<?php

declare(strict_types=1);

namespace Tiden;

/**
 * Parses a DSN of the form `http://<publicKey>@host[:port]/<projectId>` into the
 * ingest URL the Tiden edge expects:
 *   /api/<projectId>/envelope/?tiden_key=<publicKey>
 * (wire-compatible with the JS SDK — the edge reads the `tiden_key` query param).
 */
final class Dsn
{
    private function __construct(
        public readonly string $ingestUrl,
        public readonly string $publicKey,
        public readonly string $projectId,
    ) {}

    public static function parse(string $dsn): self
    {
        $u = parse_url($dsn);
        if (
            $u === false
            || empty($u['scheme'])
            || empty($u['host'])
            || empty($u['user'])
            || empty($u['path'])
        ) {
            throw new \InvalidArgumentException(
                'Tiden: invalid DSN (expected http://<publicKey>@host/<projectId>)'
            );
        }

        $projectId = explode('/', trim($u['path'], '/'))[0];
        if ($projectId === '') {
            throw new \InvalidArgumentException('Tiden: invalid DSN (missing project id)');
        }

        $host = $u['host'];
        if (isset($u['port'])) {
            $host .= ':'.$u['port'];
        }

        $ingestUrl = sprintf(
            '%s://%s/api/%s/envelope/?tiden_key=%s',
            $u['scheme'],
            $host,
            rawurlencode($projectId),
            rawurlencode($u['user']),
        );

        return new self($ingestUrl, $u['user'], $projectId);
    }
}
