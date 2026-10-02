# tiden/telemetry-php

Framework-agnostic error-tracking PHP SDK for
[Tiden](https://github.com/qase-tms/tiden-telemetry-php). Emits the
envelope wire format to a Tiden ingest endpoint — **no third-party error-SDK
dependency**. For Laravel, use the `tiden/telemetry-laravel` bridge (built on this).

```bash
composer require tiden/telemetry-php
```

```php
use Tiden\Sdk;

Sdk::init([
    'dsn' => getenv('TIDEN_DSN'), // http://<publicKey>@<host:ingestPort>/<projectId>
    'release' => 'my-app@1.2.3',
    'environment' => 'production',
    // 'send_default_pii' => false, // default: scrub likely-PII before send
    // 'http_timeout' => 2.0,           // seconds per send; connect timeout is min(this, 1 s)
    // 'max_envelope_bytes' => 921600,  // size cap, see "Envelope size cap"
    // 'retry_after_default' => 60.0,   // pause after a 429 without Retry-After
    // 'on_transport_failure' => fn (array $failure) => error_log(json_encode($failure)),
]);

// With captureGlobals (default), uncaught exceptions + fatals are reported
// automatically. Manual capture:
try {
    risky();
} catch (\Throwable $e) {
    Sdk::captureException($e);
}

Sdk::captureMessage('checkout completed', 'info');
Sdk::addBreadcrumb(new \Tiden\Breadcrumb('cache miss', category: 'cache'));
Sdk::configureScope(fn ($s) => $s->setTag('tenant', 'acme'));
```

### Scopes, custom events and the test transport

A long-running worker can isolate one unit of work (a job, a command) with a
scope stack. `pushScope()` continues on a copy of the current scope;
`popScope()` restores the saved one and discards the tags and breadcrumbs added
since (it returns `false` when nothing was pushed). To start a new unit of work
without losing process-wide tags, user and extra, clear only the breadcrumbs:

```php
Sdk::pushScope();
try {
    Sdk::configureScope(fn ($s) => $s->setTag('job', 'send-mail'));
    runJob();
} finally {
    Sdk::popScope();
}

Sdk::configureScope(fn ($s) => $s->clearBreadcrumbs()); // tags, user, extra stay

// Send a hand-built event through the current scope. It is sent as given:
// set event_id, timestamp and platform yourself. Returns its event_id.
Sdk::captureEvent([
    'event_id' => \Tiden\EventNormalizer::uuid4(),
    'timestamp' => microtime(true),
    'platform' => 'php',
    'level' => 'warning',
    'message' => 'custom',
]);
```

The same `\Throwable` object is sent once per client: a second
`captureException()` returns the first event id without sending again.

`Sdk::init()` takes an optional transport as its third argument, so tests can
use the real init path without the network:

```php
$transport = new \Tiden\Transport\NullTransport;
Sdk::init(['dsn' => 'http://key@localhost/1'], captureGlobals: false, transport: $transport);
// $transport->envelopes holds every serialized envelope.
```

## What it does

- Parses the DSN to the edge URL `/api/<projectId>/envelope/?tiden_key=…`.
- Normalizes `\Throwable` (incl. cause chains) into `exception.values[]` with
  stack frames (`in_app` heuristic, app-relative paths).
- Serializes the envelope and POSTs it via curl (synchronous, never
  throws, bounded by `http_timeout`; honors HTTP 429 + Retry-After).
- Shrinks envelopes over `max_envelope_bytes` before sending, and drops the
  ones it cannot shrink (see below).
- Reports every event it fails to deliver to `on_transport_failure`.
- Scrubs likely-PII (auth headers, secret-ish keys) unless `send_default_pii`.
- `before_send` hook to mutate or drop events.

## Options

| Key (`Options::fromArray`) | Constructor parameter | Default | Meaning |
|---|---|---|---|
| `dsn` | `$dsn` | required | `http://<publicKey>@<host:ingestPort>/<projectId>` |
| `release`, `environment` | `$release`, `$environment` | `null` | Added to every event. |
| `send_default_pii` | `$sendDefaultPii` | `false` | Keep likely-PII instead of scrubbing it. |
| `max_breadcrumbs` | `$maxBreadcrumbs` | `100` | Breadcrumbs kept per scope. |
| `before_send` | `$beforeSend` | `null` | `fn (array $event): ?array`; return `null` to drop. |
| `http_timeout` | `$httpTimeout` | `2.0` | Total seconds per send. The connect timeout is `min(http_timeout, 1.0)`. `fromArray` uses the default for empty or non-positive values. |
| `max_envelope_bytes` | `$maxEnvelopeBytes` | `921600` | Envelope size cap (the ingest rejects bodies over 1 MiB). `fromArray` uses the default for empty or non-positive values. |
| `on_transport_failure` | `$onTransportFailure` | `null` | Called for every event that is not delivered. Ignored when not callable. |
| `retry_after_default` | `$retryAfterDefault` | `60.0` | Seconds to pause after a 429 that has no `Retry-After`. |

## Delivery and failures

Sends are synchronous, so each one is bounded: `http_timeout` in total, at most
1 s to connect, no signals (`CURLOPT_NOSIGNAL`), and no `Expect: 100-continue`
round trip. A send never throws.

The transport keeps two process-local pauses. While one is active, sends are
skipped and reported as `suppressed`:

- After a curl error (connection refused, DNS failure, timeout), sends pause
  for 30 s. A dead ingest costs one timeout per worker, not one per event.
- After HTTP 429, sends pause for `Retry-After` seconds, or for
  `retry_after_default` when the header is missing.

Other non-2xx responses are reported and do not pause sending.

`on_transport_failure` receives one array per lost event:

```php
/** @param array{reason: 'curl_error'|'http_error'|'rate_limited'|'suppressed'|'envelope_too_large', status: int|null, bytes: int, curl_errno: int|null} $failure */
```

| `reason` | When | `status` | `curl_errno` |
|---|---|---|---|
| `curl_error` | curl failed (7 = connection refused, 28 = timeout, ...); starts the 30 s pause | `null` | set, or `null` when no handle could be created |
| `http_error` | any non-2xx response other than 429 | set | `null` |
| `rate_limited` | HTTP 429; starts the Retry-After pause | `429` | `null` |
| `suppressed` | skipped because a pause is active | `null` | `null` |
| `envelope_too_large` | still over `max_envelope_bytes` after shrinking (see below) | `null` | `null` |

A `curl_error` with `curl_errno` `null` means no curl handle could be
created (ext-curl missing, or `curl_init()` failed); nothing was sent, so it
starts no pause.

`bytes` is the envelope size in bytes. The array never contains the URL (it
carries the DSN key) or the event. Exceptions thrown by the callback are
swallowed, and the callback runs inside the send, so keep it cheap.

## Envelope size cap

After `before_send`, the client serializes the event. While the envelope is
larger than `max_envelope_bytes`, it shrinks the event in this order and stops
as soon as it fits:

1. Drop the breadcrumbs.
2. Cut each `extra` value to 1 KiB (a non-string value whose JSON is longer is
   replaced by its JSON, cut).
3. Cut each exception value and the message to 8 KiB (a Laravel
   `QueryException` message embeds the full SQL).
4. Still too large: drop the event and report `envelope_too_large`.

Cuts never split a UTF-8 character. A shrunk event carries the tag
`tiden.truncated` with the steps applied: `breadcrumbs`, `breadcrumbs,extra` or
`breadcrumbs,extra,exception`. The tag records the last stage reached, even
when a stage found nothing to remove (for example, an event with no
breadcrumbs still reports `breadcrumbs`).

Source maps are a browser concern (JS bundles); PHP isn't minified, so there is
no source-map counterpart here.

## Develop

```bash
composer install
composer test
```

MIT © Qase
