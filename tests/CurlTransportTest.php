<?php

declare(strict_types=1);

namespace Tiden\Tests;

use PHPUnit\Framework\TestCase;
use Tiden\Transport\CurlTransport;

/**
 * Exercises CurlTransport against a real `php -S` server (tests/fixtures/router.php).
 */
final class CurlTransportTest extends TestCase
{
    private const KEY = 'secretpublickey';

    /** @var resource|null */
    private static $server = null;

    private static int $port = 0;

    private static string $log = '';

    /** @var list<array<string,mixed>> */
    private array $failures = [];

    public static function setUpBeforeClass(): void
    {
        self::$port = self::freePort();
        $log = tempnam(sys_get_temp_dir(), 'tiden-router-');
        if ($log === false) {
            self::fail('cannot create the router log file');
        }
        self::$log = $log;

        $env = getenv();
        $env['TIDEN_ROUTER_LOG'] = self::$log;
        unset($env['PHP_CLI_SERVER_WORKERS']);

        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.self::$port, __DIR__.'/fixtures/router.php'],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            null,
            $env,
        );
        if (! is_resource($proc)) {
            self::fail('cannot start php -S');
        }
        self::$server = $proc;

        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $sock = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);
            if (is_resource($sock)) {
                fclose($sock);

                return;
            }
            usleep(50_000);
        }
        self::fail('php -S did not start on port '.self::$port);
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        self::$server = null;
        if (self::$log !== '' && is_file(self::$log)) {
            unlink(self::$log);
        }
    }

    protected function setUp(): void
    {
        file_put_contents(self::$log, '');
        $this->failures = [];
    }

    public function test_success_sends_envelope_with_headers_and_reports_nothing(): void
    {
        $envelope = $this->envelope(2048);
        $this->transport('/ok')->send($envelope);

        $this->assertSame([], $this->failures);
        $requests = $this->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame(CurlTransport::CONTENT_TYPE, $requests[0]['headers']['content-type']);
        $this->assertArrayNotHasKey('expect', $requests[0]['headers']);
        $this->assertSame($envelope, $requests[0]['body']);
    }

    public function test_server_error_reports_http_error_without_backoff(): void
    {
        $envelope = $this->envelope();
        $transport = $this->transport('/fail');

        $transport->send($envelope);
        $transport->send($envelope);

        $this->assertCount(2, $this->requests(), 'a 5xx must not start a backoff');
        $this->assertCount(2, $this->failures);
        $this->assertFailure('http_error', 500, strlen($envelope), null, $this->failures[0]);
        $this->assertFailure('http_error', 500, strlen($envelope), null, $this->failures[1]);
    }

    public function test_rate_limited_with_retry_after_suppresses_until_it_expires(): void
    {
        $envelope = $this->envelope();
        $transport = $this->transport('/limited');

        $transport->send($envelope);
        $transport->send($envelope);

        $this->assertCount(1, $this->requests());
        $this->assertFailure('rate_limited', 429, strlen($envelope), null, $this->failures[0]);
        $this->assertFailure('suppressed', null, strlen($envelope), null, $this->failures[1]);

        usleep(1_100_000); // Retry-After: 1
        $transport->send($envelope);

        $this->assertCount(2, $this->requests());
        $this->assertFailure('rate_limited', 429, strlen($envelope), null, $this->failures[2]);
    }

    public function test_rate_limited_without_retry_after_uses_the_default(): void
    {
        $envelope = $this->envelope();
        $transport = $this->transport('/limited-default', retryAfterDefault: 0.2);

        $transport->send($envelope);
        $transport->send($envelope);

        $this->assertCount(1, $this->requests());
        $this->assertSame(['rate_limited', 'suppressed'], array_column($this->failures, 'reason'));

        usleep(250_000);
        $transport->send($envelope);

        $this->assertCount(2, $this->requests());
        $this->assertSame(['rate_limited', 'suppressed', 'rate_limited'], array_column($this->failures, 'reason'));
    }

    public function test_connection_refused_reports_curl_error_and_backs_off(): void
    {
        $envelope = $this->envelope();
        $transport = new CurlTransport($this->closedPortUrl(), 2.0, $this->recorder());

        $transport->send($envelope);
        $transport->send($envelope);

        $this->assertCount(2, $this->failures);
        $this->assertFailure('curl_error', null, strlen($envelope), 7, $this->failures[0]);
        $this->assertFailure('suppressed', null, strlen($envelope), null, $this->failures[1]);
    }

    public function test_failure_backoff_expires(): void
    {
        $envelope = $this->envelope();
        $transport = new CurlTransport($this->closedPortUrl(), 2.0, $this->recorder(), failureBackoff: 0.2);

        $transport->send($envelope);
        $transport->send($envelope);
        usleep(250_000);
        $transport->send($envelope);

        $this->assertSame(['curl_error', 'suppressed', 'curl_error'], array_column($this->failures, 'reason'));
    }

    public function test_sub_second_timeout_reports_curl_error_28_and_backs_off(): void
    {
        $envelope = $this->envelope();
        $transport = $this->transport('/slow', timeout: 0.3);

        $start = microtime(true);
        $transport->send($envelope);
        $elapsed = microtime(true) - $start;
        $transport->send($envelope);

        $this->assertLessThan(0.9, $elapsed, 'the send must give up at the 0.3 s timeout');
        $this->assertCount(2, $this->failures);
        $this->assertFailure('curl_error', null, strlen($envelope), 28, $this->failures[0]);
        $this->assertFailure('suppressed', null, strlen($envelope), null, $this->failures[1]);

        usleep(900_000); // let the single-threaded server finish the slow request
    }

    public function test_throwing_callback_is_swallowed(): void
    {
        $transport = new CurlTransport(
            $this->url('/fail'),
            2.0,
            static function (array $failure): void {
                throw new \RuntimeException('callback blew up');
            },
        );

        $transport->send($this->envelope());

        $this->assertCount(1, $this->requests());
    }

    private function transport(string $path, float $timeout = 2.0, float $retryAfterDefault = 60.0): CurlTransport
    {
        return new CurlTransport($this->url($path), $timeout, $this->recorder(), $retryAfterDefault);
    }

    private function recorder(): \Closure
    {
        return function (array $failure): void {
            $this->failures[] = $failure;
        };
    }

    private function url(string $path): string
    {
        return 'http://127.0.0.1:'.self::$port.$path.'?tiden_key='.self::KEY;
    }

    private function closedPortUrl(): string
    {
        return 'http://127.0.0.1:'.self::freePort().'/api/1/envelope/?tiden_key='.self::KEY;
    }

    private function envelope(int $size = 64): string
    {
        $header = '{"event_id":"abc"}'."\n".'{"type":"event"}'."\n";

        return $header.str_repeat('x', max(0, $size - strlen($header) - 1))."\n";
    }

    /** @param array<string,mixed> $failure */
    private function assertFailure(string $reason, ?int $status, int $bytes, ?int $curlErrno, array $failure): void
    {
        $this->assertSame(
            ['reason' => $reason, 'status' => $status, 'bytes' => $bytes, 'curl_errno' => $curlErrno],
            $failure,
        );
        // Never the URL (it carries the key) and never the payload.
        $this->assertStringNotContainsString(self::KEY, (string) json_encode($failure));
        $this->assertStringNotContainsString('event_id', (string) json_encode($failure));
    }

    /** @return list<array{path: string, method: string, headers: array<string,string>, body: string}> */
    private function requests(): array
    {
        $out = [];
        foreach (file(self::$log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $out[] = json_decode($line, true);
        }

        return $out;
    }

    /** A port that was free a moment ago: bind to port 0, read the port, close. */
    private static function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            self::fail("cannot bind a free port: $errstr");
        }
        $name = (string) stream_socket_get_name($sock, false);
        fclose($sock);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
