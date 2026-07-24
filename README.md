# loghq — PHP SDK

Ship your application logs to [loghq](https://loghq.org) and read them in a fast,
searchable stream instead of `tail`-ing a `.log` file. This is the framework-agnostic
PHP core; for Laravel use [`loghq/loghq-laravel`](https://github.com/loghqorg/loghq-laravel),
which registers a `loghq` log channel on top of this.

## Install

```bash
composer require loghq/loghq
```

Requires PHP 8.4+, `ext-curl`, `ext-json`.

## Usage

```php
use LogHQ\LogHQ;

LogHQ::init([
    'key' => 'loghq_...',          // public per-project ingest key from your dashboard
    'environment' => 'production', // optional
    'channel' => 'api',            // default source tag for entries
]);

LogHQ::info('order placed', ['order_id' => 42, 'total' => 19.99]);
LogHQ::warning('low stock', ['sku' => 'ABC', 'left' => 3]);
LogHQ::error('payment failed', ['exception' => $e]);
```

Entries **buffer and ship in batches** (logs are high-volume), and the buffer is flushed
automatically at process shutdown. Anything at `error` or above flushes immediately, so a
crash never leaves its last lines stranded in a buffer. Call `LogHQ::flush()` to ship now.

### Configuration

The ingest endpoint is fixed to `https://loghq.org` — you only need a `key`. Everything
else is optional:

| Option | Default | Purpose |
|---|---|---|
| `key` | — | Public per-project ingest key (required). |
| `dsn` | — | `https://<key>@<host>/<project>` instead of separate `key`/`host`/`project`. |
| `host` | `https://loghq.org` | Override for self-hosting. |
| `environment` | `production` | Tags every entry. |
| `channel` | `app` | Default source/channel when a record carries none. |
| `minLevel` | `debug` | Drop records below this severity before buffering. |
| `sampleRate` | `1.0` | Keep a fraction of records (0..1). |
| `batchSize` | `25` | Buffer this many before shipping a batch. |
| `flushLevel` | `error` | Records this severe flush the buffer immediately. |
| `globalContext` | `[]` | Context merged into every entry. |
| `beforeSend` | — | `fn(array $entry): ?array` — mutate, or return null to drop. |

The ingest key is **public** — it ships in app config and is a revocable identifier, not a
secret. Sensitive context values (passwords, tokens, secrets, …) are redacted before send.

## Levels

The eight PSR-3 / RFC 5424 severities: `debug`, `info`, `notice`, `warning`, `error`,
`critical`, `alert`, `emergency` — each has a helper method, or call `LogHQ::log($level, …)`.

## The contract

`POST {host}/logs`, header `X-LogHQ-Key: <key>`, JSON body `{ "logs": [ entry, … ] }` where
each entry is `{ level, message, channel, context, environment, release, framework,
timestamp, host, sdk }`. Delivery is fail-open: the transport never throws, so a loghq
outage can never break your app — you just miss those lines.

## License

MIT
