<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Catalog;

use Kronixon\Connect\Api\ApiError;
use Kronixon\Connect\Catalog\Cursor;
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

    /**
     * `product_id_in`: product then combination order (combination ids interleave across products here),
     * every page size, and exactly the items of the `product_id=` answers of the listed products.
     */
    public function test_variants_of_several_products_are_the_union_of_their_product_answers(): void
    {
        $db = self::severalProducts();
        $reader = new VariantReader($db, $db->scope());
        $union = [];
        foreach ([2, 3, 4, 6, 7] as $product) {
            $union = array_merge($union, ProductReaderTest::decode($reader->read(ProductReaderTest::query($db, 'product_id='.$product)), '1')['data']);
        }
        self::assertSame(['2:10', '2:11', '3:5', '3:12', '4:13', '4:14', '4:16', '7:15'], self::keys($union), 'product 6 has its combination only in shop 2');

        foreach ([1, 2, 3, 7, 8, 500] as $limit) {
            [$items, $pages, $cursors] = self::walk($db, 'product_id_in=7,4,99,3,2,6,4&limit='.$limit);
            self::assertSame(json_encode($union), json_encode($items), "limit $limit");
            self::assertSame((int) ceil(count($union) / $limit), $pages, "limit $limit");
            foreach ($cursors as $cursor) {
                self::assertNotNull($cursor[1], 'a cursor names product and combination');
            }
        }
        $answer = ProductReaderTest::decode($reader->read(ProductReaderTest::query($db, 'product_id_in=2%2C3&limit=3')), $reader->maxId());
        self::assertSame([], ProtocolSchemas::errors('catalog-variants.json', $answer));
        self::assertSame(['2:10', '2:11', '3:5'], self::keys($answer['data']), 'an encoded comma');
        self::assertSame([3, 5], Cursor::decode((string) $answer['meta']['next_cursor']));
        self::assertSame('17', $answer['meta']['max_id'], 'the highest combination id, as without product_id_in');
    }

    public function test_variants_of_several_products_from_any_cursor(): void
    {
        $db = self::severalProducts();
        $reader = new VariantReader($db, $db->scope());
        $keys = static function (string $cursor) use ($db, $reader): array {
            return self::keys($reader->read(ProductReaderTest::query($db, 'product_id_in=2,3,4,7&cursor='.$cursor))->items);
        };

        self::assertSame(['3:12', '4:13', '4:14', '4:16', '7:15'], $keys(Cursor::encode(3, 5)), 'the rest of product 3, then the later products');
        self::assertSame(['4:13', '4:14', '4:16', '7:15'], $keys(Cursor::encode(3)), '{"id":3}: after every combination of product 3');
        self::assertSame(['4:13', '4:14', '4:16', '7:15'], $keys(Cursor::encode(3, 12)));
        self::assertSame(['7:15'], $keys(Cursor::encode(5, 1)), 'a cursor product outside the list');
        self::assertSame([], $keys(Cursor::encode(7, 15)));
        self::assertSame(['2:11', '3:5', '3:12'], self::keys($reader->read(ProductReaderTest::query($db, 'product_id_in=3,2&limit=3&cursor='.Cursor::encode(2, 10)))->items));
        $db->pdo->exec("UPDATE ps_product_shop SET date_upd = '2026-10-02 00:00:00' WHERE id_product = 4");
        self::assertSame(['4:13', '4:14', '4:16'], $keys(Cursor::encode(0).'&since=2026-10-01T00:00:00Z'), 'since: the product changed');
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public function refusedQueries(): iterable
    {
        yield 'empty' => ['product_id_in=', ['product_id_in']];
        yield 'empty member' => ['product_id_in=1,,2', ['product_id_in']];
        yield 'trailing comma' => ['product_id_in=1,2,', ['product_id_in']];
        yield 'not an integer' => ['product_id_in=1,x', ['product_id_in']];
        yield 'a leading zero' => ['product_id_in=01', ['product_id_in']];
        yield 'negative' => ['product_id_in=-1', ['product_id_in']];
        yield 'a space' => ['product_id_in=1,%202', ['product_id_in']];
        yield '101 ids' => ['product_id_in='.implode(',', range(1, 101)), ['product_id_in']];
        yield 'with product_id' => ['product_id_in=2&product_id=2', ['product_id', 'product_id_in']];
        yield 'with until_id' => ['product_id_in=2&until_id=10', ['until_id', 'product_id_in']];
        yield 'a v cursor without product_id_in' => ['product_id=2&cursor='.Cursor::encode(2, 10), ['cursor']];
    }

    /**
     * @dataProvider refusedQueries
     *
     * @param  list<string>  $errors
     */
    public function test_product_id_in_refusals(string $query, array $errors): void
    {
        $db = self::severalProducts();
        try {
            (new VariantReader($db, $db->scope()))->read(ProductReaderTest::query($db, $query));
            self::fail('accepted: '.$query);
        } catch (ApiError $e) {
            self::assertSame([400, 'bad_request', $errors], [$e->status(), $e->errorCode(), $e->details()['errors']]);
        }
    }

    public function test_product_id_in_takes_100_distinct_ids_and_counts_duplicates_once(): void
    {
        $db = self::severalProducts();
        $reader = new VariantReader($db, $db->scope());
        $ids = array_merge(range(3, 101), [2], range(3, 101), ['18446744073709551615']);

        self::assertSame(['2:10', '2:11', '3:5', '3:12', '4:13', '4:14', '4:16', '7:15'], self::keys($reader->read(ProductReaderTest::query($db, 'product_id_in='.implode(',', array_slice($ids, 0, 199))))->items));
        self::assertCount(0, $reader->read(ProductReaderTest::query($db, 'product_id_in=0,18446744073709551615'))->items, 'ids no product has');
        $this->expectException(ApiError::class);
        $reader->read(ProductReaderTest::query($db, 'product_id_in='.implode(',', $ids)));
    }

    public function test_a_product_id_in_page_costs_at_most_seven_queries(): void
    {
        $db = self::severalProducts();
        $reader = new VariantReader($db, $db->scope());
        // The rest of the cursor's product is one query; the later products another, unless the rest fills the page.
        foreach (['limit=1' => 6, 'limit=500' => 6, 'limit=1&cursor='.Cursor::encode(4, 13) => 6, 'limit=1&cursor='.Cursor::encode(2, 10) => 7, 'limit=500&cursor='.Cursor::encode(3, 5) => 7] as $query => $expected) {
            $db->queries = 0;
            $reader->read(ProductReaderTest::query($db, 'product_id_in=2,3,4,7&'.$query));
            self::assertSame($expected, $db->queries, $query);
        }
    }

    /**
     * The demo shop (product 2: combinations 10, 11) plus product 3 (5, 12), 4 (13, 14, 16), 7 (15) and
     * 6, whose only combination (17) is in shop 2.
     */
    private static function severalProducts(): CatalogDb
    {
        $db = new CatalogDb();
        $db->demo();
        foreach ([[5, 3, 1], [12, 3, 1], [13, 4, 1], [14, 4, 1], [16, 4, 1], [15, 7, 1], [17, 6, 2]] as [$variant, $product, $shop]) {
            $db->insert('product_attribute', ['id_product_attribute' => $variant, 'id_product' => $product, 'reference' => 'V-'.$variant]);
            $db->insert('product_attribute_shop', ['id_product_attribute' => $variant, 'id_product' => $product, 'id_shop' => $shop, 'price' => '1.000000', 'default_on' => null]);
            $db->insert('product_attribute_combination', ['id_attribute' => 3, 'id_product_attribute' => $variant]);
            $db->insert('stock_available', ['id_product' => $product, 'id_product_attribute' => $variant, 'quantity' => $variant]);
        }
        foreach ([3, 4, 6, 7] as $product) {
            $db->pdo->exec('INSERT OR IGNORE INTO ps_product_shop (id_product, id_shop) VALUES ('.$product.', 1)');
        }
        $db->pdo->exec("UPDATE ps_product_shop SET date_upd = '2026-09-01 00:00:00' WHERE id_product IN (3, 4, 6, 7)");

        return $db;
    }

    /**
     * Every page of a query by next_cursor: the items, the number of pages and the decoded cursors.
     *
     * @return array{0: list<array<string, mixed>>, 1: int, 2: list<array{0: int, 1: int|null}>}
     */
    private static function walk(CatalogDb $db, string $query): array
    {
        $reader = new VariantReader($db, $db->scope());
        $items = [];
        $pages = 0;
        $cursors = [];
        $cursor = '';
        do {
            $answer = ProductReaderTest::decode($reader->read(ProductReaderTest::query($db, $query.($cursor === '' ? '' : '&cursor='.$cursor))), $reader->maxId());
            self::assertSame([], ProtocolSchemas::errors('catalog-variants.json', $answer));
            $items = array_merge($items, $answer['data']);
            $pages++;
            $cursor = (string) $answer['meta']['next_cursor'];
            if ($cursor !== '') {
                $cursors[] = Cursor::decode($cursor);
            }
        } while ($answer['meta']['has_more'] && $pages < 50);

        return [$items, $pages, $cursors];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private static function keys(array $items): array
    {
        return array_map(static function (array $item): string {
            return $item['product_id'].':'.$item['external_id'];
        }, $items);
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
