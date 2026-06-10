<?php

declare(strict_types=1);

namespace Tiden;

/**
 * Serializes the envelope the Tiden edge parses:
 *   {envelope header}\n{item header (with byte length)}\n{event body}\n
 */
final class Envelope
{
    /** @param array<string,mixed> $event */
    public static function serialize(array $event): string
    {
        $body = json_encode(
            $event,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if ($body === false) {
            $body = '{}';
        }

        $eventId = is_string($event['event_id'] ?? null) ? $event['event_id'] : null;
        $header = json_encode([
            'event_id' => $eventId,
            'sent_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $item = json_encode([
            'type' => 'event',
            'length' => strlen($body), // byte length used by the edge for framing
            'content_type' => 'application/json',
        ]);

        return $header."\n".$item."\n".$body."\n";
    }
}
