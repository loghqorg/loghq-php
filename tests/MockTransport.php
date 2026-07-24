<?php

declare(strict_types=1);

namespace LogHQ\Tests;

use LogHQ\Config;
use LogHQ\Transport\Transport;

/**
 * Captures batches instead of sending them, so tests can assert on what would
 * have shipped without touching the network.
 */
final class MockTransport implements Transport
{
    /** @var list<list<array<string, mixed>>> */
    public array $batches = [];

    public int $status = 201;

    public function send(array $entries, Config $config): ?int
    {
        $this->batches[] = $entries;

        return $this->status;
    }

    /** Every entry across every batch, flattened. @return list<array<string, mixed>> */
    public function entries(): array
    {
        return array_merge(...$this->batches ?: [[]]);
    }
}
