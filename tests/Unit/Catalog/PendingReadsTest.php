<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Catalog;

use Kronixon\Connect\Catalog\AttributeReader;
use Kronixon\Connect\Catalog\AttributeValueReader;
use Kronixon\Connect\Catalog\BrandReader;
use Kronixon\Connect\Catalog\CategoryReader;
use Kronixon\Connect\Catalog\ImageReader;
use Kronixon\Connect\Catalog\PriceReader;
use Kronixon\Connect\Catalog\ProductReader;
use Kronixon\Connect\Catalog\Reader;
use Kronixon\Connect\Catalog\StockReader;
use Kronixon\Connect\Catalog\SupplierReader;
use Kronixon\Connect\Catalog\TagReader;
use Kronixon\Connect\Catalog\VariantReader;
use Kronixon\Connect\Tests\Support\CatalogDb;
use PHPUnit\Framework\TestCase;

/**
 * An object in `kx_pending` (a write created it and did not complete) is read by no endpoint: not in a
 * page, a range, `max_id`, a count or a relation of another entity, until its mark goes.
 */
final class PendingReadsTest extends TestCase
{
    /** @var CatalogDb */
    private $db;

    protected function setUp(): void
    {
        $this->db = new CatalogDb();
        $this->db->demo();
        $this->db->taxonomy();
    }

    public function test_a_marked_product_is_in_no_product_read(): void
    {
        $this->mark('product', 2);
        $this->mark('product', 5);

        self::assertSame(['1', '3', '4'], $this->ids(new ProductReader($this->db, $this->db->scope(), $this->db)));
        self::assertSame(['3'], $this->ids(new ProductReader($this->db, $this->db->scope(), $this->db), 'ids=2,3,5'), 'not by its id either');
        self::assertSame([], $this->ids(new ProductReader($this->db, $this->db->scope(), $this->db), 'until_id=2&cursor='.self::cursor(1)), 'nor in its range');
        self::assertSame('4', (new ProductReader($this->db, $this->db->scope(), $this->db))->maxId());
        self::assertSame([], $this->ids(new VariantReader($this->db, $this->db->scope())), 'its combinations');
        self::assertSame([], $this->ids(new VariantReader($this->db, $this->db->scope()), 'product_id_in=2'));
        self::assertSame([], $this->ids(new ImageReader($this->db, $this->db->scope(), $this->db)), 'its images');
        self::assertSame(['1:0', '3:0', '4:0'], $this->keys(new PriceReader($this->db, $this->db->scope())), 'its prices');
        self::assertSame(['1:0', '3:0', '4:0'], $this->keys(new StockReader($this->db, $this->db->scope())), 'its stock');
        self::assertSame('4', (new PriceReader($this->db, $this->db->scope()))->maxId());
        self::assertSame('4', (new StockReader($this->db, $this->db->scope()))->maxId());

        $this->db->pdo->exec('DELETE FROM ps_kx_pending');
        self::assertSame(['1', '2', '3', '4', '5'], $this->ids(new ProductReader($this->db, $this->db->scope(), $this->db)), 'once the mark is gone');
    }

    public function test_a_marked_combination_is_in_no_read(): void
    {
        $this->mark('product_attribute', 11);

        self::assertSame(['10'], $this->ids(new VariantReader($this->db, $this->db->scope())));
        self::assertSame(['10'], $this->ids(new VariantReader($this->db, $this->db->scope()), 'product_id=2'));
        self::assertSame(['10'], $this->ids(new VariantReader($this->db, $this->db->scope()), 'product_id_in=2'));
        self::assertSame('10', (new VariantReader($this->db, $this->db->scope()))->maxId());
        self::assertNotContains('2:11', $this->keys(new PriceReader($this->db, $this->db->scope())));
        self::assertNotContains('2:11', $this->keys(new StockReader($this->db, $this->db->scope())));
        self::assertContains('2:10', $this->keys(new StockReader($this->db, $this->db->scope())));
    }

    public function test_marked_taxonomy_is_in_no_read_and_no_count(): void
    {
        $this->mark('category', 46);
        $this->mark('manufacturer', 13);
        $this->mark('manufacturer', 14);
        $this->mark('feature', 8);
        $this->mark('feature_value', 312);
        $this->mark('attribute', 3);
        $this->mark('tag', 41);
        $scope = $this->db->scope();

        self::assertSame(['1', '2', '45'], $this->ids(new CategoryReader($this->db, $scope)));
        self::assertSame('45', (new CategoryReader($this->db, $scope))->maxId());
        self::assertSame(['12'], $this->ids(new BrandReader($this->db, $scope, $this->db)));
        self::assertSame('12', (new BrandReader($this->db, $scope, $this->db))->maxId());
        self::assertSame(['attribute_group:1', 'feature:2', 'attribute_group:2', 'feature:7'], array_map(static function (array $item): string {
            return $item['kind'].':'.$item['external_id'];
        }, $this->items(new AttributeReader($this->db, $scope))));
        self::assertSame('7', (new AttributeReader($this->db, $scope))->maxId());
        $values = $this->counts(new AttributeReader($this->db, $scope), 'value_count');
        self::assertSame([1, 0, 2], [$values['feature:7'], $values['attribute_group:1'], $values['attribute_group:2']], 'value 312 and attribute 3 are not counted');
        self::assertSame(['310', '311'], $this->ids(new AttributeValueReader($this->db, $scope), 'kind=feature'), '312 is marked');
        self::assertSame(['1', '2'], $this->ids(new AttributeValueReader($this->db, $scope), 'kind=attribute_group'));
        self::assertSame(['40', '42'], $this->ids(new TagReader($this->db, $scope)));
        self::assertSame('44', (new TagReader($this->db, $scope))->maxId(), 'the highest tag that is not marked');

        $variant = $this->items(new VariantReader($this->db, $scope))[0];
        self::assertSame([['group_id' => '2', 'value_id' => '1']], $variant['option_values'], 'a combination names no marked value');
        $product = $this->items(new ProductReader($this->db, $scope, $this->db))[0];
        self::assertSame(['en' => ['nvme'], 'bg' => ['ссд']], $product['tags'], 'a product names no marked tag');
    }

    public function test_a_product_names_no_marked_relation(): void
    {
        $this->mark('category', 45);
        $this->mark('feature_value', 311);
        $this->mark('product_supplier', 1);

        $product = $this->items(new ProductReader($this->db, $this->db->scope(), $this->db))[0];
        self::assertNotContains('45', $product['category_ids']);
        self::assertSame(['310'], array_column($product['attributes'], 'value_id'));
        self::assertSame(['2'], array_column($product['suppliers'], 'supplier_id'));
    }

    public function test_the_counts_leave_out_marked_products(): void
    {
        $scope = $this->db->scope();
        $before = [$this->counts(new CategoryReader($this->db, $scope), 'product_count'), $this->counts(new BrandReader($this->db, $scope, $this->db), 'product_count'),
            $this->counts(new SupplierReader($this->db, $scope), 'product_count'), $this->counts(new TagReader($this->db, $scope), 'product_count'), ];
        $this->mark('product', 1);

        $after = [$this->counts(new CategoryReader($this->db, $scope), 'product_count'), $this->counts(new BrandReader($this->db, $scope, $this->db), 'product_count'),
            $this->counts(new SupplierReader($this->db, $scope), 'product_count'), $this->counts(new TagReader($this->db, $scope), 'product_count'), ];
        self::assertSame([1, 0], [$before[0]['45'], $after[0]['45']], 'category 45');
        self::assertSame($before[0]['2'] - 1, $after[0]['2'], 'Home');
        self::assertSame([1, 0], [$before[1]['12'], $after[1]['12']], 'brand 12');
        self::assertSame([1, 0, 1, 0], [$before[2]['2'], $after[2]['2'], $before[2]['3'], $after[2]['3']], 'suppliers 2 and 3');
        self::assertSame([1, 0], [$before[3]['40'], $after[3]['40']], 'tag 40');
    }

    public function test_another_shops_marks_are_its_own(): void
    {
        $this->mark('product', 2, 2);
        $this->mark('category', 46, 2);

        self::assertSame(['1', '2', '3', '4', '5'], $this->ids(new ProductReader($this->db, $this->db->scope(), $this->db)));
        self::assertSame('46', (new CategoryReader($this->db, $this->db->scope()))->maxId());
    }

    private function mark(string $entity, int $id, int $shopId = 1): void
    {
        $this->db->insert('kx_pending', ['id_shop' => $shopId, 'class' => 'X', 'entity' => $entity, 'id_object' => $id, 'created_at' => '2026-09-30 12:00:00']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(Reader $reader, string $query = ''): array
    {
        return ProductReaderTest::decode($reader->read(ProductReaderTest::query($this->db, $query)), $reader->maxId())['data'];
    }

    /**
     * @return list<string>
     */
    private function ids(Reader $reader, string $query = ''): array
    {
        return array_column($this->items($reader, $query), 'external_id');
    }

    /**
     * `product:variant` of the price and stock rows.
     *
     * @return list<string>
     */
    private function keys(Reader $reader): array
    {
        return array_map(static function (array $item): string {
            return $item['product_id'].':'.$item['variant_id'];
        }, $this->items($reader));
    }

    /**
     * A counter by external id (`kind:id` for attributes).
     *
     * @return array<string, int>
     */
    private function counts(Reader $reader, string $member): array
    {
        $counts = [];
        foreach ($this->items($reader) as $item) {
            $counts[(isset($item['kind']) ? $item['kind'].':' : '').$item['external_id']] = $item[$member];
        }

        return $counts;
    }

    private static function cursor(int $id): string
    {
        return rtrim(strtr(base64_encode('{"id":'.$id.'}'), '+/', '-_'), '=');
    }
}
