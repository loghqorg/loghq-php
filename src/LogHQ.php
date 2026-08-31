<?php

declare(strict_types=1);

namespace LogHQ;

use LogHQ\Transport\Transport;

/**
 * Static entry point holding the default client:
 *
 * ```php
 * LogHQ::init(['key' => 'loghq_...']);
 * LogHQ::info('order placed', ['order_id' => 42]);
 * LogHQ::error('payment failed', ['exception' => $e]);
 * ```
 *
 * Buffered entries ship in batches and are flushed automatically at process
 * shutdown; call `LogHQ::flush()` to ship immediately.
 */
final class LogHQ
{
    // Reported as `sdk.version` on every ingested entry (Client.php) and in the
    // User-Agent (Config.php). Bump it in the same commit as the release tag —
    // it sat at 0.1.0 through v0.1.1 and v0.2.0, so every entry loghq received
    // from this SDK claimed a version that had not shipped in a year.
    public const VERSION = '0.2.0';

    private static ?Client $client = null;

    /**
     * @param array<string, mixed>|Config $config
     */
    public static function init(array|Config $config = [], ?Transport $transport = null): Client
    {
        self::$client = new Client($config, $transport);

        return self::$client;
    }

    public static function client(): ?Client
    {
        return self::$client;
    }

    /**
     * Adopt an externally constructed client (no new instance). Integrations
     * that manage the client themselves - e.g. the Laravel package's container
     * singleton - call this so the plain static API shares that same client
     * instead of silently no-oping.
     */
    public static function useClient(Client $client): Client
    {
        self::$client = $client;

        return $client;
    }

    /** @param array<string, mixed> $context */
    public static function log(string $level, string $message, array $context = []): bool
    {
        return self::$client?->log($level, $message, $context) ?? false;
    }

    /** @param array<string, mixed> $context */
    public static function debug(string $message, array $context = []): bool
    {
        return self::$client?->debug($message, $context) ?? false;
    }

    /** @param array<string, mixed> $context */
    public static function info(string $message, array $context = []): bool
    {
        return self::$client?->info($message, $context) ?? false;
    }

    /** @param array<string, mixed> $context */
    public static function notice(string $message, array $context = []): bool
    {
        return self::$client?->notice($message, $context) ?? false;
    }

    /** @param array<string, mixed> $context */
    public static function warning(string $message, array $context = []): bool
    {
        return self::$client?->warning($message, $context) ?? false;
    }

    /** @param array<string, mixed> $context */
    public static function error(string $message, array $context = []): bool
    {
        return self::$client?->error($message, $context) ?? false;
    }

    /** @param array<string, mixed> $context */
    public static function critical(string $message, array $context = []): bool
    {
        return self::$client?->critical($message, $context) ?? false;
    }

    /** @param array<string, mixed> $context */
    public static function alert(string $message, array $context = []): bool
    {
        return self::$client?->alert($message, $context) ?? false;
    }

    /** @param array<string, mixed> $context */
    public static function emergency(string $message, array $context = []): bool
    {
        return self::$client?->emergency($message, $context) ?? false;
    }

    /** @param array<string, mixed> $context */
    public static function withContext(array $context): void
    {
        self::$client?->withContext($context);
    }

    public static function flush(): bool
    {
        return self::$client?->flush() ?? true;
    }

    /** Flush and drop the default client. */
    public static function close(): void
    {
        self::$client?->flush();
        self::$client = null;
    }
}
