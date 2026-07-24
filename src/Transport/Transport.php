<?php

declare(strict_types=1);

namespace LogHQ\Transport;

use LogHQ\Config;

interface Transport
{
    /**
     * Deliver a batch of log entries to the ingest. Implementations must never
     * throw - logging must never break the host application. Returns the HTTP
     * status code, or null when delivery failed outright.
     *
     * @param list<array<string, mixed>> $entries
     */
    public function send(array $entries, Config $config): ?int;
}
