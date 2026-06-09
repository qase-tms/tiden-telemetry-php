# tiden/php

Framework-agnostic error-tracking PHP SDK for
[Tiden](https://github.com/qase-tms/tiden-php). Emits the
envelope wire format to a Tiden ingest endpoint — **no third-party error-SDK
dependency**. For Laravel, use the `tiden/laravel` bridge (built on this).

```bash
composer require tiden/php
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
