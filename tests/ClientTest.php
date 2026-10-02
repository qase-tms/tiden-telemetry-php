<?php

declare(strict_types=1);

namespace Tiden\Tests;

use PHPUnit\Framework\TestCase;
use Tiden\Breadcrumb;
use Tiden\Client;
use Tiden\Options;
use Tiden\Scope;
use Tiden\Transport\NullTransport;

final class ClientTest extends TestCase
{
    /** @return array<string,mixed> */
    private function body(NullTransport $t): array
    {
        $envelope = $t->last();
        $this->assertIsString($envelope);
        $lines = explode("\n", $envelope);

        return json_decode($lines[2], true);
    }

    public function test_capture_exception_sends_canonical_envelope(): void
    {
        $t = new NullTransport;
        $client = new Client(new Options(dsn: 'http://k@localhost:1145/proj'), $t);

        $id = $client->captureException(new \RuntimeException('boom'));

        $this->assertNotNull($id);
        $this->assertCount(1, $t->envelopes);
        $body = $this->body($t);
        $this->assertSame('php', $body['platform']);
        $this->assertSame('RuntimeException', $body['exception']['values'][0]['type']);
        $this->assertSame($id, $body['event_id']);
    }

    public function test_before_send_can_drop_event(): void
    {
        $t = new NullTransport;
        $client = new Client(
            new Options(dsn: 'http://k@localhost/p', beforeSend: static fn (array $e): ?array => null),
            $t,
        );

        $this->assertNull($client->captureMessage('nope'));
        $this->assertCount(0, $t->envelopes);
    }

    public function test_before_send_can_mutate_event(): void
    {
        $t = new NullTransport;
        $client = new Client(
            new Options(dsn: 'http://k@localhost/p', beforeSend: static function (array $e): array {
                $e['tags']['injected'] = 'yes';

                return $e;
            }),
            $t,
        );

        $client->captureMessage('hi');

        $this->assertSame('yes', $this->body($t)['tags']['injected']);
    }

    public function test_scope_is_merged_into_event(): void
    {
        $t = new NullTransport;
        $client = new Client(new Options(dsn: 'http://k@localhost/p'), $t);

        $scope = new Scope;
        $scope->setTag('region', 'eu');
        $scope->addBreadcrumb(new Breadcrumb('did a thing'));

        $client->captureException(new \Exception('x'), $scope);

        $body = $this->body($t);
        $this->assertSame('eu', $body['tags']['region']);
        $this->assertSame('did a thing', $body['breadcrumbs']['values'][0]['message']);
    }

    public function test_pii_scrubbed_by_default_unless_enabled(): void
    {
        $t = new NullTransport;
        $client = new Client(new Options(dsn: 'http://k@localhost/p'), $t);

        $scope = new Scope;
        $scope->setExtra('password', 'hunter2');
        $client->captureMessage('x', 'info', $scope);

        $this->assertSame('[Filtered]', $this->body($t)['extra']['password']);
    }

    // Dedup (TIDEN-147): the same Throwable object is sent once.

    public function test_same_throwable_is_sent_once_and_returns_same_id(): void
    {
        $t = new NullTransport;
        $client = new Client(new Options(dsn: 'http://k@localhost/p'), $t);
        $e = new \RuntimeException('boom');

        $first = $client->captureException($e);
        $second = $client->captureException($e);

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        $this->assertCount(1, $t->envelopes);
    }

    public function test_distinct_throwables_are_both_sent(): void
    {
        $t = new NullTransport;
        $client = new Client(new Options(dsn: 'http://k@localhost/p'), $t);

        $first = $client->captureException(new \RuntimeException('boom'));
        $second = $client->captureException(new \RuntimeException('boom'));

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
        $this->assertCount(2, $t->envelopes);
    }

    public function test_throwable_dropped_by_before_send_is_not_memoised(): void
    {
        $t = new NullTransport;
        $drop = true;
        $client = new Client(
            new Options(
                dsn: 'http://k@localhost/p',
                beforeSend: static function (array $event) use (&$drop): ?array {
                    return $drop ? null : $event;
                },
            ),
            $t,
        );
        $e = new \RuntimeException('boom');

        $this->assertNull($client->captureException($e));
        $this->assertCount(0, $t->envelopes);

        $drop = false;
        $id = $client->captureException($e);

        $this->assertNotNull($id);
        $this->assertCount(1, $t->envelopes);
        $this->assertSame($id, $this->body($t)['event_id']);
    }

    public function test_messages_and_events_are_not_deduplicated(): void
    {
        $t = new NullTransport;
        $client = new Client(new Options(dsn: 'http://k@localhost/p'), $t);

        $client->captureMessage('same');
        $client->captureMessage('same');
        $event = ['event_id' => str_repeat('a', 32), 'message' => 'same'];
        $client->captureEvent($event);
        $client->captureEvent($event);

        $this->assertCount(4, $t->envelopes);
    }

    public function test_dedup_is_per_client(): void
    {
        $e = new \RuntimeException('boom');
        $t1 = new NullTransport;
        $t2 = new NullTransport;

        (new Client(new Options(dsn: 'http://k@localhost/p'), $t1))->captureException($e);
        (new Client(new Options(dsn: 'http://k@localhost/p'), $t2))->captureException($e);

        $this->assertCount(1, $t1->envelopes);
        $this->assertCount(1, $t2->envelopes);
    }
}
