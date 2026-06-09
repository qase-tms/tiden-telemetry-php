<?php

declare(strict_types=1);

namespace Tiden\Tests;

use PHPUnit\Framework\TestCase;
use Tiden\Client;
use Tiden\Dsn;
use Tiden\Options;
use Tiden\Transport\CurlTransport;
use Tiden\Transport\NullTransport;

/**
 * Contract test: pins the exact wire output the SDK produces so it stays in sync
 * with the ingest backend. If the backend changes the ingest interface (auth
 * param, media type, envelope framing, or event field names), update the SDK +
 * these assertions together — a drift makes this test fail loudly.
 */
final class ContractTest extends TestCase
{
    public function testDsnIngestUrlAndAuthParam(): void
    {
        $dsn = Dsn::parse('http://pub@host:1140/proj-1');
        $this->assertSame('http://host:1140/api/proj-1/envelope/?tiden_key=pub', $dsn->ingestUrl);
    }

    public function testEnvelopeMediaType(): void
    {
        $this->assertSame('application/x-tiden-envelope', CurlTransport::CONTENT_TYPE);
    }

    public function testEnvelopeFramingAndEventShape(): void
    {
        $t = new NullTransport();
        $client = new Client(
            new Options(dsn: 'http://k@host/p', release: 'app@1.2.3', environment: 'production'),
            $t,
        );
        $client->captureException(new \RuntimeException('boom'));

        $envelope = $t->last();
        $this->assertIsString($envelope);
        $lines = explode("\n", rtrim($envelope, "\n"));
        $this->assertCount(3, $lines);

        $header = json_decode($lines[0], true);
        $item = json_decode($lines[1], true);
        $event = json_decode($lines[2], true);

        // (1) envelope framing
        $this->assertSame('event', $item['type']);
        $this->assertSame('application/json', $item['content_type']);
        $this->assertSame(strlen($lines[2]), $item['length']); // byte length
        $this->assertSame($event['event_id'], $header['event_id']);

        // (2) event schema the backend normalizer reads
        $this->assertSame('php', $event['platform']);
        $this->assertSame('error', $event['level']);
        $this->assertSame('app@1.2.3', $event['release']);
        $this->assertSame('production', $event['environment']);
        $ex = $event['exception']['values'][0];
        $this->assertSame('RuntimeException', $ex['type']);
        $this->assertSame('boom', $ex['value']);
        $this->assertIsArray($ex['stacktrace']['frames']);
    }
}
