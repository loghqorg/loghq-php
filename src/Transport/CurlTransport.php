<?php

declare(strict_types=1);

namespace LogHQ\Transport;

use LogHQ\Config;

/**
 * Default transport: a blocking curl POST of a `{ logs: [...] }` batch to
 * `{host}/logs` with tight timeouts, authenticated by the public ingest key in
 * `X-LogHQ-Key`.
 */
final class CurlTransport implements Transport
{
    public function send(array $entries, Config $config): ?int
    {
        if ($entries === []) {
            return null;
        }

        try {
            $body = json_encode(['logs' => array_values($entries)], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($body === false) {
                return null;
            }

            $ch = curl_init($config->host . '/logs');
            if ($ch === false) {
                return null;
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $config->connectTimeout,
                CURLOPT_TIMEOUT => $config->timeout,
                CURLOPT_USERAGENT => $config->userAgent,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-LogHQ-Key: ' . $config->key,
                ],
            ]);

            $ok = curl_exec($ch);
            // curl_close() is a no-op since PHP 8.0 and deprecated in 8.5 -
            // the handle is freed when it goes out of scope.
            return $ok === false ? null : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        } catch (\Throwable) {
            // never let logging throw
            return null;
        }
    }
}
