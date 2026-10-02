<?php

declare(strict_types=1);

namespace Tiden\Transport;

/**
 * Synchronous curl transport. Monitoring must never crash the host app, so every
 * failure is swallowed and, when a callback is set, reported through it.
 *
 * Bounded cost per send: a total timeout (connect timeout min(timeout, 1 s)),
 * a process-local gate after HTTP 429 (Retry-After, or $retryAfterDefault), and
 * a backoff after a curl error (connect refused, DNS, timeout) so a dead ingest
 * costs one timeout per worker, not one per capture.
 *
 * @phpstan-import-type TransportFailure from \Tiden\Options
 */
final class CurlTransport implements TransportInterface
{
    /** Envelope media type the ingest backend accepts. Part of the wire contract. */
    public const CONTENT_TYPE = 'application/x-tiden-envelope';

    /** Sends are skipped (reason "suppressed") until this microtime. */
    private float $suppressedUntil = 0.0;

    private readonly ?\Closure $onFailure;

    /**
     * @param  string  $url  Ingest URL. It carries the public key, so it is never passed to the callback.
     * @param  float  $timeout  Total timeout in seconds.
     * @param  (callable(TransportFailure): mixed)|null  $onFailure  Called on every undelivered envelope;
     *                                                               exceptions it throws are swallowed.
     * @param  float  $retryAfterDefault  Seconds to pause after a 429 without a Retry-After header.
     * @param  float  $failureBackoff  Seconds to pause after a curl error (connect error, timeout, ...).
     */
    public function __construct(
        private readonly string $url,
        private readonly float $timeout = 2.0,
        ?callable $onFailure = null,
        private readonly float $retryAfterDefault = 60.0,
        private readonly float $failureBackoff = 30.0,
    ) {
        $this->onFailure = $onFailure === null ? null : \Closure::fromCallable($onFailure);
    }

    public function send(string $envelope): void
    {
        try {
            $this->doSend($envelope);
        } catch (\Throwable) {
            // Monitoring must never crash the app it monitors.
        }
    }

    private function doSend(string $envelope): void
    {
        $bytes = strlen($envelope);

        if (microtime(true) < $this->suppressedUntil) {
            $this->fail('suppressed', null, $bytes, null);

            return;
        }
        if (! function_exists('curl_init')) {
            return;
        }

        $ch = curl_init($this->url);
        if ($ch === false) {
            return;
        }

        $retryAfter = null;
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $envelope,
            // An empty "Expect:" stops curl from waiting for "100 Continue" on large bodies.
            CURLOPT_HTTPHEADER => ['Content-Type: '.self::CONTENT_TYPE, 'Expect:'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter): int {
                if (preg_match('/^retry-after:\s*(\d+)\s*$/i', $line, $m) === 1) {
                    $retryAfter = (float) $m[1];
                }

                return strlen($line);
            },
            CURLOPT_TIMEOUT_MS => max(1, (int) round($this->timeout * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round(min($this->timeout, 1.0) * 1000)),
            // Sub-second timeouts must not rely on SIGALRM (and must not interrupt the host app).
            CURLOPT_NOSIGNAL => true,
        ]);

        curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // curl_close() is a deprecated no-op since PHP 8.0; the handle is freed
        // when $ch goes out of scope.

        if ($errno !== 0) {
            $this->suppressedUntil = microtime(true) + $this->failureBackoff;
            $this->fail('curl_error', null, $bytes, $errno);

            return;
        }
        if ($status === 429) {
            $this->suppressedUntil = microtime(true) + ($retryAfter ?? $this->retryAfterDefault);
            $this->fail('rate_limited', 429, $bytes, null);

            return;
        }
        if ($status < 200 || $status >= 300) {
            $this->fail('http_error', $status, $bytes, null);
        }
    }

    /**
     * @param  'curl_error'|'http_error'|'rate_limited'|'suppressed'  $reason
     */
    private function fail(string $reason, ?int $status, int $bytes, ?int $curlErrno): void
    {
        if ($this->onFailure === null) {
            return;
        }
        try {
            ($this->onFailure)([
                'reason' => $reason,
                'status' => $status,
                'bytes' => $bytes,
                'curl_errno' => $curlErrno,
            ]);
        } catch (\Throwable) {
            // A failing callback must not turn a lost event into a crash.
        }
    }
}
