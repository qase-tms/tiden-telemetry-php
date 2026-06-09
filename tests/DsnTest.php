<?php

declare(strict_types=1);

namespace Tiden\Tests;

use PHPUnit\Framework\TestCase;
use Tiden\Dsn;

final class DsnTest extends TestCase
{
    public function testParsesDsnIntoIngestUrl(): void
    {
        $dsn = Dsn::parse('http://pub@localhost:1145/6ef7807d');

        $this->assertSame('pub', $dsn->publicKey);
        $this->assertSame('6ef7807d', $dsn->projectId);
        $this->assertSame(
            'http://localhost:1145/api/6ef7807d/envelope/?tiden_key=pub',
            $dsn->ingestUrl,
        );
    }

    public function testRejectsDsnWithoutKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Dsn::parse('http://localhost/6ef7807d');
    }

    public function testRejectsDsnWithoutProject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Dsn::parse('http://pub@localhost');
    }
}
