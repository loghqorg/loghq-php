<?php

declare(strict_types=1);

namespace LogHQ;

use LogHQ\Transport\CurlTransport;
use LogHQ\Transport\Transport;

/**
 * The loghq log-streaming client.
 *
 * Records log entries, enriches them with channel/environment/runtime context,
 * buffers them, and POSTs them in batches to the loghq ingest:
 *
 * `POST {host}/logs`, header `X-LogHQ-Key: <ingest_key>`, JSON body
 * `{ logs: [ { level, message, channel, context, environment, release,
 * framework, timestamp, host, sdk }, ... ] }`.
 *
 * Entries buffer until `batchSize` accumulates (or a record at/above
 * `flushLevel` arrives, or `flush()`/shutdown), so a request that logs a
 * hundred lines makes a handful of round-trips, not a hundred.
 */
class Client
{
    public readonly Config $config;

    private Transport $transport;

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    /** Guards the shutdown flush so it runs at most once. */
    private bool $shutdownRegistered = false;

    /**
     * @param array<string, mixed>|Config $config
     */
    public function __construct(array|Config $config = [], ?Transport $transport = null)
    {
        $this->config = $config instanceof Config ? $config : new Config($config);
        $this->transport = $transport ?? new CurlTransport();

        // Whatever is still buffered when the process ends must ship. Per-request
        // hosts flush here; long-lived workers should also flush per unit of work.
        if ($this->config->enabled) {
            $this->registerShutdownFlush();
        }
    }

    // --- Capture ------------------------------------------------------------

    /**
     * Record one log entry. `$level` is any PSR-3 / RFC 5424 severity; unknown
     * strings normalise to `info`. Returns true when the entry was buffered
     * (not dropped by the level gate, sampling, or `beforeSend`).
     *
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $message, array $context = []): bool
    {
        if (!$this->config->enabled) {
            return false;
        }

        $level = LogLevel::normalize($level);
        if (!LogLevel::atLeast($level, $this->config->minLevel)) {
            return false;
        }

        if ($this->config->sampleRate < 1.0 && mt_rand() / mt_getrandmax() > $this->config->sampleRate) {
            return false;
        }

        $message = mb_substr($message, 0, $this->config->maxMessageChars);

        // A `channel` in context is promoted to the top-level field; a
        // Throwable under `exception` is flattened to a readable string so the
        // stream shows it without the SDK pretending to be an error tracker.
        $channel = $this->config->channel;
        if (isset($context['channel']) && \is_string($context['channel']) && $context['channel'] !== '') {
            $channel = $context['channel'];
            unset($context['channel']);
        }
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $e = $context['exception'];
            $context['exception'] = sprintf('%s: %s (%s:%d)', $e::class, $e->getMessage(), $e->getFile(), $e->getLine());
        }

        $merged = $this->config->globalContext !== [] || $context !== []
            ? $this->redact(array_merge($this->config->globalContext, $context))
            : null;

        $entry = array_filter([
            'project' => $this->config->project !== '' ? $this->config->project : null,
            'level' => $level,
            'message' => $message,
            'channel' => $channel,
            'context' => $merged !== [] ? $merged : null,
            'release' => $this->config->release,
            'framework' => $this->config->framework,
            'environment' => $this->config->environment,
            'host' => $this->config->sendDefaultContext ? (gethostname() ?: null) : null,
            'timestamp' => self::now(),
            'sdk' => ['name' => $this->config->sdkName, 'version' => LogHQ::VERSION],
        ], static fn ($v) => $v !== null);

        if ($this->config->beforeSend !== null) {
            $result = ($this->config->beforeSend)($entry);
            if ($result === null) {
                return false;
            }
            if (\is_array($result)) {
                $entry = $result;
            }
        }

        $this->buffer[] = $entry;

        // Bound the buffer: if nothing is flushing (misconfigured worker), drop
        // the oldest rather than grow without limit.
        $overflow = \count($this->buffer) - $this->config->maxBuffer;
        if ($overflow > 0) {
            $this->buffer = \array_slice($this->buffer, $overflow);
        }

        if (\count($this->buffer) >= $this->config->batchSize || LogLevel::atLeast($level, $this->config->flushLevel)) {
            $this->flush();
        }

        return true;
    }

    // --- Level helpers ------------------------------------------------------

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): bool
    {
        return $this->log(LogLevel::DEBUG, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): bool
    {
        return $this->log(LogLevel::INFO, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function notice(string $message, array $context = []): bool
    {
        return $this->log(LogLevel::NOTICE, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): bool
    {
        return $this->log(LogLevel::WARNING, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): bool
    {
        return $this->log(LogLevel::ERROR, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function critical(string $message, array $context = []): bool
    {
        return $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function alert(string $message, array $context = []): bool
    {
        return $this->log(LogLevel::ALERT, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function emergency(string $message, array $context = []): bool
    {
        return $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    // --- Buffer control -----------------------------------------------------

    /** Ship whatever is buffered now. Safe to call when empty (no-op). */
    public function flush(): bool
    {
        if ($this->buffer === []) {
            return true;
        }

        $batch = $this->buffer;
        // Clear first so a slow/failing send can't re-enter and double-ship the
        // same entries; dropped-on-failure is the deliberate fail-open choice.
        $this->buffer = [];

        $status = $this->transport->send($batch, $this->config);

        return $status !== null && $status >= 200 && $status < 300;
    }

    /** Number of entries currently buffered (not yet shipped). */
    public function pending(): int
    {
        return \count($this->buffer);
    }

    /** Merge context into every subsequent entry (e.g. a request id). @param array<string, mixed> $context */
    public function withContext(array $context): void
    {
        $this->config->globalContext = array_merge($this->config->globalContext, $context);
    }

    public function setRelease(string $release): void
    {
        $this->config->release = $release;
    }

    public function setEnvironment(string $environment): void
    {
        $this->config->environment = $environment;
    }

    // --- Internals ----------------------------------------------------------

    private function registerShutdownFlush(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        register_shutdown_function(function (): void {
            $this->flush();
        });
    }

    /**
     * ISO-8601 UTC with millisecond precision, matching the JS/other SDKs.
     */
    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Replace values of sensitive keys (passwords, tokens, secrets, ...) in
     * user-supplied context - recursively, with a depth cap so cyclic or
     * pathological data can't blow the stack.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function redact(array $data, int $depth = 0): array
    {
        if ($depth >= 8) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if (\is_string($key) && $this->isSensitiveKey($key)) {
                $data[$key] = '[redacted]';
                continue;
            }
            if (\is_array($value)) {
                $data[$key] = $this->redact($value, $depth + 1);
            }
        }

        return $data;
    }

    private function isSensitiveKey(string $key): bool
    {
        return array_any(
            $this->config->redactKeys,
            static fn (string $needle): bool => str_contains(strtolower($key), $needle),
        );
    }
}
