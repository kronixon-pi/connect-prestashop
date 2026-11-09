<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Catalog;

use Kronixon\Connect\Catalog\AttributeReader;
use Kronixon\Connect\Catalog\AttributeValueReader;
use Kronixon\Connect\Catalog\BrandReader;
use Kronixon\Connect\Catalog\CategoryReader;
use Kronixon\Connect\Catalog\ProductReader;
use Kronixon\Connect\Catalog\Reader;
use Kronixon\Connect\Catalog\SupplierReader;
use Kronixon\Connect\Catalog\VariantReader;
use Kronixon\Connect\Tests\Support\CatalogDb;
use Kronixon\Connect\Tests\Support\ProtocolSchemas;
use PHPUnit\Framework\TestCase;

/**
 * A multistore shop shares products between shops, each shop with its own category tree, features,
 * suppliers, brands and images: a reader of one shop names only the entities that shop has, and what
 * another shop links to a shared product changes neither the item nor its content_hash.
 */
final class MultistoreTest extends TestCase
{
    public function test_a_shop_never_sees_the_relations_of_another_shop(): void
    {
        $db = new CatalogDb();
        $db->demo();
        $before = [self::data($db, new ProductReader($db, $db->scope(), $db), 'fields=full'), self::data($db, new VariantReader($db, $db->scope()))];

        $db->secondShop();
        $products = self::data($db, new ProductReader($db, $db->scope(), $db), 'fields=full');
        $variants = self::data($db, new VariantReader($db, $db->scope()));

        self::assertSame(['2', '45'], $products[0]['category_ids']);
        self::assertSame(['7', '8'], array_column($products[0]['attributes'], 'feature_id'));
        self::assertSame(['2', '3'], array_column($products[0]['suppliers'], 'supplier_id'));
        self::assertSame(['0', '0'], [$products[1]['brand_id'], $products[1]['supplier_id']], 'brand 14 and supplier 4 are not in shop 1');
        self::assertSame(['901'], $variants[0]['image_ids']);
        self::assertSame(json_encode($before), json_encode([$products, $variants]), 'shop 2 changed nothing shop 1 reads, content_hash included');
    }

    public function test_the_other_shop_reads_its_own_relations(): void
    {
        $db = new CatalogDb();
        $db->demo();
        $db->secondShop();
        $reader = new ProductReader($db, $db->scope(2), $db);
        $answer = ProductReaderTest::decode($reader->read(ProductReaderTest::query($db, 'fields=full')), $reader->maxId());
        $variants = self::data($db, new VariantReader($db, $db->scope(2)));

        self::assertSame([], ProtocolSchemas::errors('catalog-products.json', $answer));
        self::assertSame(['1', '2'], array_column($answer['data'], 'external_id'));
        [$one, $two] = $answer['data'];
        self::assertSame(['990'], $one['category_ids']);
        self::assertSame([['feature_id' => '9', 'value_id' => '390', 'custom' => false]], $one['attributes']);
        self::assertSame(['4'], array_column($one['suppliers'], 'supplier_id'));
        self::assertSame(['0', '0'], [$one['brand_id'], $one['supplier_id']], 'brand 12 and supplier 3 are shop 1\'s');
        self::assertSame(['14', '4'], [$two['brand_id'], $two['supplier_id']]);
        self::assertSame(['903'], array_column($two['images'], 'external_id'));
        self::assertSame(['10'], array_column($variants, 'external_id'));
        self::assertSame(['903'], $variants[0]['image_ids']);
    }

    /**
     * The taxonomy readers of each shop: only the shop's own entities, and a value only when its parent
     * (feature, attribute group) is the shop's too, so that no answer names an entity the shop lacks.
     */
    public function test_the_taxonomy_of_each_shop(): void
    {
        $db = new CatalogDb();
        $db->demo();
        $db->taxonomy();
        $db->secondShop();
        // Shop 2's own: category 990, supplier 4, feature 9 (value 390), attribute group 3 with value 4
        // that both shops list in attribute_shop; brand 14 is taxonomy()'s brand outside shop 1.
        $db->insert('category', ['id_category' => 990, 'id_parent' => 2, 'level_depth' => 2]);
        $db->insert('supplier', ['id_supplier' => 4, 'name' => 'Shop 2 supplier']);
        $db->insert('feature', ['id_feature' => 9]);
        $db->insert('attribute_group', ['id_attribute_group' => 3]);
        $db->link('attribute_group_shop', 'id_attribute_group', 3, 2);
        $db->insert('attribute', ['id_attribute' => 4, 'id_attribute_group' => 3]);
        foreach ([1, 2] as $shop) {
            $db->link('attribute_shop', 'id_attribute', 4, $shop);
        }
        $ids = static function (CatalogDb $db, int $shop): array {
            $key = static function (array $item): string {
                return $item['kind'].':'.$item['external_id'];
            };

            return [
                'categories' => array_column(self::data($db, new CategoryReader($db, $db->scope($shop))), 'external_id'),
                'brands' => array_column(self::data($db, new BrandReader($db, $db->scope($shop), $db)), 'external_id'),
                'suppliers' => array_column(self::data($db, new SupplierReader($db, $db->scope($shop))), 'external_id'),
                'attributes' => array_map($key, self::data($db, new AttributeReader($db, $db->scope($shop)))),
                'feature values' => array_column(self::data($db, new AttributeValueReader($db, $db->scope($shop)), 'kind=feature'), 'external_id'),
                'attribute values' => array_column(self::data($db, new AttributeValueReader($db, $db->scope($shop)), 'kind=attribute_group'), 'external_id'),
            ];
        };

        self::assertSame([
            'categories' => ['1', '2', '45', '46'], 'brands' => ['12', '13'], 'suppliers' => ['2', '3'],
            'attributes' => ['attribute_group:1', 'feature:2', 'attribute_group:2', 'feature:7', 'feature:8'],
            'feature values' => ['310', '311', '312'], 'attribute values' => ['1', '2', '3'],
        ], $ids($db, 1), 'value 4 is in shop 1, its group 3 is not');
        self::assertSame([
            'categories' => ['990'], 'brands' => ['14'], 'suppliers' => ['4'], 'attributes' => ['attribute_group:3', 'feature:9'],
            'feature values' => ['390'], 'attribute values' => ['4'],
        ], $ids($db, 2));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function data(CatalogDb $db, Reader $reader, string $query = ''): array
    {
        return ProductReaderTest::decode($reader->read(ProductReaderTest::query($db, $query)), $reader->maxId())['data'];
    }
}
