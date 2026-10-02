<?php

declare(strict_types=1);

namespace Tiden\Tests;

use PHPUnit\Framework\TestCase;
use Tiden\Breadcrumb;
use Tiden\Client;
use Tiden\Options;
use Tiden\Scope;
use Tiden\Sdk;
use Tiden\Transport\NullTransport;

final class SdkTest extends TestCase
{
    private const DSN = 'http://k@localhost/p';

    protected function tearDown(): void
    {
        Sdk::close();
    }

    /** @return array<string,mixed> */
    private function body(NullTransport $t): array
    {
        $envelope = $t->last();
        $this->assertIsString($envelope);
        $lines = explode("\n", $envelope);

        return json_decode($lines[2], true);
    }

    private function bindNull(): NullTransport
    {
        $t = new NullTransport;
        Sdk::bind(new Client(new Options(dsn: self::DSN), $t));

        return $t;
    }

    public function test_push_pop_isolates_tags_and_breadcrumbs(): void
    {
        $t = $this->bindNull();
        Sdk::configureScope(static fn (Scope $s) => $s->setTag('global', 'yes'));
        Sdk::addBreadcrumb(new Breadcrumb('before'));

        Sdk::pushScope();
        Sdk::configureScope(static fn (Scope $s) => $s->setTag('job', 'send-mail'));
        Sdk::addBreadcrumb(new Breadcrumb('inside'));
        Sdk::captureMessage('in job');

        $inner = $this->body($t);
        $this->assertSame(['global' => 'yes', 'job' => 'send-mail'], $inner['tags']);
        $this->assertSame(
            ['before', 'inside'],
            array_column($inner['breadcrumbs']['values'], 'message'),
        );

        $this->assertTrue(Sdk::popScope());
        Sdk::captureMessage('after job');

        $outer = $this->body($t);
        $this->assertSame(['global' => 'yes'], $outer['tags']);
        $this->assertSame(['before'], array_column($outer['breadcrumbs']['values'], 'message'));
    }

    public function test_pop_without_push_returns_false(): void
    {
        $this->bindNull();

        $this->assertFalse(Sdk::popScope());
    }

    public function test_pop_before_init_returns_false(): void
    {
        $this->assertFalse(Sdk::popScope());
    }

    public function test_nested_push_pop_restores_each_level(): void
    {
        $t = $this->bindNull();
        Sdk::configureScope(static fn (Scope $s) => $s->setTag('level', '0'));

        Sdk::pushScope();
        Sdk::configureScope(static fn (Scope $s) => $s->setTag('level', '1'));
        Sdk::pushScope();
        Sdk::configureScope(static fn (Scope $s) => $s->setTag('level', '2'));

        $this->assertTrue(Sdk::popScope());
        Sdk::captureMessage('x');
        $this->assertSame('1', $this->body($t)['tags']['level']);

        $this->assertTrue(Sdk::popScope());
        Sdk::captureMessage('x');
        $this->assertSame('0', $this->body($t)['tags']['level']);

        $this->assertFalse(Sdk::popScope());
    }

    public function test_bind_resets_stack(): void
    {
        $this->bindNull();
        Sdk::pushScope();
        Sdk::pushScope();

        $this->bindNull();

        $this->assertFalse(Sdk::popScope());
    }

    public function test_init_resets_stack(): void
    {
        $this->bindNull();
        Sdk::pushScope();

        Sdk::init(['dsn' => self::DSN], captureGlobals: false, transport: new NullTransport);

        $this->assertFalse(Sdk::popScope());
    }

    public function test_close_resets_stack(): void
    {
        $this->bindNull();
        Sdk::pushScope();

        Sdk::close();
        $this->bindNull();

        $this->assertFalse(Sdk::popScope());
    }

    public function test_push_scope_before_init_is_noop(): void
    {
        Sdk::pushScope();
        $this->assertNull(Sdk::getClient());

        $this->bindNull();

        $this->assertFalse(Sdk::popScope());
    }

    public function test_init_uses_injected_transport(): void
    {
        $t = new NullTransport;
        Sdk::init(['dsn' => self::DSN], captureGlobals: false, transport: $t);

        $id = Sdk::captureException(new \RuntimeException('boom'));

        $this->assertNotNull($id);
        $this->assertCount(1, $t->envelopes);
        $this->assertSame($id, $this->body($t)['event_id']);
    }

    public function test_init_resets_dedup(): void
    {
        $e = new \RuntimeException('boom');
        $first = new NullTransport;
        Sdk::init(['dsn' => self::DSN], captureGlobals: false, transport: $first);
        Sdk::captureException($e);

        $second = new NullTransport;
        Sdk::init(['dsn' => self::DSN], captureGlobals: false, transport: $second);
        Sdk::captureException($e);

        $this->assertCount(1, $first->envelopes);
        $this->assertCount(1, $second->envelopes);
    }

    public function test_capture_event_goes_through_scope(): void
    {
        $t = $this->bindNull();
        Sdk::configureScope(static fn (Scope $s) => $s->setTag('tenant', 'acme'));

        $id = Sdk::captureEvent([
            'event_id' => str_repeat('b', 32),
            'message' => 'custom event',
            'tags' => ['source' => 'manual'],
        ]);

        $this->assertSame(str_repeat('b', 32), $id);
        $body = $this->body($t);
        $this->assertSame(['source' => 'manual', 'tenant' => 'acme'], $body['tags']);
        $this->assertSame('custom event', $body['message']);
    }

    public function test_capture_event_before_init_returns_null(): void
    {
        $this->assertNull(Sdk::captureEvent(['message' => 'x']));
    }
}
