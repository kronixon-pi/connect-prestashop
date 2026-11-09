<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Catalog;

use Kronixon\Connect\Api\ApiError;
use Kronixon\Connect\Catalog\AttributeReader;
use Kronixon\Connect\Catalog\AttributeValueReader;
use Kronixon\Connect\Catalog\BrandReader;
use Kronixon\Connect\Catalog\CategoryReader;
use Kronixon\Connect\Catalog\Cursor;
use Kronixon\Connect\Catalog\Reader;
use Kronixon\Connect\Catalog\SupplierReader;
use Kronixon\Connect\Catalog\TagReader;
use Kronixon\Connect\Tests\Support\CatalogDb;
use Kronixon\Connect\Tests\Support\ProtocolSchemas;
use PHPUnit\Framework\TestCase;

/**
 * The taxonomy readers on the SQLite copy of the PrestaShop tables: categories, brands, suppliers,
 * attributes, attribute values, tags.
 */
final class TaxonomyReaderTest extends TestCase
{
    /** @var CatalogDb */
    private $db;

    protected function setUp(): void
    {
        $this->db = new CatalogDb();
        $this->db->demo();
        $this->db->taxonomy();
    }

    public function test_categories(): void
    {
        $answer = $this->answer(new CategoryReader($this->db, $this->db->scope()), 'catalog-categories.json');

        self::assertSame(['1', '2', '45', '46'], array_column($answer['data'], 'external_id'));
        self::assertSame([[true, false], [true, true], [false, false], [false, false]], array_map(static function (array $c): array {
            return [$c['root'], $c['is_home']];
        }, $answer['data']), 'Root and Home are roots, Home is home');
        $ssd = $answer['data'][2];
        self::assertSame(['2', 2, 3, true, 1], [$ssd['parent_id'], $ssd['level'], $ssd['position'], $ssd['active'], $ssd['product_count']]);
        self::assertSame(['en' => 'SSD', 'bg' => 'SSD bg'], $ssd['name']);
        self::assertSame('2026-09-01T07:00:00Z', $ssd['date_upd']);
        self::assertSame(5, $answer['data'][1]['product_count']);
        self::assertFalse($answer['data'][3]['active']);
        self::assertSame('46', $answer['meta']['max_id']);

        $this->db->insert('category_product', ['id_category' => 45, 'id_product' => 2]);
        $this->db->pdo->exec("UPDATE ps_category SET date_upd = '2026-10-01 00:00:00' WHERE id_category = 45");
        $again = $this->answer(new CategoryReader($this->db, $this->db->scope()), 'catalog-categories.json')['data'][2];
        self::assertSame($ssd['content_hash'], $again['content_hash'], 'product_count and date_upd are not content');
        $this->db->pdo->exec('UPDATE ps_category_shop SET position = 4 WHERE id_category = 45');
        self::assertNotSame($ssd['content_hash'], $this->answer(new CategoryReader($this->db, $this->db->scope()), 'catalog-categories.json')['data'][2]['content_hash']);
    }

    public function test_brands_and_suppliers(): void
    {
        $brands = $this->answer(new BrandReader($this->db, $this->db->scope(), $this->db), 'catalog-brands.json');
        self::assertSame(['12', '13'], array_column($brands['data'], 'external_id'), 'not 14, which is not in the shop');
        self::assertSame(['Samsung', '-'], array_column($brands['data'], 'name'));
        self::assertSame(['https://shop.example.com/img/m/12.jpg', null], array_column($brands['data'], 'logo_url'));
        self::assertSame([1, 0], array_column($brands['data'], 'product_count'));
        self::assertSame(['en' => 'About 12'], $brands['data'][0]['short_description']);
        self::assertSame('14', $brands['meta']['max_id']);

        $suppliers = $this->answer(new SupplierReader($this->db, $this->db->scope()), 'catalog-suppliers.json');
        self::assertSame(['2', '3'], array_column($suppliers['data'], 'external_id'));
        self::assertSame([false, true], array_column($suppliers['data'], 'active'));
        self::assertSame([1, 1], array_column($suppliers['data'], 'product_count'));
        self::assertSame(['bg' => 'Доставчик'], $suppliers['data'][0]['description']);
    }

    public function test_attributes_are_features_and_groups_in_id_then_kind_order(): void
    {
        $reader = new AttributeReader($this->db, $this->db->scope());
        $answer = $this->answer($reader, 'catalog-attributes.json');

        self::assertSame(['attribute_group:1', 'feature:2', 'attribute_group:2', 'feature:7', 'feature:8'], array_map(static function (array $a): string {
            return $a['kind'].':'.$a['external_id'];
        }, $answer['data']));
        self::assertSame('8', $answer['meta']['max_id']);
        self::assertSame([
            'kind' => 'attribute_group', 'external_id' => '2', 'kx_id' => null, 'position' => 1, 'name' => ['en' => 'Color'],
            'public_name' => ['en' => 'Color!'], 'group_type' => 'color', 'is_color_group' => true, 'value_count' => 2,
        ], $answer['data'][2]);
        self::assertSame(2, $answer['data'][3]['value_count'], 'feature 7: 310 and 312');
        self::assertSame(0, $answer['data'][4]['value_count'], 'feature 8 has only a custom value');

        $first = $reader->read(ProductReaderTest::query($this->db, 'limit=2'));
        self::assertSame(Cursor::encode(2, 0), $first->nextCursor, 'after feature 2');
        $next = $reader->read(ProductReaderTest::query($this->db, 'limit=2&cursor='.$first->nextCursor));
        self::assertSame(['attribute_group', 'feature'], array_column($next->items, 'kind'), 'group 2, then feature 7');
        self::assertSame(['2', '7'], array_column($next->items, 'external_id'));
        self::assertSame(['7'], array_column($reader->read(ProductReaderTest::query($this->db, 'cursor='.Cursor::encode(2).'&until_id=7'))->items, 'external_id'), '{"id":2} is after both of id 2');
    }

    public function test_attribute_values_of_one_kind(): void
    {
        $reader = new AttributeValueReader($this->db, $this->db->scope());
        $features = $this->answer($reader, 'catalog-attribute-values.json', 'kind=feature');
        self::assertSame(['310', '311', '312'], array_column($features['data'], 'external_id'));
        self::assertSame([
            'external_id' => '311', 'parent_id' => '8', 'kx_id' => null, 'custom' => true, 'value' => ['en' => 'Quiet'], 'position' => 0,
        ], $features['data'][1]);
        self::assertSame('312', $features['meta']['max_id'], 'the kind asked');
        self::assertSame(['310', '312'], array_column($this->answer($reader, 'catalog-attribute-values.json', 'kind=feature&parent_id=7')['data'], 'external_id'));

        $values = $this->answer($reader, 'catalog-attribute-values.json', 'kind=attribute_group');
        self::assertSame('3', $values['meta']['max_id']);
        self::assertSame([
            ['external_id' => '1', 'parent_id' => '2', 'kx_id' => null, 'name' => ['en' => 'Red'], 'color' => '#ff0000', 'position' => 0],
            ['external_id' => '2', 'parent_id' => '2', 'kx_id' => null, 'name' => ['en' => 'Blue'], 'color' => '#0000ff', 'position' => 0],
            ['external_id' => '3', 'parent_id' => '1', 'kx_id' => null, 'name' => ['en' => 'M'], 'color' => null, 'position' => 0],
        ], $values['data']);

        $this->db->legacy = true;
        self::assertArrayNotHasKey('position', $this->answer(new AttributeValueReader($this->db, $this->db->scope()), 'catalog-attribute-values.json', 'kind=feature')['data'][0], '1.7.8–8.x: no feature value position');
        foreach (['', 'kind=group', 'kind=feature&parent_id=x'] as $query) {
            try {
                $reader->read(ProductReaderTest::query($this->db, $query));
                self::fail('accepted '.$query);
            } catch (ApiError $e) {
                self::assertSame(400, $e->status());
            }
        }
    }

    public function test_tags(): void
    {
        $answer = $this->answer(new TagReader($this->db, $this->db->scope()), 'catalog-tags.json');

        self::assertSame([
            ['external_id' => '40', 'lang' => 'en', 'name' => 'nvme', 'product_count' => 1],
            ['external_id' => '41', 'lang' => 'en', 'name' => 'SSD', 'product_count' => 1],
            ['external_id' => '42', 'lang' => 'bg', 'name' => 'ссд', 'product_count' => 1],
        ], $answer['data'], 'not 43 (a language the shop lacks) nor 44 (no name)');
    }

    /** At most N queries (one fewer when a page has no row of a kind, which needs no kx lookup). */
    public function test_pages_cost_a_bounded_number_of_queries_whatever_their_size(): void
    {
        $cases = [
            [new CategoryReader($this->db, $this->db->scope()), '', 4],
            [new BrandReader($this->db, $this->db->scope(), $this->db), '', 4],
            [new SupplierReader($this->db, $this->db->scope()), '', 4],
            [new AttributeReader($this->db, $this->db->scope()), '', 8],
            [new AttributeValueReader($this->db, $this->db->scope()), 'kind=feature&', 4],
            [new TagReader($this->db, $this->db->scope()), '', 2],
        ];
        foreach ($cases as [$reader, $query, $expected]) {
            foreach (['limit=1', 'limit=500'] as $limit) {
                $this->db->queries = 0;
                $reader->read(ProductReaderTest::query($this->db, $query.$limit));
                self::assertLessThanOrEqual($expected, $this->db->queries, get_class($reader).' '.$limit);
                self::assertGreaterThanOrEqual($expected - 1, $this->db->queries, get_class($reader).' '.$limit);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(Reader $reader, string $schema, string $query = ''): array
    {
        $answer = ProductReaderTest::decode($reader->read(ProductReaderTest::query($this->db, $query)), $reader->maxId());
        self::assertSame([], ProtocolSchemas::errors($schema, $answer));

        return $answer;
    }
}
