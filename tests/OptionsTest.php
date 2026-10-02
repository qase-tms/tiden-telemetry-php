<?php

declare(strict_types=1);

namespace Tiden\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tiden\Options;

final class OptionsTest extends TestCase
{
    public function test_transport_defaults(): void
    {
        $o = new Options(dsn: 'http://k@localhost/p');

        $this->assertSame(2.0, $o->httpTimeout);
        $this->assertSame(921600, $o->maxEnvelopeBytes);
        $this->assertNull($o->onTransportFailure());
        $this->assertSame(60.0, $o->retryAfterDefault);
    }

    public function test_from_array_defaults_match_constructor(): void
    {
        $o = Options::fromArray(['dsn' => 'http://k@localhost/p']);

        $this->assertSame(2.0, $o->httpTimeout);
        $this->assertSame(921600, $o->maxEnvelopeBytes);
        $this->assertNull($o->onTransportFailure());
        $this->assertSame(60.0, $o->retryAfterDefault);
    }

    public function test_from_array_parses_transport_keys(): void
    {
        $seen = [];
        $callback = static function (array $failure) use (&$seen): void {
            $seen[] = $failure;
        };

        $o = Options::fromArray([
            'dsn' => 'http://k@localhost/p',
            'http_timeout' => '5',
            'max_envelope_bytes' => '1024',
            'on_transport_failure' => $callback,
            'retry_after_default' => 10,
        ]);

        $this->assertSame(5.0, $o->httpTimeout);
        $this->assertSame(1024, $o->maxEnvelopeBytes);
        $this->assertSame(10.0, $o->retryAfterDefault);
        $cb = $o->onTransportFailure();
        $this->assertNotNull($cb);
        $cb(['reason' => 'suppressed', 'status' => null, 'bytes' => 1, 'curl_errno' => null]);
        $this->assertCount(1, $seen);
    }

    public function test_from_array_ignores_non_callable_failure_callback(): void
    {
        $o = Options::fromArray([
            'dsn' => 'http://k@localhost/p',
            'on_transport_failure' => 'not_a_function_that_exists',
        ]);

        $this->assertNull($o->onTransportFailure());
    }

    public function test_existing_positional_arguments_still_work(): void
    {
        $before = static fn (array $e): array => $e;
        $o = new Options('http://k@localhost/p', 'r1', 'prod', true, 10, $before);

        $this->assertSame('r1', $o->release);
        $this->assertSame(10, $o->maxBreadcrumbs);
        $this->assertSame($before, $o->beforeSend());
        $this->assertSame(2.0, $o->httpTimeout);
    }

    /** @return iterable<string,array{mixed}> */
    public static function unsetValues(): iterable
    {
        yield 'null' => [null];
        yield 'empty string (empty env var)' => [''];
        yield 'zero' => [0];
        yield 'zero string' => ['0'];
        yield 'negative' => [-1];
        yield 'non-scalar' => [['x']];
    }

    #[DataProvider('unsetValues')]
    public function test_from_array_falls_back_to_defaults_for_non_positive_values(mixed $value): void
    {
        $o = Options::fromArray([
            'dsn' => 'http://k@localhost/p',
            'http_timeout' => $value,
            'max_envelope_bytes' => $value,
            'retry_after_default' => $value,
        ]);

        $this->assertSame(2.0, $o->httpTimeout);
        $this->assertSame(921600, $o->maxEnvelopeBytes);
        $this->assertSame(60.0, $o->retryAfterDefault);
    }

    public function test_from_array_accepts_sub_second_timeout(): void
    {
        $o = Options::fromArray(['dsn' => 'http://k@localhost/p', 'http_timeout' => '0.5']);

        $this->assertSame(0.5, $o->httpTimeout);
    }
}
