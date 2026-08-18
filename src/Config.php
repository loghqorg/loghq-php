<?php

declare(strict_types=1);

namespace LogHQ;

/**
 * Resolved client configuration.
 *
 * Only the `key` is required - it's globally unique, so the ingest resolves the
 * project from it alone. You may still pass an explicit `project` (+ optional
 * `host`), or a `dsn` of the form `https://<ingest_key>@<host>/<project_id>`.
 * The ingest key is public - it ships in app config and is a revocable
 * identifier, not a secret.
 */
final class Config
{
    /**
     * Where entries POST, as `DEFAULT_HOST . '/logs'`, when neither an explicit
     * `host` nor a DSN says otherwise.
     *
     * The path is not this SDK's to choose. `docs/ingest.md` in the loghq repo
     * is the wire contract and specifies `POST /logs` at the app root, which
     * `app/Routes.ts` registers with an empty prefix. Every client in every
     * language targets that same URL.
     *
     * Self-hosters, and only they, override with an explicit `host` or a DSN.
     * An application on hosted loghq should leave this alone: the SDK owning
     * the endpoint is what stops a host being copied into every consumer and
     * having to be hunted down again later.
     *
     * Point it at `http://127.0.0.1:3008` to develop against `bun run dev` in
     * the loghq repo. Use the IPv4 literal rather than `localhost` there, since
     * that server binds 127.0.0.1 only and `localhost` may resolve to `::1`.
     */
    public const DEFAULT_HOST = 'https://loghq.org';

    public string $project = '';

    public string $key = '';

    public string $host = self::DEFAULT_HOST;

    public ?string $release = null;

    public string $environment = 'production';

    /** Default channel/source tag when a log record carries none (e.g. `laravel`). */
    public string $channel = 'app';

    /** Framework tag (set by framework integrations, e.g. `laravel`). */
    public ?string $framework = null;

    /** SDK name reported in `entry.sdk` (integrations override this). */
    public string $sdkName = 'loghq.php';

    public bool $enabled = true;

    /**
     * Drop records below this severity before they are ever buffered. Defaults
     * to `debug` (keep everything); raise it to thin high-volume streams.
     */
    public string $minLevel = LogLevel::DEBUG;

    /** Fraction of records to keep, 0..1 (applied after the level gate). */
    public float $sampleRate = 1.0;

    /**
     * Records buffer until this many accumulate, then flush as one batch. Logs
     * are high-volume, so batching keeps the ingest to a handful of round-trips
     * per request instead of one per line. Set to 1 to send each immediately.
     */
    public int $batchSize = 25;

    /**
     * Records at or above this severity flush the buffer immediately rather
     * than waiting for it to fill - an error shouldn't sit in a buffer that
     * only flushes at request end. Set to `emergency`+1 semantics by using a
     * level no record reaches to disable eager flushing.
     */
    public string $flushLevel = LogLevel::ERROR;

    /** Hard cap on buffered records; the oldest are dropped past this. */
    public int $maxBuffer = 500;

    /** Max characters kept per message (bounded before send). */
    public int $maxMessageChars = 16_384;

    /** Total transport timeout (seconds). */
    public int $timeout = 5;

    /** Transport connect timeout (seconds). */
    public int $connectTimeout = 2;

    /** User-Agent the transport sends (the ingest reads it for server clients). */
    public string $userAgent = 'loghq-php/' . LogHQ::VERSION;

    /** Attach hostname + runtime context to every entry automatically. */
    public bool $sendDefaultContext = true;

    /** Context merged into every record's `context` (e.g. a service name). @var array<string, mixed> */
    public array $globalContext = [];

    /**
     * Inspect/mutate an entry before send; return null to drop it.
     *
     * @var null|callable(array<string, mixed>): (array<string, mixed>|null)
     */
    public $beforeSend = null;

    /**
     * Key substrings whose values are redacted from context before send
     * (case-insensitive).
     *
     * @var list<string>
     */
    public array $redactKeys = [
        'password',
        'passwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'authorization',
        'auth',
        'cookie',
        'credential',
        'private_key',
        'access_key',
        'session_id',
    ];

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        if (isset($options['dsn']) && \is_string($options['dsn']) && $options['dsn'] !== '') {
            $parsed = self::parseDsn($options['dsn']);
            if ($parsed !== null) {
                $this->host = $parsed['host'];
                $this->key = $parsed['key'];
                $this->project = $parsed['project'];
            }
        }

        foreach (['project', 'key', 'host', 'release', 'environment', 'channel', 'framework', 'sdkName', 'userAgent', 'minLevel', 'flushLevel'] as $prop) {
            if (isset($options[$prop]) && \is_string($options[$prop]) && $options[$prop] !== '') {
                $this->{$prop} = $options[$prop];
            }
        }

        foreach (['enabled', 'sendDefaultContext'] as $prop) {
            if (\array_key_exists($prop, $options)) {
                $this->{$prop} = (bool) $options[$prop];
            }
        }

        foreach (['batchSize', 'maxBuffer', 'maxMessageChars', 'timeout', 'connectTimeout'] as $prop) {
            if (isset($options[$prop]) && \is_numeric($options[$prop])) {
                $this->{$prop} = (int) $options[$prop];
            }
        }

        if (isset($options['sampleRate']) && \is_numeric($options['sampleRate'])) {
            $this->sampleRate = max(0.0, min(1.0, (float) $options['sampleRate']));
        }

        foreach (['globalContext', 'redactKeys'] as $prop) {
            if (isset($options[$prop]) && \is_array($options[$prop])) {
                $this->{$prop} = $options[$prop];
            }
        }

        if (isset($options['beforeSend']) && \is_callable($options['beforeSend'])) {
            $this->beforeSend = $options['beforeSend'];
        }

        $this->host = rtrim($this->host, '/');
        $this->minLevel = LogLevel::normalize($this->minLevel);
        $this->batchSize = max(1, $this->batchSize);

        // The key is all that's required: it's globally unique, so the ingest
        // resolves the project from it alone. Without one, nothing can ship.
        if ($this->key === '') {
            $this->enabled = false;
        }
    }

    /**
     * Parse a DSN of the form `https://<key>@<host>/<project>`.
     *
     * @return array{host: string, key: string, project: string}|null
     */
    public static function parseDsn(string $dsn): ?array
    {
        $parts = parse_url($dsn);
        if ($parts === false || !isset($parts['host'])) {
            return null;
        }

        $project = isset($parts['path']) ? trim($parts['path'], '/') : '';
        $project = explode('/', $project)[0] ?? '';
        if ($project === '') {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return [
            'host' => $host,
            'key' => $parts['user'] ?? ($parts['pass'] ?? ''),
            'project' => $project,
        ];
    }
}
