<?php

declare(strict_types=1);

namespace Tiden;

/**
 * SDK configuration. Construct directly or via Options::fromArray() (the shape
 * the framework bridges pass through from config files).
 *
 * @phpstan-type TransportFailure array{reason: 'curl_error'|'http_error'|'rate_limited'|'suppressed'|'envelope_too_large', status: int|null, bytes: int, curl_errno: int|null}
 */
final class Options
{
    public readonly Dsn $dsn;

    /** @var (callable(array<string,mixed>): (array<string,mixed>|null))|null */
    private $beforeSend;

    /** @var (callable(TransportFailure): mixed)|null */
    private $onTransportFailure;

    /**
     * @param  (callable(array<string,mixed>): (array<string,mixed>|null))|null  $beforeSend  Last-chance hook to
     *                                                                                        mutate or drop an event; return null to drop.
     * @param  float  $httpTimeout  Total send timeout in seconds (connect timeout is min(this, 1 s)).
     * @param  int  $maxEnvelopeBytes  Envelope size cap; larger events are shrunk, then dropped.
     * @param  (callable(TransportFailure): mixed)|null  $onTransportFailure  Called when an event is not delivered.
     *                                                                        Never receives the URL or the payload.
     * @param  float  $retryAfterDefault  Seconds to pause after a 429 without a Retry-After header.
     */
    public function __construct(
        string $dsn,
        public readonly ?string $release = null,
        public readonly ?string $environment = null,
        public readonly bool $sendDefaultPii = false,
        public readonly int $maxBreadcrumbs = 100,
        ?callable $beforeSend = null,
        public readonly float $httpTimeout = 2.0,
        public readonly int $maxEnvelopeBytes = 921600,
        ?callable $onTransportFailure = null,
        public readonly float $retryAfterDefault = 60.0,
    ) {
        $this->dsn = Dsn::parse($dsn);
        $this->beforeSend = $beforeSend;
        $this->onTransportFailure = $onTransportFailure;
    }

    /** @param array<string,mixed> $o */
    public static function fromArray(array $o): self
    {
        $dsn = $o['dsn'] ?? null;
        if (! is_string($dsn) || $dsn === '') {
            throw new \InvalidArgumentException('Tiden: "dsn" is required');
        }

        return new self(
            dsn: $dsn,
            release: isset($o['release']) ? (string) $o['release'] : null,
            environment: isset($o['environment']) ? (string) $o['environment'] : null,
            sendDefaultPii: (bool) ($o['send_default_pii'] ?? false),
            maxBreadcrumbs: (int) ($o['max_breadcrumbs'] ?? 100),
            beforeSend: isset($o['before_send']) && is_callable($o['before_send']) ? $o['before_send'] : null,
            httpTimeout: self::positiveFloat($o['http_timeout'] ?? null, 2.0),
            maxEnvelopeBytes: self::positiveInt($o['max_envelope_bytes'] ?? null, 921600),
            onTransportFailure: isset($o['on_transport_failure']) && is_callable($o['on_transport_failure'])
                ? $o['on_transport_failure']
                : null,
            retryAfterDefault: self::positiveFloat($o['retry_after_default'] ?? null, 60.0),
        );
    }

    /** null, '' and values <= 0 (an empty env var casts to 0) mean "not set". */
    private static function positiveFloat(mixed $value, float $default): float
    {
        if ($value === null || $value === '' || ! is_scalar($value)) {
            return $default;
        }
        $f = (float) $value;

        return $f > 0 ? $f : $default;
    }

    /** null, '' and values <= 0 (an empty env var casts to 0) mean "not set". */
    private static function positiveInt(mixed $value, int $default): int
    {
        if ($value === null || $value === '' || ! is_scalar($value)) {
            return $default;
        }
        $i = (int) $value;

        return $i > 0 ? $i : $default;
    }

    /** @return (callable(array<string,mixed>): (array<string,mixed>|null))|null */
    public function beforeSend(): ?callable
    {
        return $this->beforeSend;
    }

    /** @return (callable(TransportFailure): mixed)|null */
    public function onTransportFailure(): ?callable
    {
        return $this->onTransportFailure;
    }
}
