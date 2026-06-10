<?php

declare(strict_types=1);

namespace Tiden;

use Tiden\Transport\CurlTransport;
use Tiden\Transport\TransportInterface;

/**
 * Builds events, applies scope + scrubbing + beforeSend, and hands the serialized
 * envelope to the transport. Capture methods never throw.
 */
final class Client
{
    public const VERSION = '0.1.0';

    private readonly Scrubber $scrubber;

    private readonly EventNormalizer $normalizer;

    public function __construct(
        private readonly Options $options,
        private readonly TransportInterface $transport,
    ) {
        $this->scrubber = new Scrubber;
        $this->normalizer = new EventNormalizer($options);
    }

    public static function create(Options $options, ?TransportInterface $transport = null): self
    {
        return new self($options, $transport ?? new CurlTransport($options->dsn->ingestUrl));
    }

    public function captureException(\Throwable $e, ?Scope $scope = null): ?string
    {
        return $this->capture($this->normalizer->fromException($e), $scope);
    }

    public function captureMessage(string $message, string $level = 'info', ?Scope $scope = null): ?string
    {
        return $this->capture($this->normalizer->fromMessage($message, $level), $scope);
    }

    /** @param array<string,mixed> $event */
    public function captureEvent(array $event, ?Scope $scope = null): ?string
    {
        return $this->capture($event, $scope);
    }

    /**
     * @param  array<string,mixed>  $event
     * @return string|null the event_id, or null if dropped
     */
    private function capture(array $event, ?Scope $scope): ?string
    {
        try {
            if ($scope !== null) {
                $event = $scope->applyTo($event);
            }
            if (! $this->options->sendDefaultPii) {
                $event = $this->scrubber->scrub($event);
            }

            $beforeSend = $this->options->beforeSend();
            if ($beforeSend !== null) {
                $result = $beforeSend($event);
                if (! is_array($result)) {
                    return null; // dropped
                }
                $event = $result;
            }

            $this->transport->send(Envelope::serialize($event));

            return is_string($event['event_id'] ?? null) ? $event['event_id'] : null;
        } catch (\Throwable) {
            // Monitoring must never crash the app it monitors.
            return null;
        }
    }
}
