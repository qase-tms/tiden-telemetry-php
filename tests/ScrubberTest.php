<?php

declare(strict_types=1);

namespace Tiden\Tests;

use PHPUnit\Framework\TestCase;
use Tiden\Scrubber;

final class ScrubberTest extends TestCase
{
    public function testRedactsHeadersAndSecretKeysButKeepsTheRest(): void
    {
        $event = [
            'request' => ['headers' => ['Authorization' => 'Bearer x', 'Accept' => 'application/json']],
            'extra' => ['password' => 'hunter2', 'order_id' => 42, 'nested' => ['api_key' => 'k', 'qty' => 3]],
        ];

        $out = (new Scrubber())->scrub($event);

        $this->assertSame('[Filtered]', $out['request']['headers']['Authorization']);
        $this->assertSame('application/json', $out['request']['headers']['Accept']);
        $this->assertSame('[Filtered]', $out['extra']['password']);
        $this->assertSame(42, $out['extra']['order_id']);
        $this->assertSame('[Filtered]', $out['extra']['nested']['api_key']);
        $this->assertSame(3, $out['extra']['nested']['qty']);
    }
}
