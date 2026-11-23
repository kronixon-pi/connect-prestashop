<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Outbox;

use Kronixon\Connect\Outbox\CatalogEnricher;
use Kronixon\Connect\Outbox\Change;
use Kronixon\Connect\Outbox\Events;
use Kronixon\Connect\Outbox\Origin;
use Kronixon\Connect\Outbox\OutboxStore;
use Kronixon\Connect\Outbox\Recorder;
use Kronixon\Connect\Tests\Support\CatalogDb;
use Kronixon\Connect\Tests\Support\FixedClock;
use Kronixon\Connect\Tests\Support\MemorySettings;
use Kronixon\Connect\Tests\Support\ProtocolSchemas;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The outbox on the catalog tables: what the hooks record, how rows become events (coalesced per entity,
 * in order, enriched from the catalog readers), and the states of a row from pending to sent or failed.
 */
final class OutboxTest extends TestCase
{
    /** @var CatalogDb */
    private $db;

    /** @var MemorySettings */
    private $settings;

    /** @var FixedClock */
    private $clock;

    /** @var OutboxStore */
    private $store;

    protected function setUp(): void
    {
        Origin::reset();
        $this->db = new CatalogDb();
        $this->db->demo();
        $this->settings = new MemorySettings();
        $this->clock = new FixedClock(1790772000.25);
        $this->store = new OutboxStore($this->db);
    }

    protected function tearDown(): void
    {
        Origin::reset();
    }

    public function test_a_change_is_one_row_with_its_time_in_milliseconds(): void
    {
        $this->recorder()->record(new Change('stock.updated', '1', ['variant_id' => '0', 'quantity' => 6, 'previous_quantity' => 7], '0'));

        $rows = $this->store->pending(10);
        self::assertCount(1, $rows);
        self::assertSame('stock.updated', $rows[0]['type']);
        self::assertSame('stock.updated:1:0', $rows[0]['entity_key']);
        self::assertSame('2026-09-30 12:40:00.250', $rows[0]['occurred_at']);
        self::assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/', (string) $rows[0]['event_id']);
    }

    public function test_the_platforms_own_request_records_nothing(): void
    {
        Origin::platformRequest();
        $this->recorder()->record(new Change('product.updated', '1'));
        $this->recorder()->capture(static function (): array {
            return [new Change('product.updated', '1')];
        });

        self::assertSame([], $this->store->pending(10));
    }

    public function test_a_failed_insert_is_a_gap_and_never_an_error(): void
    {
        $this->db->execute('DROP TABLE ps_kx_outbox');
        $this->recorder()->record(new Change('product.updated', '1'));
        self::assertSame('1790772000', $this->settings->get(Recorder::GAP));

        $this->clock->advance(60);
        $this->recorder()->capture(static function (): array {
            throw new RuntimeException('lookup failed');
        });
        self::assertSame('1790772000', $this->settings->get(Recorder::GAP), 'the first gap is kept');
    }

    public function test_a_hundred_changes_of_one_product_are_one_event_and_others_keep_their_order(): void
    {
        $recorder = $this->recorder();
        $recorder->record(new Change('category.updated', '45', ['parent_id' => '2']));
        for ($i = 0; $i < 100; $i++) {
            $this->clock->advance(0.01);
            $recorder->record(new Change('product.updated', '1'));
        }
        $recorder->record(new Change('brand.updated', '12'));

        $built = $this->events()->build($this->store->pending(1000), true);

        self::assertSame(['category.updated', 'product.updated', 'brand.updated'], array_map(static function (array $entry): string {
            return $entry['event']['type'];
        }, $built));
        self::assertCount(100, $built[1]['ids'], 'every row of the product goes with its event');
        $rows = $this->store->pending(1000);
        self::assertSame(strtolower((string) $rows[100]['event_id']), $built[1]['event']['event_id'], 'the newest row names the event');
        self::assertSame('2026-09-30T12:40:01.25Z', $built[1]['event']['occurred_at']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $built[1]['event']['payload']['content_hash']);
        self::assertSame('0192b2e4-2f7a-7c5b-8a1d-3e4f5a6b7c8d', $built[1]['event']['kx_id'], 'a linked product carries its kx_id');
        self::assertSame('product.updated:1:'.$rows[100]['id_outbox'], $built[1]['event']['dedupe_key']);
        self::assertSame([], ProtocolSchemas::errors('events.json', ['data' => array_column($built, 'event'), 'meta' => ['count' => 3, 'next_cursor' => null, 'has_more' => false, 'generated_at' => '2026-09-30T12:40:00Z']]));
    }

    public function test_stock_keeps_the_latest_quantity_and_the_first_previous_one(): void
    {
        $recorder = $this->recorder();
        $recorder->record(new Change('stock.updated', '1', ['variant_id' => '0', 'quantity' => 6, 'previous_quantity' => 7], '0'));
        $recorder->record(new Change('stock.updated', '1', ['variant_id' => '0', 'quantity' => 4, 'previous_quantity' => 6], '0'));
        $recorder->record(new Change('stock.updated', '1', ['variant_id' => '3', 'quantity' => 1], '3'));

        $built = $this->events()->build($this->store->pending(10), true);

        self::assertCount(2, $built, 'one event per stock row');
        self::assertSame(['variant_id' => '0', 'quantity' => 4, 'previous_quantity' => 7], $built[0]['event']['payload']);
        self::assertSame(['variant_id' => '3', 'quantity' => 1], $built[1]['event']['payload']);
    }

    public function test_an_entity_that_is_gone_drops_its_event_and_a_price_is_read_when_sent(): void
    {
        $recorder = $this->recorder();
        $recorder->record(new Change('product.updated', '999'));
        $recorder->record(new Change('price.updated', '1', ['variant_id' => '0'], '0'));
        $recorder->record(new Change('price.updated', '999', ['variant_id' => '0'], '0'));
        $recorder->record(new Change('product.deleted', '999'));

        $built = $this->events()->build($this->store->pending(10), true);

        self::assertNull($built[0]['event']);
        self::assertSame(['variant_id' => '0', 'price' => '99.990000'], $built[1]['event']['payload']);
        self::assertNull($built[2]['event']);
        self::assertSame('product.deleted', $built[3]['event']['type']);
        self::assertSame('{}', json_encode($built[3]['event']['payload']), 'an empty payload is an object');
    }

    public function test_a_claimed_batch_ends_sent_failed_or_pending_again(): void
    {
        $recorder = $this->recorder();
        foreach (['1', '2', '3'] as $id) {
            $recorder->record(new Change('brand.updated', $id));
        }
        $ids = array_map('intval', array_column($this->store->pending(10), 'id_outbox'));

        $claimed = $this->store->claim($ids, 'b1');
        self::assertCount(3, $claimed);
        self::assertSame([], $this->store->claim($ids, 'b2'), 'a row is in one batch at a time');
        self::assertCount(3, $this->store->unfinished(10), 'a batch cut short is found again');

        $this->store->sent([$ids[0]], '2026-09-30 12:41:00');
        $this->store->failed([$ids[1]], 'payload_invalid');
        $this->store->release([$ids[2]], 'network_error');

        self::assertSame(['pending' => 1, 'failed' => 1], $this->store->counts());
        self::assertSame([], $this->store->unfinished(10));
        self::assertSame((string) $ids[2], $this->store->pending(10)[0]['id_outbox']);
        self::assertSame('1', $this->store->pending(10)[0]['attempts']);
    }

    public function test_a_row_fails_after_the_last_attempt(): void
    {
        $this->recorder()->record(new Change('brand.updated', '1'));
        $id = (int) $this->store->pending(1)[0]['id_outbox'];
        for ($attempt = 1; $attempt <= OutboxStore::MAX_ATTEMPTS; $attempt++) {
            self::assertSame(['pending' => 1, 'failed' => 0], $this->store->counts(), 'attempt '.$attempt);
            $this->store->claim([$id], 'b'.$attempt);
            $this->store->release([$id], 'module_error');
        }

        self::assertSame(['pending' => 0, 'failed' => 1], $this->store->counts());
    }

    public function test_above_the_cap_older_rows_of_an_entity_go_then_everything_with_an_overflow(): void
    {
        $recorder = $this->recorder();
        foreach ([1, 2, 1, 1, 3, 2] as $brand) {
            $recorder->record(new Change('brand.updated', (string) $brand));
        }

        self::assertNull($this->store->bound(3), 'three brands fit after the merge');
        self::assertSame(['brand.updated:1', 'brand.updated:3', 'brand.updated:2'], array_column($this->store->pending(10), 'entity_key'));

        $overflow = $this->store->bound(2);
        self::assertSame(['merged' => 3, 'since' => '2026-09-30 12:40:00.250'], $overflow);
        self::assertSame(0, $this->store->pendingCount());
    }

    public function test_the_overflow_row_is_in_before_any_pending_row_goes_and_module_rows_stay(): void
    {
        $recorder = $this->recorder();
        $this->store->insert(new Change('module.outbox_overflow', null, ['merged' => 0, 'since' => '2026-09-30T12:00:00Z']), '2026-09-30 12:39:00.000');
        foreach ([1, 2, 3] as $brand) {
            $recorder->record(new Change('brand.updated', (string) $brand));
        }
        $seen = null;

        $overflow = $this->store->bound(2, function (int $merged, string $since) use (&$seen): void {
            // What the row says, recorded while every change is still there.
            $seen = [$merged, $since, $this->store->pendingCount()];
            $this->store->insert(new Change('module.outbox_overflow', null, ['merged' => $merged, 'since' => $since]), '2026-09-30 12:41:00.000');
        });

        self::assertSame([3, '2026-09-30 12:40:00.250', 4], $seen);
        self::assertSame(['merged' => 3, 'since' => '2026-09-30 12:40:00.250'], $overflow);
        self::assertSame(['module.outbox_overflow', 'module.outbox_overflow'], array_column($this->store->pending(10), 'type'), 'the earlier overflow keeps its since; the new row stays');
    }

    public function test_pull_lists_pending_and_failed_rows_and_ack_marks_them_sent(): void
    {
        $recorder = $this->recorder();
        foreach (['1', '2', '3'] as $id) {
            $recorder->record(new Change('brand.updated', $id));
        }
        $rows = $this->store->pending(10);
        $this->store->claim([(int) $rows[1]['id_outbox']], 'b1');
        $this->store->failed([(int) $rows[1]['id_outbox']], 'payload_invalid');

        self::assertCount(3, $this->store->pull(0, 10));
        self::assertCount(1, $this->store->pull((int) $rows[1]['id_outbox'], 10));

        self::assertSame(2, $this->store->ack([strtoupper((string) $rows[0]['event_id']), (string) $rows[1]['event_id'], '0192b2e4-0000-7000-8000-000000000000'], '2026-09-30 12:41:00'));
        self::assertSame(0, $this->store->ack([(string) $rows[0]['event_id']], '2026-09-30 12:42:00'), 'acknowledging twice is harmless');
        self::assertSame(['pending' => 1, 'failed' => 0], $this->store->counts());
    }

    public function test_prune_drops_old_sent_and_failed_rows(): void
    {
        $this->recorder()->record(new Change('brand.updated', '1'));
        $this->recorder()->record(new Change('brand.updated', '2'));
        $rows = $this->store->pending(10);
        $this->store->sent([(int) $rows[0]['id_outbox']], '2026-09-30 12:41:00');

        $this->store->prune('2026-09-30 12:40:00', '2026-09-30 12:40:00');
        self::assertSame(1, (int) $this->db->rows('SELECT COUNT(*) AS n FROM ps_kx_outbox WHERE status = \'sent\'')[0]['n'], 'a recent row stays');
        $this->store->prune('2026-10-07 12:40:00', '2026-09-30 12:40:00');
        self::assertSame(0, (int) $this->db->rows('SELECT COUNT(*) AS n FROM ps_kx_outbox WHERE status = \'sent\'')[0]['n']);
        self::assertSame(1, $this->store->pendingCount());
    }

    private function recorder(): Recorder
    {
        return new Recorder($this->db, $this->settings, $this->clock);
    }

    private function events(): Events
    {
        return new Events(new CatalogEnricher($this->db, $this->db->scope(), $this->db));
    }
}
