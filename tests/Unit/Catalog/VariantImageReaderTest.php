<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Catalog;

use Kronixon\Connect\Api\ApiError;
use Kronixon\Connect\Catalog\ImageReader;
use Kronixon\Connect\Catalog\VariantReader;
use Kronixon\Connect\Tests\Support\CatalogDb;
use Kronixon\Connect\Tests\Support\ProtocolSchemas;
use PHPUnit\Framework\TestCase;

/**
 * `GET catalog/variants` and `GET catalog/images` on the SQLite copy of the PrestaShop tables.
 */
final class VariantImageReaderTest extends TestCase
{
    public function test_variants(): void
    {
        $db = new CatalogDb();
        $db->demo();
        $db->insert('kx_entity_map', ['entity_type' => 'variant', 'external_id' => '11', 'kx_id' => '0192b2e4-2f7a-7c5b-8a1d-3e4f5a6b7c8e']);
        $db->pdo->exec("UPDATE ps_product_attribute_shop SET available_date = '2026-12-01', minimal_quantity = 0 WHERE id_product_attribute = 11");
        $reader = new VariantReader($db, $db->scope());
        $answer = ProductReaderTest::decode($reader->read(ProductReaderTest::query($db, '')), $reader->maxId());

        self::assertSame([], ProtocolSchemas::errors('catalog-variants.json', $answer));
        self::assertSame('11', $answer['meta']['max_id']);
        self::assertSame([
            'external_id' => '10', 'product_id' => '2', 'kx_id' => null, 'reference' => 'TS-10', 'ean13' => '', 'mpn' => '',
            'price_impact' => '5.000000', 'wholesale_price' => '0.000000', 'weight_impact' => '0.000000', 'quantity' => 10, 'default' => true,
            'option_values' => [['group_id' => '1', 'value_id' => '3'], ['group_id' => '2', 'value_id' => '1']],
            'image_ids' => ['901'], 'minimal_quantity' => 1, 'available_date' => null,
        ], $answer['data'][0]);
        self::assertSame(['-2.500000', false, [], 1, '2026-12-01', '0192b2e4-2f7a-7c5b-8a1d-3e4f5a6b7c8e'], [
            $answer['data'][1]['price_impact'], $answer['data'][1]['default'], $answer['data'][1]['image_ids'],
            $answer['data'][1]['minimal_quantity'], $answer['data'][1]['available_date'], $answer['data'][1]['kx_id'],
        ]);

        self::assertSame([], ProductReaderTest::decode($reader->read(ProductReaderTest::query($db, 'product_id=1')), '11')['data']);
        self::assertCount(2, $reader->read(ProductReaderTest::query($db, 'product_id=2'))->items);
        self::assertSame([], $reader->read(ProductReaderTest::query($db, 'since=2026-09-30T00:00:00Z'))->items, 'product 2 changed before since');
        $this->expectException(ApiError::class);
        $reader->read(ProductReaderTest::query($db, 'product_id=x'));
    }

    /** Option values come from the page's combination rows; a row whose value no longer exists is left out. */
    public function test_variant_option_values_skip_a_deleted_value(): void
    {
        $db = new CatalogDb();
        $db->demo();
        $db->insert('product_attribute_combination', ['id_attribute' => 999, 'id_product_attribute' => 10]);
        $items = (new VariantReader($db, $db->scope()))->read(ProductReaderTest::query($db, ''))->items;

        self::assertSame([['group_id' => '1', 'value_id' => '3'], ['group_id' => '2', 'value_id' => '1']], $items[0]['option_values']);
    }

    public function test_images(): void
    {
        $db = new CatalogDb();
        $db->demo();
        $reader = new ImageReader($db, $db->scope(), $db);
        $answer = ProductReaderTest::decode($reader->read(ProductReaderTest::query($db, 'product_id=2')), $reader->maxId());

        self::assertSame([], ProtocolSchemas::errors('catalog-images.json', $answer));
        self::assertSame(['900', '901', '902'], array_column($answer['data'], 'external_id'), 'by id');
        self::assertSame([
            'external_id' => '900', 'product_id' => '2', 'position' => 2, 'cover' => true, 'alt' => ['en' => 'T-shirt 2'],
            'url' => 'https://shop.example.com/900/product-2.jpg',
            'urls' => ['small_default' => 'https://shop.example.com/900-small_default/product-2.jpg', 'large_default' => 'https://shop.example.com/900-large_default/product-2.jpg'],
            'sha256' => str_repeat('ab', 32), 'kx_key' => 'f-1',
        ], $answer['data'][0]);
        self::assertSame([null, null], [$answer['data'][1]['sha256'], $answer['data'][1]['kx_key']]);
        self::assertSame([], $reader->read(ProductReaderTest::query($db, 'product_id=1'))->items);
    }

    public function test_pages_cost_the_same_queries_whatever_their_size(): void
    {
        $db = new CatalogDb();
        $db->demo();
        $variants = new VariantReader($db, $db->scope());
        $images = new ImageReader($db, $db->scope(), $db);
        foreach ([[$variants, 6], [$images, 3]] as [$reader, $expected]) {
            foreach (['limit=1', 'limit=500'] as $query) {
                $db->queries = 0;
                $reader->read(ProductReaderTest::query($db, $query));
                self::assertSame($expected, $db->queries, get_class($reader).' '.$query);
            }
        }
    }
}
