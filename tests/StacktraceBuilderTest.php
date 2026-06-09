<?php

declare(strict_types=1);

namespace Tiden\Tests;

use PHPUnit\Framework\TestCase;
use Tiden\StacktraceBuilder;

final class StacktraceBuilderTest extends TestCase
{
    public function testInnermostFrameIsTheThrowLocation(): void
    {
        $line = __LINE__ + 1;
        $e = new \RuntimeException('boom');

        $frames = StacktraceBuilder::fromThrowable($e);

        $this->assertNotEmpty($frames);
        $last = $frames[array_key_last($frames)];
        $this->assertSame(__FILE__, $last['abs_path']);
        $this->assertSame($line, $last['lineno']);
        $this->assertTrue($last['in_app']);
    }
}
