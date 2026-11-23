<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Write;

use Kronixon\Connect\Api\ApiError;
use Kronixon\Connect\Api\Budget;
use Kronixon\Connect\Api\Request;
use Kronixon\Connect\Support\Log;
use Kronixon\Connect\Tests\Support\CatalogDb;
use Kronixon\Connect\Tests\Support\FixedClock;
use Kronixon\Connect\Tests\Support\ProtocolSchemas;
use Kronixon\Connect\Write\Facets;
use Kronixon\Connect\Write\PendingWrites;
use Kronixon\Connect\Write\PriceBulk;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/Support/prestashop-stubs.php';

/**
 * `POST prices/bulk`: net prices and combination impacts written in chunks without Product::save(),
 * `expected_price` conflicts that write nothing, repeats that see no conflict, and the answer.
 */
final class PriceBulkTest extends TestCase
{
    /** @var CatalogDb */
    private $db;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private $hooks = [];

    /** @var list<int> products whose prices ps_facetedsearch indexed */
    private $indexed = [];

    /** @var int */
    private $flushed = 0;

    protected function setUp(): void
    {
        $this->db = new CatalogDb();
        $this->db->product(1, ['price' => '10.000000'], ['price' => '10.000000', 'wholesale_price' => '4.000000']);
        $this->db->product(2, [], ['price' => '20.000000']);
        $this->db->insert('product_attribute', ['id_product_attribute' => 7, 'id_product' => 2, 'price' => '1.000000']);
        $this->db->insert('product_attribute_shop', ['id_product_attribute' => 7, 'id_product' => 2, 'id_shop' => 1, 'price' => '1.000000']);
        $this->db->insert('tax_rules_group', ['id_tax_rules_group' => 1]);
        $this->db->insert('tax_rules_group', ['id_tax_rules_group' => 9, 'deleted' => 1]);
    }

    public function test_prices_and_impacts_are_written_and_the_answer_matches_the_schema(): void
    {
        $data = $this->send([
            ['product_id' => '1', 'variant_id' => '0', 'price' => '12.5', 'wholesale_price' => '5.00', 'expected_price' => '10.000000'],
            ['product_id' => '2', 'variant_id' => '7', 'price' => '-2.000000', 'wholesale_price' => null],
            ['product_id' => '2', 'variant_id' => '0', 'price' => '20.000000'],
        ]);

        self::assertSame([], ProtocolSchemas::errors('prices-bulk.json', $data));
        self::assertSame([['ok', 'updated', '10.000000'], ['ok', 'updated', '1.000000'], ['ok', 'unchanged', '20.000000']], self::statuses($data, 'previous_price'));
        self::assertSame(['12.500000', '5.000000'], array_values($this->db->rows('SELECT price, wholesale_price FROM ps_product_shop WHERE id_product = 1')[0]));
        self::assertSame('12.500000', $this->db->rows('SELECT price FROM ps_product WHERE id_product = 1')[0]['price'], 'the default shop: the main table too');
        self::assertSame('-2.000000', $this->db->rows('SELECT price FROM ps_product_attribute_shop WHERE id_product_attribute = 7')[0]['price']);
        self::assertNotSame('2026-09-01 10:00:00', $this->db->rows('SELECT date_upd FROM ps_product_shop WHERE id_product = 2')[0]['date_upd'], 'a combination row touches its product');
        self::assertSame(['ok' => 3, 'error' => 0, 'skipped' => 0, 'conflict' => 0, 'time_ms' => 0], $data['summary']);
        self::assertSame([['actionKxBulkPricesUpdated', ['ids' => [1, 2]]]], $this->hooks, 'one hook for the request, none per product');
        self::assertSame(1, $this->flushed);
        self::assertArrayNotHasKey('facets_pending', $data, 'no ps_facetedsearch');
    }

    public function test_a_price_changed_in_the_shop_is_a_conflict_and_nothing_of_the_row_is_written(): void
    {
        $data = $this->send([
            ['product_id' => '1', 'variant_id' => '0', 'price' => '15', 'wholesale_price' => '9', 'expected_price' => '9.990000'],
            ['product_id' => '2', 'variant_id' => '7', 'price' => '3', 'expected_price' => '0.5'],
        ]);

        self::assertSame([['conflict', null, '10.000000'], ['conflict', null, '1.000000']], self::statuses($data, 'current_price'));
        self::assertSame('price_changed', $data['results'][0]['code']);
        self::assertSame(['10.000000', '4.000000'], array_values($this->db->rows('SELECT price, wholesale_price FROM ps_product_shop WHERE id_product = 1')[0]));
        self::assertSame(2, $data['summary']['conflict']);
        self::assertSame([], $this->hooks, 'nothing changed');
        self::assertSame([], ProtocolSchemas::errors('prices-bulk.json', $data));
    }

    public function test_a_repeat_after_a_lost_answer_is_unchanged_not_a_conflict(): void
    {
        $rows = [['product_id' => '1', 'variant_id' => '0', 'price' => '11', 'expected_price' => '10']];
        self::assertSame('updated', $this->send($rows)['results'][0]['action']);
        $again = $this->send($rows);

        self::assertSame([['ok', 'unchanged', '11.000000']], self::statuses($again, 'previous_price'));
    }

    public function test_rows_naming_nothing_of_the_shop_are_refused_one_by_one(): void
    {
        $data = $this->send([
            ['product_id' => '99', 'variant_id' => '0', 'price' => '1'],
            ['product_id' => '1', 'variant_id' => '7', 'price' => '1'],
            ['product_id' => '1', 'variant_id' => '0', 'tax_rules_group_id' => 9],
            ['product_id' => '3', 'variant_id' => '0', 'price' => 'cheap'],
            ['product_id' => '2', 'variant_id' => '0', 'price' => '-1'],
            ['product_id' => 'x', 'variant_id' => '0', 'price' => '1'],
            ['product_id' => '2', 'variant_id' => '7', 'price' => '1.0'],
            ['product_id' => '1', 'variant_id' => '0', 'price' => '2'],
        ]);

        self::assertSame(['product_not_found', 'not_found', 'tax_rules_group_not_found', 'validation_failed', 'validation_failed', 'validation_failed', null, 'validation_failed'], array_map(static function (array $r): ?string {
            return $r['code'] ?? null;
        }, $data['results']));
        self::assertSame(['product_id', 'variant_id', 'tax_rules_group_id', 'rows.3.price', 'price', 'rows.5.product_id', null, 'rows.7.variant_id'], array_map(static function (array $r): ?string {
            return $r['field'] ?? null;
        }, $data['results']), 'the eighth names a product and variant sent before');
        self::assertSame('0', $data['results'][5]['product_id'], 'not an id: "0", the field names the row');
        self::assertSame([], ProtocolSchemas::errors('prices-bulk.json', $data));
    }

    public function test_the_envelope_is_checked_as_a_whole(): void
    {
        foreach ([['rows' => []], ['rows' => [['product_id' => '1']], 'options' => ['fire_hooks' => 'yes']], ['rows' => 'x']] as $body) {
            try {
                $this->post($body);
                self::fail('accepted '.json_encode($body));
            } catch (ApiError $error) {
                self::assertSame(422, $error->status());
            }
        }
        try {
            $this->post(['rows' => array_fill(0, 1001, ['product_id' => '1', 'variant_id' => '0', 'price' => '1'])]);
            self::fail('1 001 rows accepted');
        } catch (ApiError $error) {
            self::assertSame([413, 'batch_too_large'], [$error->status(), $error->errorCode()]);
        }
    }

    public function test_without_time_left_every_row_is_skipped_with_continue(): void
    {
        $data = $this->send([['product_id' => '1', 'variant_id' => '0', 'price' => '11']], [], 1);

        self::assertSame([['skipped', null, null]], self::statuses($data, 'previous_price'));
        self::assertTrue($data['continue']);
        self::assertSame('10.000000', $this->db->rows('SELECT price FROM ps_product_shop WHERE id_product = 1')[0]['price']);
    }

    public function test_a_unit_price_follows_the_rule_of_the_version(): void
    {
        $this->send([['product_id' => '1', 'variant_id' => '0', 'price' => '20', 'unit_price' => '4']]);
        self::assertSame(['20.000000', '4.000000', '5.000000'], array_values($this->db->rows('SELECT price, unit_price, unit_price_ratio FROM ps_product_shop WHERE id_product = 1')[0]));
        $this->send([['product_id' => '1', 'variant_id' => '0', 'price' => '40']]);
        self::assertSame(['40.000000', '4.000000', '10.000000'], array_values($this->db->rows('SELECT price, unit_price, unit_price_ratio FROM ps_product_shop WHERE id_product = 1')[0]), '8.0+: the unit price stays, the ratio follows');

        $this->db->legacy = true;
        $this->send([['product_id' => '1', 'variant_id' => '0', 'price' => '30', 'unit_price' => '6']]);
        self::assertSame(['30.000000', '5.000000'], array_values($this->db->rows('SELECT price, unit_price_ratio FROM ps_product_shop WHERE id_product = 1')[0]), '1.7.8: only the ratio');
    }

    public function test_on_1_7_8_a_new_price_keeps_the_unit_price_as_product_update_does(): void
    {
        $this->db->legacy = true;
        // The review's product: price 10, unit price 5 (ratio 2); price 20 sent → ratio 4, unit price 5.
        $this->db->pdo->exec("UPDATE ps_product_shop SET unit_price_ratio = '2.000000' WHERE id_product = 1");
        $this->send([['product_id' => '1', 'variant_id' => '0', 'price' => '20']]);
        self::assertSame(['20.000000', '4.000000'], array_values($this->db->rows('SELECT price, unit_price_ratio FROM ps_product_shop WHERE id_product = 1')[0]));

        // A ratio PrestaShop computes in floats: 10 / 3 per unit, then price 20 → ratio 6, not 6.000001.
        $this->db->pdo->exec("UPDATE ps_product_shop SET price = '10.000000', unit_price_ratio = '3.000000' WHERE id_product = 1");
        $this->send([['product_id' => '1', 'variant_id' => '0', 'price' => '20']]);
        self::assertSame('6.000000', $this->db->rows('SELECT unit_price_ratio FROM ps_product_shop WHERE id_product = 1')[0]['unit_price_ratio']);

        // No unit price (ratio 0) stays none; an unchanged price writes nothing.
        $this->db->pdo->exec("UPDATE ps_product_shop SET unit_price_ratio = '0.000000' WHERE id_product = 1");
        $this->send([['product_id' => '1', 'variant_id' => '0', 'price' => '25']]);
        self::assertSame('0.000000', $this->db->rows('SELECT unit_price_ratio FROM ps_product_shop WHERE id_product = 1')[0]['unit_price_ratio']);
        self::assertSame('unchanged', $this->send([['product_id' => '1', 'variant_id' => '0', 'price' => '25']])['results'][0]['action']);
    }

    public function test_the_chunk_reads_its_rows_with_their_locks_inside_its_transaction(): void
    {
        $this->send([['product_id' => '1', 'variant_id' => '0', 'price' => '11'], ['product_id' => '2', 'variant_id' => '7', 'price' => '2']]);

        self::assertCount(3, $this->db->locks, 'product, product_shop (the default shop), the combinations');
        self::assertStringContainsString('FROM `ps_product` WHERE', $this->db->locks[0][0]);
        self::assertStringContainsString('ORDER BY id_product', $this->db->locks[1][0]);
        self::assertStringContainsString('ORDER BY pa.id_product_attribute', $this->db->locks[2][0]);
        self::assertSame(10, $this->db->locks[0][1], 'at most 10 s of a 25 s budget');

        $this->db->locks = [];
        $this->post(['rows' => [['product_id' => '1', 'variant_id' => '0', 'price' => '12']]], 25, false, false);
        self::assertCount(1, $this->db->locks, 'another shop: only product_shop');
    }

    public function test_a_price_another_session_commits_while_the_chunk_waits_is_a_conflict(): void
    {
        // Committed after the chunk began and before its locks were granted (see the integration
        // check bulk-race.php for the same on MySQL with a second connection).
        $this->db->onLock = static function (CatalogDb $db): void {
            $db->pdo->exec("UPDATE ps_product_shop SET price = '11.000000' WHERE id_product = 1");
            $db->onLock = null;
        };
        $data = $this->send([['product_id' => '1', 'variant_id' => '0', 'price' => '12', 'expected_price' => '10']]);

        self::assertSame([['conflict', null, '11.000000']], self::statuses($data, 'current_price'));
        self::assertSame('11.000000', $this->db->rows('SELECT price FROM ps_product_shop WHERE id_product = 1')[0]['price'], 'nothing written');
    }

    public function test_rows_whose_locks_are_not_granted_are_skipped_with_continue_and_nothing_is_written(): void
    {
        $this->db->lockFails = true;
        $data = $this->send([['product_id' => '1', 'variant_id' => '0', 'price' => '11']]);

        self::assertSame(['skipped', 'rows_locked'], [$data['results'][0]['status'], $data['results'][0]['code']]);
        self::assertTrue($data['continue']);
        self::assertSame('10.000000', $this->db->rows('SELECT price FROM ps_product_shop WHERE id_product = 1')[0]['price']);
        self::assertSame([], ProtocolSchemas::errors('prices-bulk.json', $data));
    }

    public function test_hooks_per_product_and_the_facetedsearch_price_index_on_request(): void
    {
        $data = $this->send([['product_id' => '1', 'variant_id' => '0', 'price' => '11'], ['product_id' => '2', 'variant_id' => '7', 'price' => '2']], ['fire_hooks' => true, 'flush_cache' => false, 'reindex' => 'inline'], 25, true);

        self::assertSame(['actionProductUpdate', 'actionProductUpdate', 'actionKxBulkPricesUpdated'], array_column($this->hooks, 0));
        self::assertSame(1, $this->hooks[0][1]['id_product']);
        self::assertSame([1, 2], $this->indexed);
        self::assertSame(0, $this->flushed);
        self::assertArrayNotHasKey('facets_pending', $data);

        $deferred = $this->send([['product_id' => '1', 'variant_id' => '0', 'price' => '12']], [], 25, true);
        self::assertSame(['1'], $deferred['facets_pending']);
        self::assertSame([1, 2], $this->indexed, 'deferred: left to maintenance/facets');
    }

    public function test_another_shop_than_the_default_one_leaves_the_main_table(): void
    {
        $this->send([['product_id' => '1', 'variant_id' => '0', 'price' => '13']], [], 25, false, false);

        self::assertSame('13.000000', $this->db->rows('SELECT price FROM ps_product_shop WHERE id_product = 1')[0]['price']);
        self::assertSame('10.000000', $this->db->rows('SELECT price FROM ps_product WHERE id_product = 1')[0]['price']);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function send(array $rows, array $options = [], int $seconds = 25, bool $facets = false, bool $defaultShop = true): array
    {
        return $this->post(['rows' => $rows] + ($options === [] ? [] : ['options' => $options]), $seconds, $facets, $defaultShop);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(array $body, int $seconds = 25, bool $facets = false, bool $defaultShop = true): array
    {
        $request = Request::fromServer(['REQUEST_METHOD' => 'POST', 'QUERY_STRING' => 'path=prices/bulk'], (string) json_encode($body));
        $log = new Log(static function (): void {
        });
        $hook = function (string $name, array $params): void {
            $this->hooks[] = [$name, $params];
        };
        $module = $facets ? new class ($this->indexed) {
            /** @var list<int> */
            private $indexed;

            /** @param list<int> $indexed */
            public function __construct(array &$indexed)
            {
                $this->indexed = &$indexed;
            }

            public function indexProductPrices(int $id): void
            {
                $this->indexed[] = $id;
            }
        } : null;
        $scope = $this->db->scope();
        $bulk = new PriceBulk($this->db, $scope, new PendingWrites($this->db, 1, $log, $hook), $log, $defaultShop, $hook, new Facets($module, $this->db, $scope), function (): void {
            $this->flushed++;
        });
        $response = $bulk->run($request, new Budget(new FixedClock(1790772000.0), $seconds));
        self::assertSame(200, $response->status());

        return (array) json_decode($response->body(), true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{0: string, 1: string|null, 2: string|null}>
     */
    private static function statuses(array $data, string $member): array
    {
        return array_map(static function (array $result) use ($member): array {
            return [$result['status'], $result['action'] ?? null, $result[$member] ?? null];
        }, $data['results']);
    }
}
