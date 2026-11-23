<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Outbox;

use Kronixon\Connect\Api\Budget;
use Kronixon\Connect\Api\Response;
use Kronixon\Connect\Outbox\CatalogEnricher;
use Kronixon\Connect\Outbox\Change;
use Kronixon\Connect\Outbox\Events;
use Kronixon\Connect\Outbox\Flusher;
use Kronixon\Connect\Outbox\Heartbeat;
use Kronixon\Connect\Outbox\OutboxStore;
use Kronixon\Connect\Outbox\Recorder;
use Kronixon\Connect\Platform\PlatformError;
use Kronixon\Connect\Tests\Support\CatalogDb;
use Kronixon\Connect\Tests\Support\FixedClock;
use Kronixon\Connect\Tests\Support\MemorySettings;
use Kronixon\Connect\Tests\Support\ProtocolSchemas;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Delivery of the outbox: batches, the platform's answers, waiting, a batch cut short, the limits, and
 * what the flush records itself (gap, overflow, heartbeat).
 */
final class FlusherTest extends TestCase
{
    /** @var CatalogDb */
    private $db;

    /** @var MemorySettings */
    private $settings;

    /** @var FixedClock */
    private $clock;

    /** @var OutboxStore */
    private $store;

    /** @var list<array<string, mixed>> request bodies the fake platform got */
    private $requests = [];

    /** @var list<string> the same bodies as JSON */
    private $raw = [];

    /** @var callable(array<string, mixed>): array<string, mixed> the fake platform's answer */
    private $answer;

    protected function setUp(): void
    {
        $this->db = new CatalogDb();
        $this->db->demo();
        $this->settings = new MemorySettings();
        $this->clock = new FixedClock(1790772000.0);
        $this->store = new OutboxStore($this->db);
        // A heartbeat is not due unless a test asks for it.
        $this->settings->set(Flusher::HEARTBEAT_AT, '1790772000');
        $this->answer = static function (array $body): array {
            return ['accepted' => array_column($body['events'], 'event_id'), 'duplicates' => [], 'rejected' => []];
        };
    }

    public function test_changes_go_as_one_signed_batch_and_their_rows_are_sent(): void
    {
        $recorder = $this->recorder();
        for ($i = 0; $i < 5; $i++) {
            $recorder->record(new Change('product.updated', '1'));
        }
        $recorder->record(new Change('stock.updated', '1', ['variant_id' => '0', 'quantity' => 3, 'previous_quantity' => 4], '0'));

        $result = $this->flusher()->run($this->budget());

        self::assertSame(['sent' => 2, 'failed' => 0, 'batches' => 1, 'state' => 'done'], $result);
        self::assertCount(1, $this->requests);
        self::assertSame([], ProtocolSchemas::platformRequestErrors('store-events.json', $this->raw[0]));
        self::assertSame(['product.updated', 'stock.updated'], array_column($this->requests[0]['events'], 'type'));
        self::assertSame(['pending' => 0, 'failed' => 0], $this->store->counts());
        self::assertSame('1790772000', $this->settings->get(Flusher::LAST_SENT));
        self::assertSame(['sent' => 0, 'failed' => 0, 'batches' => 0, 'state' => 'done'], $this->flusher()->run($this->budget()), 'nothing twice');
    }

    public function test_retry_after_is_waited_for_then_the_same_rows_go(): void
    {
        $this->recorder()->record(new Change('brand.updated', '12'));
        $this->answer = static function (): array {
            throw new PlatformError(503, 'maintenance', [], 120);
        };

        self::assertSame('waiting', $this->flusher()->run($this->budget())['state']);
        self::assertSame('1790772120', $this->settings->get(Flusher::RETRY_AT));
        self::assertSame(['pending' => 1, 'failed' => 0], $this->store->counts(), 'the row waits');
        self::assertTrue(Flusher::waiting($this->settings, 1790772119));

        $this->clock->advance(60);
        self::assertSame(['sent' => 0, 'failed' => 0, 'batches' => 0, 'state' => 'waiting'], $this->flusher()->run($this->budget()));
        self::assertCount(1, $this->requests, 'no request while waiting');

        $this->clock->advance(61);
        $this->answer = static function (array $body): array {
            return ['accepted' => [], 'duplicates' => array_column($body['events'], 'event_id'), 'rejected' => []];
        };
        self::assertSame(1, $this->flusher()->run($this->budget())['sent']);
        self::assertSame($this->requests[0]['events'][0]['event_id'], $this->requests[1]['events'][0]['event_id'], 'the same event');
        self::assertSame('0', $this->settings->get(Flusher::FAILURES));
    }

    public function test_without_retry_after_the_wait_doubles_up_to_six_hours(): void
    {
        $this->recorder()->record(new Change('brand.updated', '12'));
        $this->answer = static function (): array {
            throw new PlatformError(0, PlatformError::NETWORK);
        };
        $waits = [];
        for ($i = 0; $i < 10; $i++) {
            $this->flusher()->run($this->budget());
            $wait = (int) $this->settings->get(Flusher::RETRY_AT) - (int) $this->clock->now();
            $waits[] = $wait;
            $this->clock->advance($wait);
        }

        self::assertSame([60, 120, 240, 480, 960, 1920, 3840, 7680, 15360, 21600], $waits);
        self::assertSame(['pending' => 0, 'failed' => 1], $this->store->counts(), 'failed after the tenth attempt');
    }

    public function test_a_payload_error_fails_the_rows_and_does_not_wait(): void
    {
        $this->recorder()->record(new Change('brand.updated', '12'));
        $this->answer = static function (): array {
            throw new PlatformError(422, 'validation_failed');
        };

        self::assertSame(['sent' => 0, 'failed' => 1, 'batches' => 1, 'state' => 'done'], $this->flusher()->run($this->budget()));
        self::assertSame(['pending' => 0, 'failed' => 1], $this->store->counts());
        self::assertNull($this->settings->get(Flusher::RETRY_AT));
    }

    public function test_rejected_events_fail_and_events_missing_from_the_answer_go_again(): void
    {
        $recorder = $this->recorder();
        foreach (['1', '2', '3'] as $brand) {
            $recorder->record(new Change('brand.updated', $brand));
        }
        $this->answer = static function (array $body): array {
            $ids = array_column($body['events'], 'event_id');

            return ['accepted' => [$ids[0]], 'duplicates' => [], 'rejected' => [['event_id' => $ids[1], 'code' => 'unknown_type']]];
        };

        $result = $this->flusher()->run($this->budget());

        self::assertSame(1, $result['sent']);
        self::assertSame(1, $result['failed']);
        self::assertSame(['pending' => 1, 'failed' => 1], $this->store->counts());
        self::assertSame('rejected: unknown_type', $this->db->rows("SELECT last_error FROM ps_kx_outbox WHERE status = 'failed'")[0]['last_error']);
    }

    public function test_a_batch_cut_short_goes_again_with_its_batch_id_and_event_ids(): void
    {
        $recorder = $this->recorder();
        $recorder->record(new Change('brand.updated', '1'));
        $recorder->record(new Change('brand.updated', '1'));
        // The process that claimed the rows died before the answer.
        $this->store->claim(array_map('intval', array_column($this->store->pending(10), 'id_outbox')), '0192b2e4-9b3c-7d4e-9f50-6b7c8d9e0f1a');
        $recorder->record(new Change('brand.updated', '1'));

        $this->flusher()->run($this->budget());

        self::assertCount(2, $this->requests);
        self::assertSame('0192b2e4-9b3c-7d4e-9f50-6b7c8d9e0f1a', $this->requests[0]['batch_id'], 'the batch first, as it was');
        self::assertCount(1, $this->requests[0]['events']);
        self::assertNotSame('0192b2e4-9b3c-7d4e-9f50-6b7c8d9e0f1a', $this->requests[1]['batch_id'], 'the later change in a batch of its own');
        self::assertSame(['pending' => 0, 'failed' => 0], $this->store->counts());
    }

    public function test_a_batch_has_at_most_200_entities_and_1_mb(): void
    {
        $recorder = $this->recorder();
        for ($i = 1; $i <= 250; $i++) {
            $recorder->record(new Change('brand.updated', (string) $i));
        }
        $this->flusher()->run($this->budget());
        self::assertSame([200, 50], array_map(static function (array $request): int {
            return count($request['events']);
        }, $this->requests));

        $this->requests = [];
        for ($i = 1; $i <= 150; $i++) {
            $this->store->insert(new Change('category.updated', (string) $i, ['parent_id' => '2', 'pad' => str_repeat('x', 10000)]), '2026-09-30 12:40:00.000');
        }
        $this->flusher()->run($this->budget());
        $sizes = array_map(static function (array $request): int {
            return strlen((string) json_encode($request, Response::JSON_FLAGS));
        }, $this->requests);
        self::assertGreaterThan(1, count($sizes));
        self::assertLessThanOrEqual(Flusher::MAX_BYTES, max($sizes));
        self::assertSame(150, array_sum(array_map(static function (array $request): int {
            return count($request['events']);
        }, $this->requests)));
        self::assertSame('1', $this->db->rows('SELECT MAX(attempts) AS n FROM ps_kx_outbox')[0]['n'], 'a row left out of a batch does not count the attempt');
    }

    public function test_a_gap_and_the_daily_heartbeat_are_recorded_before_the_changes(): void
    {
        $this->settings->set(Recorder::GAP, '1790771000');
        $this->settings->delete(Flusher::HEARTBEAT_AT);
        $this->recorder()->record(new Change('brand.updated', '12'));

        $this->flusher()->run($this->budget());

        $events = $this->requests[0]['events'];
        self::assertSame(['brand.updated', 'module.outbox_overflow', 'module.heartbeat'], array_column($events, 'type'));
        self::assertSame(['merged' => 0, 'since' => '2026-09-30T12:23:20Z'], (array) $events[1]['payload']);
        self::assertSame(['products' => 2], $events[2]['payload']['counts']);
        self::assertSame([], ProtocolSchemas::platformRequestErrors('store-events.json', $this->raw[0]));
        self::assertNull($this->settings->get(Recorder::GAP));

        $this->clock->advance(3600);
        $this->flusher()->run($this->budget());
        self::assertCount(1, $this->requests, 'one heartbeat a day');
    }

    public function test_a_failure_of_the_transport_is_a_network_error(): void
    {
        $this->recorder()->record(new Change('brand.updated', '12'));
        $this->answer = static function (): array {
            throw new RuntimeException('curl');
        };

        self::assertSame('waiting', $this->flusher()->run($this->budget())['state']);
        self::assertSame(['pending' => 1, 'failed' => 0], $this->store->counts());
        self::assertStringEndsWith('0 network_error', (string) $this->settings->get(Flusher::LAST_ERROR));
    }

    private function recorder(): Recorder
    {
        return new Recorder($this->db, $this->settings, $this->clock);
    }

    private function budget(): Budget
    {
        return new Budget($this->clock, 25);
    }

    private function flusher(): Flusher
    {
        $heartbeat = new Heartbeat(static function (): array {
            return ['protocol' => 'v1'];
        }, static function (): array {
            return ['products' => 2];
        }, static function (): array {
            return ['count' => 0, 'last' => []];
        }, $this->store, $this->settings, $this->clock, '9.1.5');

        return new Flusher($this->store, new Events(new CatalogEnricher($this->db, $this->db->scope(), $this->db)), function (array $body, int $timeout): array {
            $this->raw[] = (string) json_encode($body, Response::JSON_FLAGS);
            $this->requests[] = json_decode($this->raw[count($this->raw) - 1], true);

            return ($this->answer)($body);
        }, $this->settings, $this->clock, [$heartbeat, 'payload']);
    }
}
