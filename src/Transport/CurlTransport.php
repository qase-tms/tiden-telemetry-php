<?php

declare(strict_types=1);

namespace Tiden\Transport;

/**
 * Synchronous curl transport. Monitoring must never crash the host app, so every
 * failure is swallowed. Honors HTTP 429 + Retry-After with a process-local gate.
 */
final class CurlTransport implements TransportInterface
{
    /** Envelope media type the ingest backend accepts. Part of the wire contract. */
    public const CONTENT_TYPE = 'application/x-tiden-envelope';

    private float $rateLimitedUntil = 0.0;

    public function __construct(
        private readonly string $url,
        private readonly float $timeout = 2.0,
    ) {
    }

    public function send(string $envelope): void
    {
        if (microtime(true) < $this->rateLimitedUntil) {
            return;
        }
        if (!function_exists('curl_init')) {
            return;
        }

        $ch = curl_init($this->url);
        if ($ch === false) {
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $envelope,
            CURLOPT_HTTPHEADER => ['Content-Type: ' . self::CONTENT_TYPE],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => (int) ceil($this->timeout),
            CURLOPT_CONNECTTIMEOUT => (int) ceil($this->timeout),
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        // curl_close() is a deprecated no-op since PHP 8.0; the handle is freed
        // when $ch goes out of scope.

        if ($status === 429 && is_string($response)) {
            $headers = substr($response, 0, $headerSize);
            $retryAfter = 60.0;
            if (preg_match('/retry-after:\s*(\d+)/i', $headers, $m) === 1) {
                $retryAfter = (float) $m[1];
            }
            $this->rateLimitedUntil = microtime(true) + $retryAfter;
        }
    }
}
