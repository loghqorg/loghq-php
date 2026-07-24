<?php

declare(strict_types=1);

namespace LogHQ;

/**
 * The eight PSR-3 / RFC 5424 severities loghq understands, and the numeric
 * weights used to compare them (Monolog's values, so a Monolog handler maps
 * across without translation). Anything unrecognised is treated as `info`.
 */
final class LogLevel
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const NOTICE = 'notice';
    public const WARNING = 'warning';
    public const ERROR = 'error';
    public const CRITICAL = 'critical';
    public const ALERT = 'alert';
    public const EMERGENCY = 'emergency';

    /** @var array<string, int> */
    private const WEIGHTS = [
        self::DEBUG => 100,
        self::INFO => 200,
        self::NOTICE => 250,
        self::WARNING => 300,
        self::ERROR => 400,
        self::CRITICAL => 500,
        self::ALERT => 550,
        self::EMERGENCY => 600,
    ];

    /** Canonicalise an arbitrary level string to one of the eight names. */
    public static function normalize(string $level): string
    {
        $lower = strtolower(trim($level));

        // A few common aliases callers reach for.
        $lower = match ($lower) {
            'warn' => self::WARNING,
            'err', 'fatal' => self::ERROR,
            'crit' => self::CRITICAL,
            'emerg', 'panic' => self::EMERGENCY,
            'trace', 'verbose' => self::DEBUG,
            default => $lower,
        };

        return isset(self::WEIGHTS[$lower]) ? $lower : self::INFO;
    }

    /** Numeric severity for a level (higher = more severe). */
    public static function weight(string $level): int
    {
        return self::WEIGHTS[self::normalize($level)] ?? self::WEIGHTS[self::INFO];
    }

    /** True when `$level` is at least as severe as `$threshold`. */
    public static function atLeast(string $level, string $threshold): bool
    {
        return self::weight($level) >= self::weight($threshold);
    }
}
