<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Write;

use Kronixon\Connect\Api\ApiError;
use Kronixon\Connect\Api\Budget;
use Kronixon\Connect\Api\Request;
use Kronixon\Connect\Tests\Support\CatalogDb;
use Kronixon\Connect\Tests\Support\FixedClock;
use Kronixon\Connect\Write\Facets;
use Kronixon\Connect\Write\Maintenance;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/Support/prestashop-stubs.php';

/**
 * `POST maintenance/*` and the filters of `POST attributes`: walks that visit each product once within
 * their budget and end, and ps_facetedsearch's filters kept where its back office looks for them.
 */
final class MaintenanceTest extends TestCase
{
    /** @var CatalogDb */
    private $db;

    /** @var FixedClock */
    private $clock;

    /** @var list<string> */
    private $done = [];

    protected function setUp(): void
    {
        $this->db = new CatalogDb();
        $this->db->products(1, 120);
        $this->clock = new FixedClock(1790772000.0);
    }

    public function test_a_full_reindex_walks_every_product_once_across_calls(): void
    {
        $this->db->pdo->exec('UPDATE ps_product_shop SET active = 0 WHERE id_product = 100');
        $first = $this->call('reindex', ['scope' => 'full', 'budget_s' => 5], 0.06);
        self::assertTrue($first['continue']);
        self::assertSame([84, 35], [$first['indexed'], $first['remaining']], '5 s at 60 ms a product; the inactive one is not counted');
        $second = $this->call('reindex', ['scope' => 'full', 'budget_s' => 5, 'cursor' => $first['cursor']], 0.06);

        self::assertSame([36, 0, false, null], [$second['indexed'], $second['remaining'], $second['continue'], $second['cursor']]);
        self::assertSame(array_map(static function (int $id): string {
            return 'index '.$id;
        }, range(1, 120)), $this->done, 'once each, the inactive one too, and the walk ends');
    }

    public function test_pending_and_listed_products(): void
    {
        $this->db->pdo->exec('UPDATE ps_product_shop SET indexed = 0 WHERE id_product IN (5, 6)');
        self::assertSame([2, 0, false], array_values(array_intersect_key($this->call('reindex', ['scope' => 'pending']), array_flip(['indexed', 'remaining', 'continue']))));
        self::assertSame(['index 5', 'index 6'], $this->done);

        $this->done = [];
        $this->call('reindex', ['scope' => 'products', 'product_ids' => ['9', '7']]);
        self::assertSame(['index 7', 'index 9'], $this->done);
        foreach ([['scope' => 'products'], ['scope' => 'every'], ['scope' => 'pending', 'product_ids' => ['x']], ['scope' => 'pending', 'budget_s' => 0]] as $body) {
            try {
                $this->call('reindex', $body);
                self::fail('accepted '.json_encode($body));
            } catch (ApiError $error) {
                self::assertSame(422, $error->status());
            }
        }
    }

    public function test_facets_index_the_products_of_categories_and_need_the_module(): void
    {
        $this->db->insert('category_product', ['id_category' => 45, 'id_product' => 200]);
        $this->db->product(200);
        $data = $this->call('facets', ['scope' => 'categories', 'category_ids' => ['45']], 0.0, true);

        self::assertSame(['indexables', 'prices 200', 'attributes 200', 'invalidate'], $this->done);
        self::assertSame([1, null, false], [$data['indexed'], $data['cursor'], $data['continue']]);

        $this->done = [];
        $this->call('facets', ['scope' => 'prices', 'product_ids' => ['2', '1']], 0.0, true);
        self::assertSame(['prices 1', 'prices 2', 'invalidate'], $this->done);

        try {
            $this->call('facets', ['scope' => 'all']);
            self::fail('facets without ps_facetedsearch');
        } catch (ApiError $error) {
            self::assertSame([403, 'capability_disabled'], [$error->status(), $error->errorCode()]);
        }
    }

    public function test_cache_flush_names_what_it_cleared(): void
    {
        $data = $this->call('cache-flush', ['scope' => 'smarty']);

        self::assertSame(['flush smarty'], $this->done);
        self::assertSame(['smarty'], $data['cleared']);
    }

    public function test_filters_are_written_to_the_category_and_the_templates_that_cover_it(): void
    {
        $this->db->insert('layered_category', ['id_shop' => 1, 'controller' => 'category', 'id_category' => 45, 'id_value' => 0, 'type' => 'price', 'position' => 4]);
        $this->db->insert('layered_category', ['id_shop' => 1, 'controller' => 'category', 'id_category' => 46, 'id_value' => 7, 'type' => Facets::FEATURE, 'position' => 1]);
        $this->db->insert('layered_filter', ['name' => 'A', 'filters' => serialize(['categories' => [45, 50], 'shop_list' => [1 => 1], 'layered_selection_price_slider' => ['filter_type' => 0, 'filter_show_limit' => 0]])]);
        $this->db->insert('layered_filter', ['name' => 'B', 'filters' => serialize(['categories' => [46], 'shop_list' => [1 => 1], 'layered_selection_feat_7' => ['filter_type' => 0, 'filter_show_limit' => 0]])]);
        $this->db->insert('layered_filter', ['name' => 'other shop', 'filters' => serialize(['categories' => [45], 'shop_list' => [2 => 2]])]);
        $facets = new Facets(new \stdClass(), $this->db, $this->db->scope());

        self::assertTrue($facets->write(Facets::FEATURE, 7, [45 => ['position' => null, 'filter_type' => 1, 'show_limit' => 5]], false));
        self::assertSame([45 => ['position' => 5, 'filter_type' => 1, 'show_limit' => 5], 46 => ['position' => 1, 'filter_type' => 0, 'show_limit' => 0]], $facets->of(Facets::FEATURE, 7), 'merge: the next position of the category, the other kept');
        self::assertSame(['filter_type' => 1, 'filter_show_limit' => 5], $this->template('A')['layered_selection_feat_7']);
        self::assertArrayNotHasKey('layered_selection_feat_7', $this->template('other shop'));
        self::assertFalse($facets->write(Facets::FEATURE, 7, [45 => ['position' => null, 'filter_type' => 1, 'show_limit' => 5]], false), 'the same again changes nothing');

        self::assertTrue($facets->write(Facets::FEATURE, 7, [45 => ['position' => 2, 'filter_type' => 1, 'show_limit' => 5]], true));
        self::assertSame([45 => ['position' => 2, 'filter_type' => 1, 'show_limit' => 5]], $facets->of(Facets::FEATURE, 7));
        self::assertArrayNotHasKey('layered_selection_feat_7', $this->template('B'), 'replace: out of the template of a category no longer listed');
        self::assertArrayHasKey('layered_selection_price_slider', $this->template('A'), 'the template keeps its other filters');
    }

    /**
     * @return array<string, mixed>
     */
    private function template(string $name): array
    {
        return (array) unserialize((string) $this->db->rows('SELECT filters FROM ps_layered_filter WHERE name = '.$this->db->quote($name))[0]['filters']);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function call(string $endpoint, array $body, float $cost = 0.0, bool $module = false): array
    {
        $clock = $this->clock;
        $record = function (string $step) use ($clock, $cost): void {
            $this->done[] = $step;
            $clock->advance($cost);
        };
        $facets = new Facets($module ? new class ($record) {
            /** @var callable(string): void */
            private $record;

            public function __construct(callable $record)
            {
                $this->record = $record;
            }

            public function indexProductPrices(int $id): void
            {
                ($this->record)('prices '.$id);
            }

            public function indexAttributes(int $id): void
            {
                ($this->record)('attributes '.$id);
            }

            public function indexFeatures(): void
            {
                ($this->record)('indexables');
            }

            public function invalidateLayeredFilterBlockCache(): void
            {
                ($this->record)('invalidate');
            }
        } : null, $this->db, $this->db->scope());
        $maintenance = new Maintenance($this->db, $this->db->scope(), $facets, static function (int $id) use ($record): void {
            $record('index '.$id);
        }, static function (string $scope) use ($record): void {
            $record('flush '.$scope);
        });
        $request = Request::fromServer(['REQUEST_METHOD' => 'POST', 'QUERY_STRING' => 'path=maintenance/x'], (string) json_encode($body));
        $response = $maintenance->handle($endpoint, $request, new Budget($clock, 25));

        return (array) json_decode($response->body(), true);
    }
}
