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

    /** Tag listing what the size cap removed or cut: "breadcrumbs", then ",extra", then ",exception". */
    public const TRUNCATED_TAG = 'tiden.truncated';

    /** Each `extra` value is cut to this many bytes when the envelope is over the cap. */
    public const EXTRA_VALUE_LIMIT = 1024;

    /** Exception values and the message are cut to this many bytes when still over the cap. */
    public const EXCEPTION_VALUE_LIMIT = 8192;

    private readonly Scrubber $scrubber;

    private readonly EventNormalizer $normalizer;

    /**
     * Throwables this client already sent, mapped to their event id. Weak keys,
     * so a memoised exception is still garbage-collected.
     *
     * @var \WeakMap<\Throwable,string>
     */
    private \WeakMap $sent;

    public function __construct(
        private readonly Options $options,
        private readonly TransportInterface $transport,
    ) {
        $this->scrubber = new Scrubber;
        $this->normalizer = new EventNormalizer($options);
        $this->sent = new \WeakMap;
    }

    public static function create(Options $options, ?TransportInterface $transport = null): self
    {
        return new self($options, $transport ?? new CurlTransport(
            $options->dsn->ingestUrl,
            $options->httpTimeout,
            $options->onTransportFailure(),
            $options->retryAfterDefault,
        ));
    }

    /**
     * The same Throwable object is sent once: a repeat capture (e.g. a framework
     * report() plus a log listener seeing the same exception) returns the first
     * event id without sending. A capture that was dropped (before_send returned
     * null) is not remembered, so a later capture may still send it.
     */
    public function captureException(\Throwable $e, ?Scope $scope = null): ?string
    {
        if (isset($this->sent[$e])) {
            return $this->sent[$e];
        }

        try {
            $event = $this->normalizer->fromException($e);
        } catch (\Throwable) {
            return null;
        }

        $id = $this->capture($event, $scope);
        if ($id !== null) {
            $this->sent[$e] = $id;
        }

        return $id;
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

            $envelope = $this->fitToCap($event);
            if ($envelope === null) {
                return null; // dropped: still over max_envelope_bytes after every shrink step
            }

            $this->transport->send($envelope);

            return is_string($event['event_id'] ?? null) ? $event['event_id'] : null;
        } catch (\Throwable) {
            // Monitoring must never crash the app it monitors.
            return null;
        }
    }

    /**
     * Serializes $event and, while the envelope is over max_envelope_bytes, shrinks it
     * in order: drop breadcrumbs, cut `extra` values, cut exception values and message.
     * Each step is tagged in `tiden.truncated`. Returns null (and reports
     * envelope_too_large) when the event is still over the cap.
     *
     * @param  array<string,mixed>  $event
     */
    private function fitToCap(array $event): ?string
    {
        $cap = $this->options->maxEnvelopeBytes;
        $envelope = Envelope::serialize($event);
        if (strlen($envelope) <= $cap) {
            return $envelope;
        }

        $steps = [
            'breadcrumbs' => static function (array $e): array {
                unset($e['breadcrumbs']);

                return $e;
            },
            'extra' => static function (array $e): array {
                if (isset($e['extra']) && is_array($e['extra'])) {
                    foreach ($e['extra'] as $k => $v) {
                        $e['extra'][$k] = self::cutExtra($v);
                    }
                }

                return $e;
            },
            'exception' => static function (array $e): array {
                if (isset($e['exception']['values']) && is_array($e['exception']['values'])) {
                    foreach ($e['exception']['values'] as $i => $value) {
                        if (is_array($value) && is_string($value['value'] ?? null)) {
                            $e['exception']['values'][$i]['value'] = Truncate::utf8($value['value'], self::EXCEPTION_VALUE_LIMIT);
                        }
                    }
                }
                if (is_string($e['message'] ?? null)) {
                    $e['message'] = Truncate::utf8($e['message'], self::EXCEPTION_VALUE_LIMIT);
                }

                return $e;
            },
        ];

        $applied = [];
        foreach ($steps as $name => $step) {
            $event = $step($event);
            $applied[] = $name;
            if (! isset($event['tags']) || ! is_array($event['tags'])) {
                $event['tags'] = [];
            }
            $event['tags'][self::TRUNCATED_TAG] = implode(',', $applied);

            $envelope = Envelope::serialize($event);
            if (strlen($envelope) <= $cap) {
                return $envelope;
            }
        }

        $this->reportTooLarge(strlen($envelope));

        return null;
    }

    /** Strings are cut to EXTRA_VALUE_LIMIT; other values keep their type unless their JSON is over it. */
    private static function cutExtra(mixed $value): mixed
    {
        if (is_string($value)) {
            return Truncate::utf8($value, self::EXTRA_VALUE_LIMIT);
        }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return '';
        }

        return strlen($json) <= self::EXTRA_VALUE_LIMIT ? $value : Truncate::utf8($json, self::EXTRA_VALUE_LIMIT);
    }

    private function reportTooLarge(int $bytes): void
    {
        $callback = $this->options->onTransportFailure();
        if ($callback === null) {
            return;
        }
        try {
            $callback(['reason' => 'envelope_too_large', 'status' => null, 'bytes' => $bytes, 'curl_errno' => null]);
        } catch (\Throwable) {
            // A failing callback must not turn a dropped event into a crash.
        }
    }
}
