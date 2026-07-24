<?php

declare(strict_types=1);

namespace LogHQ\Tests;

use LogHQ\Config;
use LogHQ\LogLevel;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function test_defaults(): void
    {
        $c = new Config(['key' => 'loghq_x']);
        self::assertSame('https://loghq.org', $c->host);
        self::assertTrue($c->enabled);
        self::assertSame(LogLevel::DEBUG, $c->minLevel);
        self::assertSame('app', $c->channel);
    }

    public function test_missing_key_disables(): void
    {
        self::assertFalse((new Config())->enabled);
    }

    public function test_trailing_slash_stripped_from_host(): void
    {
        self::assertSame('https://loghq.org', (new Config(['key' => 'k', 'host' => 'https://loghq.org/']))->host);
    }

    public function test_dsn_parsing(): void
    {
        $c = new Config(['dsn' => 'https://loghq_abc@logs.example.com/proj_1']);
        self::assertSame('https://logs.example.com', $c->host);
        self::assertSame('loghq_abc', $c->key);
        self::assertSame('proj_1', $c->project);
    }

    public function test_explicit_options_override_dsn(): void
    {
        $c = new Config([
            'dsn' => 'https://loghq_abc@logs.example.com/proj_1',
            'host' => 'https://self.hosted',
        ]);
        self::assertSame('https://self.hosted', $c->host);
    }

    public function test_min_level_normalised(): void
    {
        self::assertSame(LogLevel::WARNING, (new Config(['key' => 'k', 'minLevel' => 'WARN']))->minLevel);
    }

    public function test_sample_rate_clamped(): void
    {
        self::assertSame(1.0, (new Config(['key' => 'k', 'sampleRate' => 5]))->sampleRate);
        self::assertSame(0.0, (new Config(['key' => 'k', 'sampleRate' => -1]))->sampleRate);
    }

    public function test_batch_size_floored_at_one(): void
    {
        self::assertSame(1, (new Config(['key' => 'k', 'batchSize' => 0]))->batchSize);
    }
}
