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
use RuntimeException;

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
    private $hooks = [];

    /** @var list<array{0: string, 1: array<string, mixed>, 2: bool}> each hook: name, parameters (the object as `object #<id>`), run inside a transaction */
    private $seen = [];

    /** @var list<string> the messages logged */
    private $logged = [];

    /** @var bool the shop is 8.1+: `actionUpdateQuantity` carries `delta_quantity` and `id_shop` */
    private $hookShop = true;

    /** @var string|null a hook that throws */
    private $throwing;

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

    public function test_safe_mode_writes_what_set_quantity_writes_and_runs_its_hooks_after_the_commit(): void
    {
        $this->db->pdo->exec('UPDATE ps_stock_available SET physical_quantity = 50, reserved_quantity = 1');
        $data = $this->send([
            ['product_id' => '1', 'variant_id' => '0', 'quantity' => 9, 'location' => 'default', 'expected_quantity' => 5],
            ['product_id' => '2', 'variant_id' => '7', 'quantity' => 4, 'out_of_stock' => 1, 'location' => 'shelf-2', 'available_now' => ['en' => 'Now']],
            ['product_id' => '2', 'variant_id' => '8', 'quantity' => 2, 'shop_id' => 1],
        ]);

        self::assertSame([], ProtocolSchemas::errors('stock-bulk.json', $data));
        self::assertSame([['ok', 'updated', 5, []], ['ok', 'updated', 1, ['location_ignored', 'available_texts_ignored']], ['ok', 'unchanged', 2, []]], self::results($data));
        self::assertSame(['1', '2'], array_column($this->db->rows('SELECT out_of_stock FROM ps_stock_available WHERE id_product = 2 AND id_product_attribute IN (7, 8) ORDER BY id_product_attribute'), 'out_of_stock'), 'the policy of the row sent, by primary key');
        self::assertSame([['9', '50', '1'], ['6', '50', '1'], ['4', '50', '1']], array_map('array_values', $this->db->rows('SELECT quantity, physical_quantity, reserved_quantity FROM ps_stock_available WHERE id_product_attribute IN (0, 7) ORDER BY id_stock_available')), 'the quantity alone, and the total 4 + 2, as setQuantity() writes them');

        $object = ['actionObjectUpdateBefore', 'actionObjectStockAvailableUpdateBefore', 'actionObjectUpdateAfter', 'actionObjectStockAvailableUpdateAfter'];
        self::assertSame(array_merge($object, ['actionUpdateQuantity'], $object, ['actionUpdateQuantity'], $object, ['actionUpdateQuantity'], ['actionKxBulkStockUpdated']), $this->hooks, 'each changed row, then the total of product 2; not the unchanged row');
        self::assertSame([false], array_values(array_unique(array_column($this->seen, 2))), 'every hook after the commit');
        $quantities = array_values(array_filter($this->seen, static function (array $hook): bool {
            return $hook[0] === 'actionUpdateQuantity';
        }));
        self::assertSame([
            ['id_product' => 1, 'id_product_attribute' => 0, 'quantity' => 9, 'delta_quantity' => 4, 'id_shop' => 1],
            ['id_product' => 2, 'id_product_attribute' => 7, 'quantity' => 4, 'delta_quantity' => 3, 'id_shop' => 1],
            ['id_product' => 2, 'id_product_attribute' => 0, 'quantity' => 6, 'delta_quantity' => 3, 'id_shop' => 1],
        ], array_column($quantities, 1));
        self::assertSame(['object' => 'object #1'], $this->seen[0][1], 'the StockAvailable of the row, read after the commit');
    }

    public function test_before_8_1_action_update_quantity_has_the_parameters_of_that_version(): void
    {
        $this->hookShop = false;
        $this->send([['product_id' => '1', 'variant_id' => '0', 'quantity' => 9]]);

        self::assertSame(['id_product' => 1, 'id_product_attribute' => 0, 'quantity' => 9], $this->seen[4][1]);
    }

    public function test_a_hook_that_throws_after_the_commit_is_logged_and_the_rows_stay_written(): void
    {
        $this->throwing = 'actionObjectStockAvailableUpdateAfter';
        $data = $this->send([['product_id' => '1', 'variant_id' => '0', 'quantity' => 9]]);

        self::assertSame([['ok', 'updated', 5, []]], self::results($data));
        self::assertSame('9', $this->db->rows('SELECT quantity FROM ps_stock_available WHERE id_product = 1')[0]['quantity']);
        self::assertContains('A hook after a bulk write failed', $this->logged);
        self::assertContains('actionUpdateQuantity', $this->hooks, 'the hooks after it still run');
    }

    public function test_a_quantity_changed_in_the_shop_is_a_conflict_and_a_repeat_is_unchanged(): void
    {
        $conflict = $this->send([['product_id' => '1', 'variant_id' => '0', 'quantity' => 9, 'expected_quantity' => 4]]);
        self::assertSame([['conflict', null, 5, []]], self::results($conflict));
        self::assertSame([5, 'quantity_changed'], [$conflict['results'][0]['current_quantity'], $conflict['results'][0]['code']]);
        self::assertSame([], $this->hooks);
        self::assertSame('5', $this->db->rows('SELECT quantity FROM ps_stock_available WHERE id_product = 1')[0]['quantity']);

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
        self::assertSame([], $this->hooks);
        self::assertSame([], $this->db->bulks);
        self::assertSame([], ProtocolSchemas::errors('stock-bulk.json', $data));
    }

    public function test_fast_mode_writes_the_rows_and_the_products_total(): void
    {
        $data = $this->send([['product_id' => '2', 'variant_id' => '7', 'quantity' => 10], ['product_id' => '1', 'variant_id' => '0', 'quantity' => -2]], ['mode' => 'fast', 'fire_hooks' => true]);

        self::assertSame([['ok', 'updated', 1, []], ['ok', 'updated', 5, []]], self::results($data));
        self::assertSame(['10', '12', '-2'], array_column($this->db->rows('SELECT quantity FROM ps_stock_available WHERE (id_product = 2 AND id_product_attribute IN (7, 0)) OR id_product = 1 ORDER BY id_product DESC, id_product_attribute DESC'), 'quantity'));
        self::assertSame('10', $this->db->rows('SELECT physical_quantity FROM ps_stock_available WHERE id_product_attribute = 7')[0]['physical_quantity']);
        self::assertSame(['actionUpdateQuantity', 'actionUpdateQuantity', 'actionKxBulkStockUpdated'], $this->hooks, 'per row sent, not for the total');
        self::assertSame([false], array_values(array_unique(array_column($this->seen, 2))), 'after the commit');

        $this->send([['product_id' => '1', 'variant_id' => '0', 'quantity' => 3]], ['mode' => 'fast']);
        self::assertSame(['actionKxBulkStockUpdated'], $this->hooks, 'without fire_hooks');
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

    public function test_hide_when_zero_restores_the_visibility_the_product_had(): void
    {
        $this->db->insert('kx_entity_map', ['entity_type' => 'product', 'external_id' => '1', 'kx_id' => '0192b2e4-2f7a-7c5b-8a1d-3e4f5a6b7c8d', 'flags' => 0]);
        foreach (['catalog' => '3', 'search' => '5', 'both' => '1'] as $visibility => $flags) {
            $this->db->pdo->exec("UPDATE ps_product_shop SET visibility = '".$visibility."' WHERE id_product = 1");
            $this->send([['product_id' => '1', 'variant_id' => '0', 'quantity' => 0]], ['visibility_policy' => 'hide_when_zero']);
            self::assertSame(['none', $flags], [$this->db->rows('SELECT visibility FROM ps_product_shop WHERE id_product = 1')[0]['visibility'], $this->db->rows('SELECT flags FROM ps_kx_entity_map')[0]['flags']]);

            $this->send([['product_id' => '1', 'variant_id' => '0', 'quantity' => 2]], ['visibility_policy' => 'hide_when_zero']);
            self::assertSame([$visibility, '0'], [$this->db->rows('SELECT visibility FROM ps_product_shop WHERE id_product = 1')[0]['visibility'], $this->db->rows('SELECT flags FROM ps_kx_entity_map')[0]['flags']]);
        }
    }

    public function test_a_failed_total_fails_the_whole_chunk_in_both_modes(): void
    {
        $this->db->pdo->exec("CREATE TRIGGER kx_refuse BEFORE UPDATE OF quantity ON ps_stock_available WHEN NEW.id_product_attribute = 0 AND NEW.id_product = 2 BEGIN SELECT RAISE(ABORT, 'refused'); END");
        foreach (['safe', 'fast'] as $mode) {
            $data = $this->send([['product_id' => '2', 'variant_id' => '7', 'quantity' => 10], ['product_id' => '1', 'variant_id' => '0', 'quantity' => 0]], ['mode' => $mode, 'fire_hooks' => true]);

            self::assertSame(['module_error', 'module_error'], array_column($data['results'], 'code'), $mode.': the total is in the chunk\'s transaction');
            self::assertSame(['5', '3', '1'], array_column($this->db->rows('SELECT quantity FROM ps_stock_available WHERE id_product_attribute IN (0, 7) ORDER BY id_stock_available'), 'quantity'), $mode.': nothing written');
            self::assertSame([], $this->hooks, $mode.': no hook for what was rolled back');
            self::assertSame([], ProtocolSchemas::errors('stock-bulk.json', $data));
        }
    }

    public function test_a_failed_visibility_step_is_a_row_warning_and_a_repeat_sets_it(): void
    {
        $this->db->insert('kx_entity_map', ['entity_type' => 'product', 'external_id' => '1', 'kx_id' => '0192b2e4-2f7a-7c5b-8a1d-3e4f5a6b7c8d', 'flags' => 0]);
        $this->db->pdo->exec("CREATE TRIGGER kx_refuse BEFORE UPDATE OF visibility ON ps_product_shop BEGIN SELECT RAISE(ABORT, 'refused'); END");
        $data = $this->send([['product_id' => '2', 'variant_id' => '7', 'quantity' => 10], ['product_id' => '1', 'variant_id' => '0', 'quantity' => 0]], ['mode' => 'fast', 'visibility_policy' => 'hide_when_zero']);

        self::assertSame([['ok', 'updated', 1, ['totals_not_updated']], ['ok', 'updated', 5, ['totals_not_updated']]], self::results($data), 'the step is one transaction for the chunk\'s products');
        self::assertSame(['10', '12', '0'], array_column($this->db->rows('SELECT quantity FROM ps_stock_available WHERE id_product_attribute IN (0, 7) ORDER BY id_product DESC, id_product_attribute DESC'), 'quantity'), 'the quantities and the total are committed');
        self::assertSame('both', $this->db->rows('SELECT visibility FROM ps_product_shop WHERE id_product = 1')[0]['visibility']);
        self::assertSame([], ProtocolSchemas::errors('stock-bulk.json', $data));

        $this->db->pdo->exec('DROP TRIGGER kx_refuse');
        $again = $this->send([['product_id' => '2', 'variant_id' => '7', 'quantity' => 10], ['product_id' => '1', 'variant_id' => '0', 'quantity' => 0]], ['mode' => 'fast', 'visibility_policy' => 'hide_when_zero']);
        self::assertSame([['ok', 'unchanged', 10, []], ['ok', 'unchanged', 0, ['visibility_hidden']]], self::results($again));
    }

    public function test_a_deadlock_after_a_write_rolls_the_chunk_back_and_runs_it_once_more(): void
    {
        // The second statement of the chunk (product 2's row, after product 1's) loses a deadlock.
        $this->db->bulkDeadlocks = [2];
        $data = $this->send([['product_id' => '1', 'variant_id' => '0', 'quantity' => 8, 'location' => 'shelf'], ['product_id' => '2', 'variant_id' => '7', 'quantity' => 6]]);

        self::assertSame([['ok', 'updated', 5, ['location_ignored']], ['ok', 'updated', 1, []]], self::results($data), 'the second run\'s answer, its warnings once');
        self::assertSame(['beginChunk', 'rollbackChunk', 'beginChunk', 'commitChunk'], $this->db->chunks);
        self::assertSame(['8', '8', '6'], array_column($this->db->rows('SELECT quantity FROM ps_stock_available WHERE id_product_attribute IN (0, 7) ORDER BY id_stock_available'), 'quantity'));
        self::assertSame(3, count(array_keys($this->hooks, 'actionUpdateQuantity', true)), 'the hooks of the committed run only: two rows and a total');

        // Rolled back on the second run too: nothing written, the rows are sent again.
        $this->db->chunks = [];
        $this->db->bulkDeadlocks = [count($this->db->bulks) + 1, count($this->db->bulks) + 2];
        $data = $this->send([['product_id' => '1', 'variant_id' => '0', 'quantity' => 1], ['product_id' => '2', 'variant_id' => '7', 'quantity' => 1]]);
        self::assertSame(['rows_locked', 'rows_locked'], array_column($data['results'], 'code'));
        self::assertTrue($data['continue']);
        self::assertSame(['8', '8', '6'], array_column($this->db->rows('SELECT quantity FROM ps_stock_available WHERE id_product_attribute IN (0, 7) ORDER BY id_stock_available'), 'quantity'), 'nothing partial');
        self::assertSame([], $this->hooks);
    }

    public function test_a_chunk_whose_marker_is_gone_at_the_commit_is_rolled_back_and_runs_once_more(): void
    {
        $this->db->lostChunks = 1;
        $data = $this->send([['product_id' => '1', 'variant_id' => '0', 'quantity' => 8]]);
        self::assertSame([['ok', 'updated', 5, []]], self::results($data));
        self::assertSame(['beginChunk', 'commitChunk', 'rollbackChunk', 'beginChunk', 'commitChunk'], $this->db->chunks);
        self::assertSame(1, count(array_keys($this->hooks, 'actionUpdateQuantity', true)));

        $this->db->lostChunks = 2;
        $data = $this->send([['product_id' => '1', 'variant_id' => '0', 'quantity' => 9]]);
        self::assertSame(['error', 'module_error'], [$data['results'][0]['status'], $data['results'][0]['code']], 'never ok for a chunk the server rolled back');
        self::assertSame('8', $this->db->rows('SELECT quantity FROM ps_stock_available WHERE id_product = 1')[0]['quantity']);
        self::assertSame([], $this->hooks);
        self::assertContains('A bulk chunk failed', $this->logged);
    }

    public function test_the_chunk_locks_exactly_its_stock_rows_by_primary_key_first_inside_its_transaction(): void
    {
        // Another product's stock row (id 5) between the chunk's own (1–4) and after them (6).
        $this->db->product(3, [], [], 7);
        $this->db->insert('stock_available', ['id_product' => 1, 'id_product_attribute' => 9, 'quantity' => 0]);
        $this->db->log = [];
        $this->send([['product_id' => '2', 'variant_id' => '7', 'quantity' => 6], ['product_id' => '1', 'variant_id' => '0', 'quantity' => 6]]);

        self::assertCount(1, $this->db->locks);
        self::assertStringContainsString('WHERE sa.id_stock_available IN (1,2,3,4,6) ORDER BY sa.id_stock_available', $this->db->locks[0][0]);
        self::assertStringNotContainsString('id_product IN', $this->db->locks[0][0], 'by primary key only: no plan that scans other rows');
        $outside = array_values(array_filter($this->db->outside, static function (string $sql): bool {
            return strpos($sql, 'kx_pending') === false;
        }));
        self::assertCount(1, $outside);
        self::assertStringStartsWith('SELECT sa.id_stock_available FROM', $outside[0], 'the ids are read before the transaction');
        $inside = array_values(array_diff($this->db->log, $this->db->outside));
        self::assertSame($this->db->locks[0][0], $inside[0], 'no read of the catalog before the lock starts the snapshot');
    }

    public function test_a_chunk_that_loses_a_deadlock_runs_once_more_and_then_is_skipped(): void
    {
        $this->db->deadlocks = 1;
        $data = $this->send([['product_id' => '1', 'variant_id' => '0', 'quantity' => 8]]);
        self::assertSame([['ok', 'updated', 5, []]], self::results($data));
        self::assertCount(2, $this->db->locks);
        self::assertSame('8', $this->db->rows('SELECT quantity FROM ps_stock_available WHERE id_product = 1')[0]['quantity']);

        $this->db->locks = [];
        $this->db->deadlocks = 2;
        $data = $this->send([['product_id' => '1', 'variant_id' => '0', 'quantity' => 9]]);
        self::assertSame(['skipped', 'rows_locked'], [$data['results'][0]['status'], $data['results'][0]['code']]);
        self::assertTrue($data['continue']);
        self::assertCount(2, $this->db->locks, 'once more, not more');
        self::assertSame('8', $this->db->rows('SELECT quantity FROM ps_stock_available WHERE id_product = 1')[0]['quantity']);

        $this->db->locks = [];
        $this->db->lockFails = true;
        $data = $this->send([['product_id' => '1', 'variant_id' => '0', 'quantity' => 9]]);
        self::assertSame('rows_locked', $data['results'][0]['code']);
        self::assertCount(1, $this->db->locks, 'a lock wait timeout is not repeated');
    }

    public function test_a_chunk_without_stock_rows_locks_nothing_and_creates_them(): void
    {
        $this->db->pdo->exec('DELETE FROM ps_stock_available WHERE id_product = 1');
        $data = $this->send([['product_id' => '1', 'variant_id' => '0', 'quantity' => 4, 'out_of_stock' => 1]], ['mode' => 'fast']);
        self::assertSame([['ok', 'updated', 0, []]], self::results($data));
        self::assertSame([], $this->db->locks);
        self::assertSame([['1', '0', '1', '0', '4', '4', '1']], array_map('array_values', $this->db->rows('SELECT id_product, id_product_attribute, id_shop, id_shop_group, quantity, physical_quantity, out_of_stock FROM ps_stock_available WHERE id_product = 1')));
        self::assertStringStartsWith('INSERT INTO `ps_stock_available`', $this->db->bulks[0], 'by the module, not through PrestaShop inside the transaction');
        self::assertStringContainsString(' ON DUPLICATE KEY UPDATE quantity = 4, physical_quantity = reserved_quantity + 4, out_of_stock = 1', $this->db->bulks[0]);

        // safe: as setQuantity() adds it (no physical quantity, the product's policy), with the hooks of an add.
        $this->db->pdo->exec('DELETE FROM ps_stock_available WHERE id_product = 2 AND id_product_attribute = 8');
        $this->db->pdo->exec('UPDATE ps_stock_available SET out_of_stock = 1 WHERE id_product = 2 AND id_product_attribute = 0');
        $data = $this->send([['product_id' => '2', 'variant_id' => '8', 'quantity' => 5]]);
        self::assertSame([['ok', 'updated', 0, []]], self::results($data));
        self::assertSame(['5', '0', '1'], array_values($this->db->rows('SELECT quantity, physical_quantity, out_of_stock FROM ps_stock_available WHERE id_product = 2 AND id_product_attribute = 8')[0]));
        self::assertSame('6', $this->db->rows('SELECT quantity FROM ps_stock_available WHERE id_product = 2 AND id_product_attribute = 0')[0]['quantity'], 'the total: 1 + 5');
        self::assertSame(['actionObjectAddBefore', 'actionObjectStockAvailableAddBefore', 'actionObjectAddAfter', 'actionObjectStockAvailableAddAfter', 'actionUpdateQuantity'], array_slice($this->hooks, 0, 5));
        self::assertSame(['id_product' => 2, 'id_product_attribute' => 8, 'quantity' => 5, 'delta_quantity' => null, 'id_shop' => 1], $this->seen[4][1], 'no delta for a row added, as PrestaShop');
    }

    public function test_a_combination_product_without_its_total_row_gets_one(): void
    {
        $this->db->pdo->exec('DELETE FROM ps_stock_available WHERE id_product = 2 AND id_product_attribute = 0');
        $this->send([['product_id' => '2', 'variant_id' => '7', 'quantity' => 4]]);

        self::assertSame('6', $this->db->rows('SELECT quantity FROM ps_stock_available WHERE id_product = 2 AND id_product_attribute = 0')[0]['quantity']);
    }

    public function test_every_stock_write_after_the_lock_goes_by_primary_key(): void
    {
        // An order's statement reaches a stock row through the id_product index, its entry first: a
        // chunk that holds the row must not write it through that index.
        $this->db->insert('stock_available', ['id_product' => 1, 'id_product_attribute' => 9, 'quantity' => 4]);
        $this->send([
            ['product_id' => '2', 'variant_id' => '7', 'quantity' => 10, 'out_of_stock' => 0],
            ['product_id' => '1', 'variant_id' => '0', 'quantity' => 6, 'out_of_stock' => 1],
        ], ['mode' => 'fast']);

        $stock = array_values(array_filter($this->db->bulks, static function (string $sql): bool {
            return strpos($sql, 'ps_stock_available') !== false;
        }));
        self::assertCount(5, $stock, 'two quantities, two policies, one total');
        foreach ($stock as $sql) {
            self::assertMatchesRegularExpression('/ WHERE id_stock_available (= \d+|IN \([0-9,]+\))\z/', $sql);
        }
        self::assertSame(['1', '1'], array_column($this->db->rows('SELECT out_of_stock FROM ps_stock_available WHERE id_product = 1 ORDER BY id_product_attribute'), 'out_of_stock'), 'variant "0": every row of the product, as setProductOutOfStock()');
        self::assertSame(['2', '0', '2'], array_column($this->db->rows('SELECT out_of_stock FROM ps_stock_available WHERE id_product = 2 ORDER BY id_product_attribute DESC'), 'out_of_stock'), 'a combination: its row only');
        self::assertSame(['12', '6'], array_column($this->db->rows('SELECT quantity FROM ps_stock_available WHERE id_product_attribute = 0 ORDER BY id_product DESC'), 'quantity'), 'the total of product 2: 10 + 2');
        self::assertCount(1, $this->db->locks, 'the total is summed from the rows the chunk holds');
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
        $this->hooks = [];
        $this->seen = [];
        $request = Request::fromServer(['REQUEST_METHOD' => 'POST', 'QUERY_STRING' => 'path=stock/bulk'], (string) json_encode(['rows' => $rows] + ($options === [] ? [] : ['options' => $options])));
        $log = new Log(function (array $row): void {
            $this->logged[] = (string) $row['message'];
        });
        $hook = function (string $name, array $params = []): void {
            $this->hooks[] = $name;
            if (isset($params['object']) && is_object($params['object'])) {
                $params['object'] = 'object #'.$params['object']->id;
            }
            $this->seen[] = [$name, $params, $this->db->pdo->inTransaction()];
            if ($name === $this->throwing) {
                throw new RuntimeException('hook');
            }
        };
        $bulk = new StockBulk($this->db, $this->db->scope(), new PendingWrites($this->db, 1, $log, $hook), $log, true, $hook, static function (int $id): object {
            // StockAvailable as committed.
            return (object) ['id' => $id];
        }, static function (array $products): void {
        }, $this->hookShop);
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
