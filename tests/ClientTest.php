<?php

declare(strict_types=1);

namespace LogHQ\Tests;

use LogHQ\Client;
use LogHQ\LogLevel;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private function client(array $options, MockTransport $transport): Client
    {
        return new Client(array_merge(['key' => 'loghq_test', 'batchSize' => 100], $options), $transport);
    }

    public function test_it_buffers_until_flush(): void
    {
        $t = new MockTransport();
        $c = $this->client([], $t);

        $c->info('one');
        $c->info('two');
        self::assertSame(2, $c->pending());
        self::assertSame([], $t->batches, 'nothing shipped before flush or batch fill');

        $c->flush();
        self::assertSame(0, $c->pending());
        self::assertCount(1, $t->batches);
        self::assertCount(2, $t->batches[0]);
        self::assertSame('one', $t->batches[0][0]['message']);
    }

    public function test_batch_size_triggers_a_flush(): void
    {
        $t = new MockTransport();
        $c = $this->client(['batchSize' => 3], $t);

        $c->debug('a');
        $c->debug('b');
        self::assertCount(0, $t->batches);
        $c->debug('c'); // hits batchSize -> flush
        self::assertCount(1, $t->batches);
        self::assertCount(3, $t->batches[0]);
    }

    public function test_error_level_flushes_immediately(): void
    {
        $t = new MockTransport();
        $c = $this->client([], $t); // batchSize 100, flushLevel error (default)

        $c->info('buffered');
        self::assertCount(0, $t->batches);
        $c->error('boom'); // >= flushLevel -> immediate flush of the whole buffer
        self::assertCount(1, $t->batches);
        self::assertCount(2, $t->batches[0]);
        self::assertSame('boom', $t->batches[0][1]['message']);
    }

    public function test_min_level_drops_below_threshold(): void
    {
        $t = new MockTransport();
        $c = $this->client(['minLevel' => LogLevel::WARNING], $t);

        self::assertFalse($c->debug('nope'));
        self::assertFalse($c->info('nope'));
        self::assertTrue($c->warning('kept'));
        self::assertTrue($c->error('kept'));
        $c->flush();
        self::assertCount(2, $t->entries());
    }

    public function test_context_is_redacted(): void
    {
        $t = new MockTransport();
        $c = $this->client([], $t);

        $c->info('login', ['user' => 'ada', 'password' => 'hunter2', 'api_key' => 'sk_live_x']);
        $c->flush();

        $ctx = $t->entries()[0]['context'];
        self::assertSame('ada', $ctx['user']);
        self::assertSame('[redacted]', $ctx['password']);
        self::assertSame('[redacted]', $ctx['api_key']);
    }

    public function test_channel_in_context_is_promoted(): void
    {
        $t = new MockTransport();
        $c = $this->client([], $t);

        $c->info('hi', ['channel' => 'billing', 'amount' => 10]);
        $c->flush();

        $entry = $t->entries()[0];
        self::assertSame('billing', $entry['channel']);
        self::assertArrayNotHasKey('channel', $entry['context']);
        self::assertSame(10, $entry['context']['amount']);
    }

    public function test_before_send_can_drop(): void
    {
        $t = new MockTransport();
        $c = $this->client(['beforeSend' => static fn (array $e): ?array => $e['level'] === 'debug' ? null : $e], $t);

        self::assertFalse($c->debug('drop me'));
        self::assertTrue($c->info('keep me'));
        $c->flush();
        self::assertCount(1, $t->entries());
    }

    public function test_disabled_without_key(): void
    {
        $t = new MockTransport();
        $c = new Client(['batchSize' => 1], $t); // no key -> disabled

        self::assertFalse($c->info('ignored'));
        $c->flush();
        self::assertCount(0, $t->batches);
    }

    public function test_global_context_merges_into_every_entry(): void
    {
        $t = new MockTransport();
        $c = $this->client(['globalContext' => ['service' => 'api']], $t);

        $c->info('a', ['x' => 1]);
        $c->flush();
        $ctx = $t->entries()[0]['context'];
        self::assertSame('api', $ctx['service']);
        self::assertSame(1, $ctx['x']);
    }
}
