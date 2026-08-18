# loghq for PHP

[![CI](https://github.com/loghqorg/loghq-php/actions/workflows/ci.yml/badge.svg)](https://github.com/loghqorg/loghq-php/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-111827.svg)](./LICENSE)

The PHP client for [loghq](https://loghq.org). Buffers log entries, batches
them, and POSTs them to the ingest.

This is a log shipper, not an error tracker. All eight PSR-3 / RFC 5424
severities are first class: `debug` and `info` travel exactly like `emergency`
does, because the line before the failure is usually the one you need.

No composer dependencies. PHP 8.4+, with `ext-curl` and `ext-json`.

On Laravel, use [loghq/loghq-laravel](https://github.com/loghqorg/loghq-laravel)
instead. It wraps this client as a `loghq` log channel, so your existing
`Log::info()` calls ship with no code changes.

## Install

```sh
composer require loghq/loghq
```

> [!IMPORTANT]
> Not on Packagist yet, so that command fails with
> `could not be found in any version` until you add this to your application's
> `composer.json`:
>
> ```json
> "repositories": [
>     {
>         "type": "vcs",
>         "url": "https://github.com/loghqorg/loghq-php"
>     }
> ]
> ```
>
> Composer only reads `repositories` from the root package, so this has to go in
> the application, even when it is a dependency of a dependency. Drop the block
> once the package is submitted.

## Quick start

```php
use LogHQ\LogHQ;

LogHQ::init(['key' => 'loghq_your_project_key']);

LogHQ::info('order placed', ['order_id' => 42]);
LogHQ::error('payment failed', ['exception' => $e]);
```

The ingest key is a public, revocable project identifier, not a secret. It says
which project an entry belongs to and grants no read access, so it is safe in
app config and `.env`.

That is the whole integration. Entries buffer and ship in batches, and whatever
is still buffered is flushed automatically at process shutdown.

Prefer an object? The static API is a thin wrapper over one:

```php
use LogHQ\Client;

$client = new Client(['key' => 'loghq_...']);
$client->warning('cache miss storm', ['keys' => 1200]);
$client->flush();
```

A single DSN works instead of the separate parts:

```php
LogHQ::init(['dsn' => 'https://loghq_your_key@loghq.org/my-project']);
```

Explicit options beat DSN parts, so passing `host` alongside a `dsn` overrides
the host embedded in it.

## Levels

```php
LogHQ::debug($message, $context);
LogHQ::info($message, $context);
LogHQ::notice($message, $context);
LogHQ::warning($message, $context);
LogHQ::error($message, $context);
LogHQ::critical($message, $context);
LogHQ::alert($message, $context);
LogHQ::emergency($message, $context);

LogHQ::log('warning', $message, $context);   // or pass the level
```

Every one returns `bool`: `true` when the entry was buffered, `false` when it
was dropped because the client is disabled, the severity is below `minLevel`,
sampling discarded it, or `beforeSend` returned null. That return value is the
cheapest way to check your wiring:

```php
var_dump(LogHQ::error('probe'));   // false means nothing was recorded
```

Level strings are normalised, so common aliases from other loggers land
somewhere sensible rather than being rejected: `warn` becomes `warning`, `err`
and `fatal` become `error`, `crit` becomes `critical`, `emerg` and `panic`
become `emergency`, `trace` and `verbose` become `debug`. Anything unrecognised
becomes `info`. The numeric weights are Monolog's, so a Monolog handler maps
across without translation.

## Configuration

| Option | Default | What it does |
| --- | --- | --- |
| `key` | `''` | Project ingest key. **Empty disables the client silently.** |
| `dsn` | none | `https://<key>@<host>/<project>` instead of the parts. |
| `host` | `https://loghq.org` | Ingest host. Only set it to self-host. |
| `project` | `''` | Optional. The key alone already identifies the project. |
| `environment` | `production` | Reported on every entry. |
| `release` | none | Version or commit, reported on every entry. |
| `channel` | `app` | Default source tag for entries that carry none. |
| `enabled` | `true` | Master switch. |
| `minLevel` | `debug` | Drop anything less severe, before buffering. |
| `sampleRate` | `1.0` | Keep this fraction of records, applied after the level gate. |
| `batchSize` | `25` | Buffer this many, then ship. `1` sends each immediately. |
| `flushLevel` | `error` | Ship immediately at this severity and above. |
| `maxBuffer` | `500` | Hard cap. Past it the **oldest** buffered entries are dropped. |
| `maxMessageChars` | `16384` | Messages are truncated to this before send. |
| `timeout` | `5` | Total transport timeout, seconds. |
| `connectTimeout` | `2` | Connect timeout, seconds. |
| `sendDefaultContext` | `true` | Attach the machine hostname to every entry. |
| `globalContext` | `[]` | Merged into every entry's context. |
| `beforeSend` | none | `callable(array $entry): ?array`. Return null to drop. |
| `redactKeys` | see below | Context keys whose values are replaced. |

> [!NOTE]
> Entries POST to `{host}/logs`, so the default resolves to
> `https://loghq.org/logs`. The path comes from the ingest spec rather than from
> this SDK, and is the same in every language client.
>
> Leave `host` alone unless you self-host. The SDK owning the endpoint is what
> stops it being copied into every consuming application and having to be found
> again when it changes.
>
> To develop against a local loghq (`bun run dev` in the loghq repo), set
> `host` to `http://127.0.0.1:3008`. Use the IPv4 literal rather than
> `localhost`: that server binds 127.0.0.1 only, and `localhost` can resolve to
> `::1` first and be refused.

## What gets sent

`LogHQ::info('cache warmed', ['keys' => 12])` arrives as:

```json
{
  "level": "info",
  "message": "cache warmed",
  "channel": "app",
  "context": { "keys": 12 },
  "environment": "production",
  "host": "web-01",
  "timestamp": "2026-08-17T12:56:51.066Z",
  "sdk": { "name": "loghq.php", "version": "0.1.0" }
}
```

On the wire that is `POST {host}/logs`, authenticated with `X-LogHQ-Key`, body
`{"logs": [ ... ]}`.

A `channel` key in context is promoted to the top-level field and removed from
the context, which is how you label a source:

```php
LogHQ::info('charged', ['channel' => 'billing', 'amount' => 4200]);
```

A `Throwable` under `context['exception']` is flattened to
`Class: message (file:line)`, so the stream stays readable without this
pretending to be an error tracker.

### Redaction

Context keys that look like credentials have their values replaced with
`[redacted]` before anything leaves the process, recursively through nested
arrays, with a depth cap:

`password`, `passwd`, `secret`, `token`, `api_key`, `apikey`, `authorization`,
`auth`, `cookie`, `credential`, `private_key`, `access_key`, `session_id`

This matches on the key, not the value, so a secret interpolated into a message
string is not caught. Treat it as a safety net for context you forgot about,
not as licence to log credentials.

### Shared context

```php
LogHQ::withContext(['service' => 'checkout', 'region' => 'eu-west-1']);
```

Merged into every entry recorded afterwards. Per-call context wins on a key
collision.

## Buffering and flushing

Logs are high volume, so entries buffer and ship together. A request that logs a
hundred lines makes a handful of round trips, not a hundred. The buffer ships
when:

- it reaches `batchSize`
- a record arrives at `flushLevel` or above, so an error never sits waiting
- you call `flush()`
- the process shuts down, via `register_shutdown_function`

```php
LogHQ::flush();          // ship now, returns true on a 2xx
LogHQ::close();          // flush and drop the default client
$client->pending();      // entries buffered but not yet shipped
```

On a long-lived worker, flush per unit of work as well. The shutdown hook only
runs when the process ends, which for a queue worker may be hours of jobs later.

Two deliberate choices worth knowing. `maxBuffer` drops the **oldest** entries
when a misconfigured worker never flushes, so a burst cannot grow the process
out of memory. And a failed send **drops that batch** rather than retrying: the
buffer is cleared before the request so a slow or failing send cannot re-enter
and double-ship, which means delivery is best effort by design.

## Failure behaviour

Logging must never be the reason a request fails:

- The transport never throws. Any error yields a null status and the batch is
  dropped.
- An empty key disables the client. Nothing is sent and nothing throws.
- Timeouts are tight by default, 2 seconds to connect and 5 in total, so an
  unreachable ingest cannot hang the caller for long.

The tradeoff is that a misconfiguration is quiet. If loghq shows nothing, check
the return value of a call before digging further.

## Custom transports

The transport is swappable, which is how the test suite captures batches instead
of sending them:

```php
use LogHQ\Config;
use LogHQ\Transport\Transport;

final class ArrayTransport implements Transport
{
    public array $batches = [];

    public function send(array $entries, Config $config): ?int
    {
        $this->batches[] = $entries;

        return 201;
    }
}

$client = new Client(['key' => 'loghq_test'], new ArrayTransport());
```

Implementations must never throw. Return the HTTP status, or null when delivery
failed outright.

## Framework integrations

| Framework | Package |
| --- | --- |
| Laravel | [loghq/loghq-laravel](https://github.com/loghqorg/loghq-laravel) |

Integrations construct their own client and call `LogHQ::useClient($client)` so
the static API shares it rather than silently no-oping alongside it.

## Testing

```sh
composer test
```

## License

MIT. See [LICENSE](./LICENSE).
