<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Write;

use Kronixon\Connect\Api\Budget;
use Kronixon\Connect\Api\Request;
use Kronixon\Connect\Support\Log;
use Kronixon\Connect\Tests\Support\CatalogDb;
use Kronixon\Connect\Tests\Support\FixedClock;
use Kronixon\Connect\Tests\Support\ProtocolSchemas;
use Kronixon\Connect\Write\PendingWrites;
use Kronixon\Connect\Write\StockBulk;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/Support/prestashop-stubs.php';

/**
 * `POST stock/bulk`: quantities through StockAvailable (safe) or directly (fast), `expected_quantity`
 * conflicts, the product's total and the visibility policies.
 */
final class StockBulkTest extends TestCase
{
    /** @var CatalogDb */
    private $db;

    /** @var list<string> */
    private $calls = [];

    /** @var list<string> */
    private $hooks = [];

    protected function setUp(): void
    {
        $this->db = new CatalogDb();
        $this->db->product(1, [], [], 5);
        $this->db->product(2, [], [], 3);
        foreach ([7 => 1, 8 => 2] as $variant => $quantity) {
            $this->db->insert('product_attribute', ['id_product_attribute' => $variant, 'id_product' => 2]);
            $this->db->insert('product_attribute_shop', ['id_product_attribute' => $variant, 'id_product' => 2, 'id_shop' => 1]);
            $this->db->insert('stock_available', ['id_product' => 2, 'id_product_attribute' => $variant, 'quantity' => $quantity]);
        }
    }

    public function test_safe_mode_goes_through_stock_available_and_the_answer_matches_the_schema(): void
    {
        $data = $this->send([
            ['product_id' => '1', 'variant_id' => '0', 'quantity' => 9, 'location' => 'default', 'expected_quantity' => 5],
            ['product_id' => '2', 'variant_id' => '7', 'quantity' => 4, 'out_of_stock' => 1, 'location' => 'shelf-2', 'available_now' => ['en' => 'Now']],
            ['product_id' => '2', 'variant_id' => '8', 'quantity' => 2, 'shop_id' => 1],
        ]);

        self::assertSame([], ProtocolSchemas::errors('stock-bulk.json', $data));
        self::assertSame([['ok', 'updated', 5, []], ['ok', 'updated', 1, ['location_ignored', 'available_texts_ignored']], ['ok', 'unchanged', 2, []]], self::results($data));
        self::assertSame(['set 1:0=9@1', 'set 2:7=4@1', 'out 2:7=1@1'], $this->calls);
        self::assertSame(['actionKxBulkStockUpdated'], $this->hooks);
    }

    public function test_a_quantity_changed_in_the_shop_is_a_conflict_and_a_repeat_is_unchanged(): void
    {
        $conflict = $this->send([['product_id' => '1', 'variant_id' => '0', 'quantity' => 9, 'expected_quantity' => 4]]);
        self::assertSame([['conflict', null, 5, []]], self::results($conflict));
        self::assertSame([5, 'quantity_changed'], [$conflict['results'][0]['current_quantity'], $conflict['results'][0]['code']]);
        self::assertSame([], $this->calls);

        $this->send([['product_id' => '1', 'variant_id' => '0', 'quantity' => 9, 'expected_quantity' => 5]]);
        $again = $this->send([['product_id' => '1', 'variant_id' => '0', 'quantity' => 9, 'expected_quantity' => 5]]);
        self::assertSame([['ok', 'unchanged', 9, []]], self::results($again), 'the shop holds what was sent: no conflict');
    }

    public function test_rows_the_shop_cannot_take_are_refused(): void
    {
        $data = $this->send([
            ['product_id' => '2', 'variant_id' => '0', 'quantity' => 1],
            ['product_id' => '1', 'variant_id' => '8', 'quantity' => 1],
            ['product_id' => '1', 'variant_id' => '0', 'quantity' => 1, 'shop_id' => 2],
            ['product_id' => '1', 'variant_id' => '0', 'quantity' => '1'],
            ['product_id' => '3', 'variant_id' => '0', 'quantity' => 1],
        ]);

        self::assertSame(['validation_failed', 'not_found', 'shop_mismatch', 'validation_failed', 'product_not_found'], array_column($data['results'], 'code'));
        self::assertSame([], $this->calls);
        self::assertSame([], ProtocolSchemas::errors('stock-bulk.json', $data));
    }

    public function test_fast_mode_writes_the_rows_and_the_products_total(): void
    {
        $data = $this->send([['product_id' => '2', 'variant_id' => '7', 'quantity' => 10], ['product_id' => '1', 'variant_id' => '0', 'quantity' => -2]], ['mode' => 'fast', 'fire_hooks' => true]);

        self::assertSame([['ok', 'updated', 1, []], ['ok', 'updated', 5, []]], self::results($data));
        self::assertSame([], $this->calls);
        self::assertSame(['10', '12', '-2'], array_column($this->db->rows('SELECT quantity FROM ps_stock_available WHERE (id_product = 2 AND id_product_attribute IN (7, 0)) OR id_product = 1 ORDER BY id_product DESC, id_product_attribute DESC'), 'quantity'));
        self::assertSame('10', $this->db->rows('SELECT physical_quantity FROM ps_stock_available WHERE id_product_attribute = 7')[0]['physical_quantity']);
        self::assertSame(['actionUpdateQuantity', 'actionUpdateQuantity', 'actionKxBulkStockUpdated'], $this->hooks);
    }

    public function test_restore_if_hidden_shows_a_product_with_stock_again(): void
    {
        $this->db->pdo->exec("UPDATE ps_product_shop SET visibility = 'none' WHERE id_product = 1");
        $data = $this->send([['product_id' => '1', 'variant_id' => '0', 'quantity' => 3]], ['visibility_policy' => 'restore_if_hidden']);

        self::assertSame(['visibility_restored'], $data['results'][0]['warnings']);
        self::assertSame(['both', '0'], array_values($this->db->rows('SELECT visibility, indexed FROM ps_product_shop WHERE id_product = 1')[0]));
    }

    public function test_hide_when_zero_hides_only_linked_products_and_shows_only_those_it_hid(): void
    {
        $this->db->insert('kx_entity_map', ['entity_type' => 'product', 'external_id' => '1', 'kx_id' => '0192b2e4-2f7a-7c5b-8a1d-3e4f5a6b7c8d', 'flags' => 0]);
        $data = $this->send([['product_id' => '1', 'variant_id' => '0', 'quantity' => 0], ['product_id' => '2', 'variant_id' => '7', 'quantity' => 0], ['product_id' => '2', 'variant_id' => '8', 'quantity' => 0]], ['visibility_policy' => 'hide_when_zero']);

        self::assertSame([['visibility_hidden'], ['not_hidden_unlinked'], ['not_hidden_unlinked']], array_column($data['results'], 'warnings'));
        self::assertSame(['none', 'both'], array_column($this->db->rows('SELECT visibility FROM ps_product_shop ORDER BY id_product'), 'visibility'));
        self::assertSame('1', $this->db->rows('SELECT flags FROM ps_kx_entity_map')[0]['flags']);

        $this->db->pdo->exec("UPDATE ps_product_shop SET visibility = 'none' WHERE id_product = 2");
        $back = $this->send([['product_id' => '1', 'variant_id' => '0', 'quantity' => 4], ['product_id' => '2', 'variant_id' => '7', 'quantity' => 4]], ['visibility_policy' => 'hide_when_zero']);
        self::assertSame([['visibility_restored'], []], array_column($back['results'], 'warnings'));
        self::assertSame(['both', 'none'], array_column($this->db->rows('SELECT visibility FROM ps_product_shop ORDER BY id_product'), 'visibility'), 'the one hidden by hand stays hidden');
        self::assertSame('0', $this->db->rows('SELECT flags FROM ps_kx_entity_map')[0]['flags']);
    }

    public function test_a_failed_totals_or_visibility_step_is_a_row_warning_and_a_repeat_sets_them(): void
    {
        $this->db->insert('kx_entity_map', ['entity_type' => 'product', 'external_id' => '1', 'kx_id' => '0192b2e4-2f7a-7c5b-8a1d-3e4f5a6b7c8d', 'flags' => 0]);
        $this->db->pdo->exec("CREATE TRIGGER kx_refuse BEFORE UPDATE OF quantity ON ps_stock_available WHEN NEW.id_product_attribute = 0 AND NEW.id_product = 2 BEGIN SELECT RAISE(ABORT, 'refused'); END");
        $data = $this->send([['product_id' => '2', 'variant_id' => '7', 'quantity' => 10], ['product_id' => '1', 'variant_id' => '0', 'quantity' => 0]], ['mode' => 'fast', 'visibility_policy' => 'hide_when_zero']);

        self::assertSame([['ok', 'updated', 1, ['totals_not_updated']], ['ok', 'updated', 5, ['totals_not_updated']]], self::results($data), 'the step is one transaction: the visibility of product 1 too');
        self::assertSame(['10', '3'], array_column($this->db->rows('SELECT quantity FROM ps_stock_available WHERE id_product = 2 AND id_product_attribute IN (7, 0) ORDER BY id_product_attribute DESC'), 'quantity'));
        self::assertSame([], ProtocolSchemas::errors('stock-bulk.json', $data));

        $this->db->pdo->exec('DROP TRIGGER kx_refuse');
        $again = $this->send([['product_id' => '2', 'variant_id' => '7', 'quantity' => 10], ['product_id' => '1', 'variant_id' => '0', 'quantity' => 0]], ['mode' => 'fast', 'visibility_policy' => 'hide_when_zero']);
        self::assertSame([['ok', 'unchanged', 10, []], ['ok', 'unchanged', 0, ['visibility_hidden']]], self::results($again));
        self::assertSame('12', $this->db->rows('SELECT quantity FROM ps_stock_available WHERE id_product = 2 AND id_product_attribute = 0')[0]['quantity']);
    }

    public function test_the_chunk_locks_its_stock_rows_first_by_id_inside_its_transaction(): void
    {
        $this->db->log = [];
        $this->send([['product_id' => '2', 'variant_id' => '7', 'quantity' => 6], ['product_id' => '1', 'variant_id' => '0', 'quantity' => 6]]);

        self::assertCount(1, $this->db->locks);
        self::assertStringContainsString('ORDER BY sa.id_stock_available', $this->db->locks[0][0]);
        $catalog = array_values(array_filter($this->db->log, static function (string $sql): bool {
            return strpos($sql, 'kx_pending') === false;
        }));
        self::assertSame($this->db->locks[0][0], $catalog[0], 'no read of the catalog before the lock starts the snapshot');
    }

    public function test_a_quantity_another_session_commits_while_the_chunk_waits_is_a_conflict_in_both_modes(): void
    {
        foreach (['safe', 'fast'] as $mode) {
            $this->db->pdo->exec('UPDATE ps_stock_available SET quantity = 5 WHERE id_product = 1');
            // An order of one, committed after the chunk began and before its lock was granted.
            $this->db->onLock = static function (CatalogDb $db): void {
                $db->pdo->exec('UPDATE ps_stock_available SET quantity = quantity - 1 WHERE id_product = 1');
                $db->onLock = null;
            };
            $data = $this->send([['product_id' => '1', 'variant_id' => '0', 'quantity' => 8, 'expected_quantity' => 5]], ['mode' => $mode]);
            self::assertSame([['conflict', null, 4, []]], self::results($data), $mode);
            self::assertSame(4, $data['results'][0]['current_quantity']);
            self::assertSame('4', $this->db->rows('SELECT quantity FROM ps_stock_available WHERE id_product = 1')[0]['quantity'], $mode.': nothing written');

            // Without expected_quantity the write lands after the order: the platform's value wins.
            $this->db->onLock = static function (CatalogDb $db): void {
                $db->pdo->exec('UPDATE ps_stock_available SET quantity = quantity - 1 WHERE id_product = 1');
                $db->onLock = null;
            };
            $plain = $this->send([['product_id' => '1', 'variant_id' => '0', 'quantity' => 8]], ['mode' => $mode]);
            self::assertSame([['ok', 'updated', 3, []]], self::results($plain), $mode);
            self::assertSame('8', $this->db->rows('SELECT quantity FROM ps_stock_available WHERE id_product = 1')[0]['quantity']);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function send(array $rows, array $options = []): array
    {
        $this->calls = [];
        $this->hooks = [];
        $request = Request::fromServer(['REQUEST_METHOD' => 'POST', 'QUERY_STRING' => 'path=stock/bulk'], (string) json_encode(['rows' => $rows] + ($options === [] ? [] : ['options' => $options])));
        $log = new Log(static function (): void {
        });
        $hook = function (string $name): void {
            $this->hooks[] = $name;
        };
        $bulk = new StockBulk($this->db, $this->db->scope(), new PendingWrites($this->db, 1, $log, $hook), $log, true, $hook, function (int $product, int $variant, int $quantity, int $shop): void {
            // As StockAvailable::setQuantity(): the row, then the product's total of its combinations.
            $this->calls[] = 'set '.$product.':'.$variant.'='.$quantity.'@'.$shop;
            $this->db->pdo->exec('UPDATE ps_stock_available SET quantity = '.$quantity.' WHERE id_product = '.$product.' AND id_product_attribute = '.$variant);
            if ($variant !== 0) {
                $this->db->pdo->exec('UPDATE ps_stock_available SET quantity = (SELECT SUM(quantity) FROM ps_stock_available WHERE id_product = '.$product.' AND id_product_attribute <> 0) WHERE id_product = '.$product.' AND id_product_attribute = 0');
            }
        }, function (int $product, int $policy, int $shop, int $variant): void {
            $this->calls[] = 'out '.$product.':'.$variant.'='.$policy.'@'.$shop;
        }, static function (): void {
        });
        $response = $bulk->run($request, new Budget(new FixedClock(1790772000.0), 25));
        self::assertSame(200, $response->status());

        return (array) json_decode($response->body(), true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{0: string, 1: string|null, 2: int|null, 3: list<string>}>
     */
    private static function results(array $data): array
    {
        return array_map(static function (array $result): array {
            return [$result['status'], $result['action'] ?? null, $result['previous_quantity'] ?? null, $result['warnings'] ?? []];
        }, $data['results']);
    }
}
