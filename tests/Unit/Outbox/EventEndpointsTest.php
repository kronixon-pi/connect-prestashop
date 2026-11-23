<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Outbox;

use Kronixon\Connect\Api\Response;
use Kronixon\Connect\Outbox\CatalogEnricher;
use Kronixon\Connect\Outbox\Change;
use Kronixon\Connect\Outbox\EventEndpoints;
use Kronixon\Connect\Outbox\Events;
use Kronixon\Connect\Outbox\OutboxStore;
use Kronixon\Connect\Outbox\Recorder;
use Kronixon\Connect\Tests\Support\CatalogDb;
use Kronixon\Connect\Tests\Support\MemorySettings;
use Kronixon\Connect\Tests\Support\ProtocolSchemas;
use Kronixon\Connect\Tests\Support\TestModule;
use PHPUnit\Framework\TestCase;

/**
 * `GET events` and `POST events/ack` through the Kernel, signed as the platform sends them.
 */
final class EventEndpointsTest extends TestCase
{
    /** @var TestModule */
    private $module;

    /** @var CatalogDb */
    private $db;

    /** @var int */
    private $requests = 0;

    protected function setUp(): void
    {
        $this->db = new CatalogDb();
        $this->db->demo();
        $this->module = new TestModule();
        $this->module->pair();
        $db = $this->db;
        $this->module->router = (new EventEndpoints(static function () use ($db): array {
            return [new OutboxStore($db), new Events(new CatalogEnricher($db, $db->scope(), $db))];
        }, $this->module->clock))->register($this->module->router);

        $recorder = new Recorder($this->db, new MemorySettings(), $this->module->clock);
        $recorder->record(new Change('product.updated', '1'));
        $recorder->record(new Change('product.updated', '1'));
        $recorder->record(new Change('stock.updated', '1', ['variant_id' => '0', 'quantity' => 6], '0'));
    }

    public function test_pull_walks_the_rows_one_event_each_and_ack_takes_them_out(): void
    {
        $first = $this->call('GET', 'events', ['limit' => '2']);
        self::assertSame(200, $first->status(), $first->body());
        self::assertSame([], ProtocolSchemas::errors('events.json', $first->body()));
        $page = json_decode($first->body(), true);
        self::assertSame(['product.updated', 'product.updated'], array_column($page['data'], 'type'), 'not coalesced: each row can be acknowledged');
        self::assertTrue($page['meta']['has_more']);

        $second = json_decode($this->call('GET', 'events', ['limit' => '2', 'cursor' => (string) $page['meta']['next_cursor']])->body(), true);
        self::assertSame(['stock.updated'], array_column($second['data'], 'type'));
        self::assertFalse($second['meta']['has_more']);

        $ack = $this->call('POST', 'events/ack', [], (string) json_encode(['ids' => array_column($page['data'], 'event_id')]));
        self::assertSame(200, $ack->status(), $ack->body());
        self::assertSame(['acknowledged' => 2], json_decode($ack->body(), true));
        self::assertSame([], ProtocolSchemas::errors('events-ack.json', $ack->body()));

        $left = json_decode($this->call('GET', 'events')->body(), true);
        self::assertSame(['stock.updated'], array_column($left['data'], 'type'));
    }

    public function test_bad_requests_are_refused(): void
    {
        self::assertSame(400, $this->call('GET', 'events', ['limit' => '201x'])->status());
        self::assertSame(400, $this->call('GET', 'events', ['cursor' => 'nope!'])->status());
        foreach (['{}', '{"ids":[]}', '{"ids":["x"]}', '{"ids":{"a":"0192b2e4-7c1a-7d3e-9f4b-2a6c8e0d1f35"}}',
            '{"ids":["0192b2e4-7c1a-7d3e-9f4b-2a6c8e0d1f35","0192B2E4-7C1A-7D3E-9F4B-2A6C8E0D1F35"]}'] as $body) {
            self::assertSame(422, $this->call('POST', 'events/ack', [], $body)->status(), $body);
        }
    }

    /**
     * @param  array<string, string>  $query
     */
    private function call(string $method, string $endpoint, array $query = [], string $body = ''): Response
    {
        $nonce = sprintf('4056df7f-8d8a-445a-9102-1e318c62%04d', ++$this->requests);
        $response = $this->module->kernel()->handle($this->module->signedServer((string) $this->module->platform->secret, $method, $endpoint, $query, $body, $nonce), $body);
        self::assertTrue(TestModule::signedWith($response, $nonce, (string) $this->module->platform->secret));

        return $response;
    }
}
