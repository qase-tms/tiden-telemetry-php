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

### Scopes

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
  throws; honors HTTP 429 + Retry-After).
- Scrubs likely-PII (auth headers, secret-ish keys) unless `send_default_pii`.
- `before_send` hook to mutate or drop events.

Source maps are a browser concern (JS bundles); PHP isn't minified, so there is
no source-map counterpart here.

## Develop

```bash
composer install
composer test
```

MIT © Qase
