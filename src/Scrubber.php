<?php

declare(strict_types=1);

namespace Tiden;

/**
 * Redacts likely-PII before send (defense-in-depth; the edge also scrubs request
 * headers server-side). Applied unless Options::$sendDefaultPii is true.
 */
final class Scrubber
{
    private const REDACTED = '[Filtered]';

    private const HEADER_DENYLIST = [
        'authorization', 'cookie', 'set-cookie', 'x-api-key', 'x-auth-token', 'proxy-authorization',
    ];

    private const KEY_DENYLIST = [
        'password', 'passwd', 'secret', 'token', 'api_key', 'apikey',
        'authorization', 'credit_card', 'card_number', 'cvv',
    ];

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    public function scrub(array $event): array
    {
        if (isset($event['request']['headers']) && is_array($event['request']['headers'])) {
            foreach ($event['request']['headers'] as $name => $_) {
                if (in_array(strtolower((string) $name), self::HEADER_DENYLIST, true)) {
                    $event['request']['headers'][$name] = self::REDACTED;
                }
            }
        }

        foreach (['extra', 'user', 'contexts'] as $section) {
            if (isset($event[$section]) && is_array($event[$section])) {
                $event[$section] = $this->scrubKeys($event[$section]);
            }
        }

        return $event;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function scrubKeys(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::KEY_DENYLIST, true)) {
                $data[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $data[$key] = $this->scrubKeys($value);
            }
        }

        return $data;
    }
}
