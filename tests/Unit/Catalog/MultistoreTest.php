<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Catalog;

use Kronixon\Connect\Catalog\ProductReader;
use Kronixon\Connect\Catalog\Reader;
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
     * @return list<array<string, mixed>>
     */
    private static function data(CatalogDb $db, Reader $reader, string $query = ''): array
    {
        return ProductReaderTest::decode($reader->read(ProductReaderTest::query($db, $query)), $reader->maxId())['data'];
    }
}
