<?php

declare(strict_types=1);

namespace Tiden\Tests;

use PHPUnit\Framework\TestCase;
use Tiden\EventNormalizer;
use Tiden\Options;

final class EventNormalizerTest extends TestCase
{
    private function normalizer(): EventNormalizer
    {
        return new EventNormalizer(new Options(
            dsn: 'http://k@localhost/p',
            release: 'app@1.2.3',
            environment: 'staging',
        ));
    }

    public function test_from_exception_builds_canonical_shape(): void
    {
        $event = $this->normalizer()->fromException(new \RuntimeException('boom'));

        $this->assertSame('php', $event['platform']);
        $this->assertSame('error', $event['level']);
        $this->assertSame('app@1.2.3', $event['release']);
        $this->assertSame('staging', $event['environment']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $event['event_id']);

        $value = $event['exception']['values'][0];
        $this->assertSame('RuntimeException', $value['type']);
        $this->assertSame('boom', $value['value']);
        $this->assertNotEmpty($value['stacktrace']['frames']);
    }

    public function test_chained_exceptions_order_root_cause_first(): void
    {
        $root = new \RuntimeException('root cause');
        $wrapper = new \LogicException('wrapper', 0, $root);

        $values = $this->normalizer()->fromException($wrapper)['exception']['values'];

        $this->assertCount(2, $values);
        $this->assertSame('RuntimeException', $values[0]['type']);
        $this->assertSame('root cause', $values[0]['value']);
        $this->assertSame('LogicException', $values[1]['type']);
    }

    public function test_from_message(): void
    {
        $event = $this->normalizer()->fromMessage('hello', 'warning');

        $this->assertSame('hello', $event['message']);
        $this->assertSame('warning', $event['level']);
        $this->assertArrayNotHasKey('exception', $event);
    }
}
