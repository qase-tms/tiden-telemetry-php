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

    public function test_memoised_throwable_can_be_garbage_collected(): void
    {
        $client = new Client(new Options(dsn: 'http://k@localhost/p'), new NullTransport);
        $e = new \RuntimeException('boom');
        $this->assertNotNull($client->captureException($e));
        $ref = \WeakReference::create($e);

        unset($e);
        gc_collect_cycles();

        $this->assertNull($ref->get());
    }

    // --- Envelope size cap (TIDEN-148) ---

    /** @var list<array<string,mixed>> */
    private array $transportFailures = [];

    private function cappedClient(NullTransport $t, int $cap, ?callable $beforeSend = null): Client
    {
        $this->transportFailures = [];

        return new Client(new Options(
            dsn: 'http://secretkey@localhost/p',
            beforeSend: $beforeSend,
            maxEnvelopeBytes: $cap,
            onTransportFailure: function (array $failure): void {
                $this->transportFailures[] = $failure;
            },
        ), $t);
    }

    private function scopeWithBreadcrumbs(int $count, int $size): Scope
    {
        $scope = new Scope;
        for ($i = 0; $i < $count; $i++) {
            $scope->addBreadcrumb(new Breadcrumb(str_repeat('b', $size)));
        }

        return $scope;
    }

    public function test_event_under_cap_is_sent_untouched(): void
    {
        $t = new NullTransport;
        $client = $this->cappedClient($t, 921600);

        $client->captureMessage('hi', 'info', $this->scopeWithBreadcrumbs(3, 10));

        $body = $this->body($t);
        $this->assertCount(3, $body['breadcrumbs']['values']);
        $this->assertArrayNotHasKey('tags', $body);
        $this->assertSame([], $this->transportFailures);
    }

    public function test_drops_breadcrumbs_first(): void
    {
        $t = new NullTransport;
        $client = $this->cappedClient($t, 2000);
        $scope = $this->scopeWithBreadcrumbs(30, 100);
        $scope->setExtra('note', 'keep me');

        $id = $client->captureMessage('m', 'info', $scope);

        $this->assertNotNull($id);
        $this->assertLessThanOrEqual(2000, strlen((string) $t->last()));
        $body = $this->body($t);
        $this->assertArrayNotHasKey('breadcrumbs', $body);
        $this->assertSame('breadcrumbs', $body['tags'][Client::TRUNCATED_TAG]);
        $this->assertSame('keep me', $body['extra']['note']);
        $this->assertSame('m', $body['message']);
    }

    public function test_extra_truncated(): void
    {
        $t = new NullTransport;
        $client = $this->cappedClient($t, 4000);
        $scope = $this->scopeWithBreadcrumbs(5, 100);
        $scope->setExtra('sql', str_repeat('x', 3000));
        $scope->setExtra('rows', ['nested' => str_repeat('y', 3000)]);
        $scope->setExtra('count', 42);

        $client->captureEvent([
            'event_id' => 'e1',
            'exception' => ['values' => [['type' => 'RuntimeException', 'value' => 'short']]],
        ], $scope);

        $this->assertLessThanOrEqual(4000, strlen((string) $t->last()));
        $body = $this->body($t);
        $this->assertSame('breadcrumbs,extra', $body['tags'][Client::TRUNCATED_TAG]);
        $this->assertArrayNotHasKey('breadcrumbs', $body);
        $this->assertSame(Client::EXTRA_VALUE_LIMIT, strlen($body['extra']['sql']));
        $this->assertIsString($body['extra']['rows'], 'an oversized non-string extra is JSON-encoded, then cut');
        $this->assertSame(Client::EXTRA_VALUE_LIMIT, strlen($body['extra']['rows']));
        $this->assertStringStartsWith('{"nested":"yyy', $body['extra']['rows']);
        $this->assertSame(42, $body['extra']['count'], 'a small non-string extra keeps its type');
        $this->assertSame('short', $body['exception']['values'][0]['value']);
    }

    public function test_exception_message_truncated(): void
    {
        $t = new NullTransport;
        $client = $this->cappedClient($t, 20000);
        // 1 ASCII byte then 2-byte chars: the 8192-byte cut falls inside a character.
        $value = 'a'.str_repeat('é', 10000);

        $client->captureEvent([
            'event_id' => 'e2',
            'message' => str_repeat('m', 10000),
            'extra' => ['k' => 'v'],
            'exception' => ['values' => [
                ['type' => 'PDOException', 'value' => 'root'],
                ['type' => 'Illuminate\Database\QueryException', 'value' => $value],
            ]],
        ]);

        $this->assertLessThanOrEqual(20000, strlen((string) $t->last()));
        $body = $this->body($t);
        $this->assertSame('breadcrumbs,extra,exception', $body['tags'][Client::TRUNCATED_TAG]);
        $cut = $body['exception']['values'][1]['value'];
        $this->assertLessThanOrEqual(Client::EXCEPTION_VALUE_LIMIT, strlen($cut));
        $this->assertSame(Client::EXCEPTION_VALUE_LIMIT - 1, strlen($cut), 'the partial character is dropped');
        $this->assertSame(1, preg_match('//u', $cut), 'the cut value is valid UTF-8');
        $this->assertStringStartsWith($cut, $value);
        $this->assertSame('root', $body['exception']['values'][0]['value']);
        $this->assertSame(Client::EXCEPTION_VALUE_LIMIT, strlen($body['message']));
        $this->assertSame('v', $body['extra']['k']);
        $this->assertSame([], $this->transportFailures);
    }

    public function test_unshrinkable_event_dropped_and_reported(): void
    {
        $t = new NullTransport;
        $client = $this->cappedClient($t, 1000);

        $id = $client->captureEvent([
            'event_id' => 'e3',
            // Exception types and frames are never cut, so this cannot fit.
            'exception' => ['values' => [['type' => str_repeat('T', 5000), 'value' => 'v']]],
        ]);

        $this->assertNull($id);
        $this->assertSame([], $t->envelopes);
        $this->assertCount(1, $this->transportFailures);
        $failure = $this->transportFailures[0];
        $this->assertSame(['reason', 'status', 'bytes', 'curl_errno'], array_keys($failure));
        $this->assertSame('envelope_too_large', $failure['reason']);
        $this->assertNull($failure['status']);
        $this->assertNull($failure['curl_errno']);
        $this->assertGreaterThan(1000, $failure['bytes']);
        $this->assertStringNotContainsString('secretkey', (string) json_encode($failure));
        $this->assertStringNotContainsString('TTTT', (string) json_encode($failure));
    }

    public function test_unshrinkable_event_with_throwing_callback_returns_null(): void
    {
        $t = new NullTransport;
        $client = new Client(new Options(
            dsn: 'http://k@localhost/p',
            maxEnvelopeBytes: 100,
            onTransportFailure: static function (): void {
                throw new \RuntimeException('callback blew up');
            },
        ), $t);

        $this->assertNull($client->captureMessage(str_repeat('m', 500)));
        $this->assertSame([], $t->envelopes);
    }

    public function test_size_cap_runs_after_before_send(): void
    {
        $t = new NullTransport;
        $seenBreadcrumbs = null;
        $client = $this->cappedClient($t, 3000, static function (array $e) use (&$seenBreadcrumbs): array {
            $seenBreadcrumbs = count($e['breadcrumbs']['values'] ?? []);
            $e['extra']['added_by_before_send'] = str_repeat('z', 5000);

            return $e;
        });

        $client->captureMessage('m', 'info', $this->scopeWithBreadcrumbs(5, 10));

        $this->assertSame(5, $seenBreadcrumbs, 'before_send sees the event before any truncation');
        $body = $this->body($t);
        $this->assertSame('breadcrumbs,extra', $body['tags'][Client::TRUNCATED_TAG]);
        $this->assertSame(Client::EXTRA_VALUE_LIMIT, strlen($body['extra']['added_by_before_send']));
    }
}
