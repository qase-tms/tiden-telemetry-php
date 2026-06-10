<?php

declare(strict_types=1);

namespace Tiden\Tests;

use PHPUnit\Framework\TestCase;
use Tiden\Envelope;

final class EnvelopeTest extends TestCase
{
    public function test_serializes_three_line_envelope_with_byte_length(): void
    {
        $event = ['event_id' => 'abc123', 'platform' => 'php', 'message' => 'héllo'];

        $envelope = Envelope::serialize($event);
        $lines = explode("\n", $envelope);

        $header = json_decode($lines[0], true);
        $item = json_decode($lines[1], true);
        $body = $lines[2];

        $this->assertSame('abc123', $header['event_id']);
        $this->assertArrayHasKey('sent_at', $header);
        $this->assertSame('event', $item['type']);
        $this->assertSame('application/json', $item['content_type']);
        // length is the BYTE length the edge uses for framing (UTF-8 multibyte).
        $this->assertSame(strlen($body), $item['length']);
        $this->assertStringEndsWith("\n", $envelope);

        $decoded = json_decode($body, true);
        $this->assertSame('php', $decoded['platform']);
        $this->assertSame('héllo', $decoded['message']);
    }
}
