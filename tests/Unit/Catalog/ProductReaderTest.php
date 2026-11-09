<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Catalog;

use Kronixon\Connect\Api\ApiError;
use Kronixon\Connect\Api\Request;
use Kronixon\Connect\Catalog\Page;
use Kronixon\Connect\Catalog\PageQuery;
use Kronixon\Connect\Catalog\ProductReader;
use Kronixon\Connect\Tests\Support\CatalogDb;
use Kronixon\Connect\Tests\Support\ProtocolSchemas;
use PHPUnit\Framework\TestCase;

/**
 * `GET catalog/products` on the SQLite copy of the PrestaShop tables: every answer matches
 * module/catalog-products.json, the members carry the shop's data, a page costs a fixed number of
 * queries, `content_hash` follows the content only.
 */
final class ProductReaderTest extends TestCase
{
    public function test_a_full_page_matches_the_schema_and_carries_the_shop_data(): void
    {
        $db = new CatalogDb();
        $db->demo();
        $answer = self::answer($db);

        self::assertSame([], ProtocolSchemas::errors('catalog-products.json', $answer));
        self::assertSame(['1', '2', '3', '4', '5'], array_column($answer['data'], 'external_id'));
        self::assertSame(['simple', 'variable', 'pack', 'virtual', 'simple'], array_column($answer['data'], 'type'));
        self::assertSame(['count' => 5, 'next_cursor' => null, 'has_more' => false, 'max_id' => '5'], array_slice($answer['meta'], 0, 4));

        $p = $answer['data'][0];
        self::assertSame('0192b2e4-2f7a-7c5b-8a1d-3e4f5a6b7c8d', $p['kx_id']);
        self::assertSame(['en' => 'Product 1', 'bg' => 'Продукт 1'], $p['name']);
        self::assertSame(['en' => '<p>NVMe</p>', 'bg' => '<p>NVMe</p>'], $p['description_short']);
        self::assertSame(['en' => '', 'bg' => ''], $p['description']);
        self::assertSame(['2', '45'], $p['category_ids']);
        self::assertSame('45', $p['default_category_id']);
        self::assertSame('12', $p['brand_id']);
        self::assertSame(['99.990000', '80.000000', '49.995000', '1.200000'], [$p['price'], $p['wholesale_price'], $p['unit_price'], $p['weight']]);
        self::assertSame([['feature_id' => '7', 'value_id' => '310', 'custom' => false], ['feature_id' => '8', 'value_id' => '311', 'custom' => true]], $p['attributes']);
        self::assertSame(['en' => ['SSD', 'nvme'], 'bg' => ['ссд']], $p['tags'], 'per language, sorted byte-wise');
        self::assertSame([
            ['supplier_id' => '2', 'reference' => '', 'price_te' => '81.000000'],
            ['supplier_id' => '3', 'reference' => 'S-1', 'price_te' => '80.000000', 'currency' => 'EUR'],
        ], $p['suppliers']);
        self::assertSame([7, 2, false], [$p['quantity'], $p['out_of_stock'], $p['depends_on_stock']]);
        self::assertSame(['2026-09-01T08:00:00Z', '2026-09-30T12:40:00Z'], [$p['date_add'], $p['date_upd']], 'shop time (Europe/Sofia) as UTC');
        self::assertSame(['type' => '404', 'target_id' => '0'], $p['redirect']);

        $images = $answer['data'][1]['images'];
        self::assertSame(['901', '900', '902'], array_column($images, 'external_id'), 'by position');
        self::assertSame([false, true, false], array_column($images, 'cover'));
        self::assertSame('https://shop.example.com/900-large_default/product-2.jpg', $images[1]['url']);
        self::assertSame([null, str_repeat('ab', 32), null], array_column($images, 'sha256'));
        self::assertEquals(new \stdClass(), $answer['data'][1]['tags'], 'no tags: {}');
        self::assertSame('{}', json_encode($answer['data'][1]['tags']));
    }

    public function test_basic_fields_and_filters(): void
    {
        $db = new CatalogDb();
        $db->demo();

        $basic = self::answer($db, 'fields=basic');
        self::assertSame([], ProtocolSchemas::errors('catalog-products.json', $basic));
        self::assertArrayNotHasKey('description', $basic['data'][0]);
        self::assertArrayNotHasKey('images', $basic['data'][0]);
        self::assertNotSame(self::answer($db)['data'][0]['content_hash'], $basic['data'][0]['content_hash'], 'the hash covers the members the item carries');

        self::assertSame(['2', '4'], array_column(self::answer($db, 'ids=4,2,99')['data'], 'external_id'));
        self::assertSame(['5'], array_column(self::answer($db, 'active=0')['data'], 'external_id'));
        self::assertSame(['1', '2', '3', '4'], array_column(self::answer($db, 'active=1')['data'], 'external_id'));
        self::assertSame(['1'], array_column(self::answer($db, 'since=2026-09-30T12:40:00Z')['data'], 'external_id'), 'date_upd at or after since');
        self::assertSame(['3', '4'], array_column(self::answer($db, 'cursor=eyJpZCI6Mn0&until_id=4')['data'], 'external_id'), 'after 2 up to 4 inclusive');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function invalidParameters(): iterable
    {
        yield 'ids with a zero' => ['ids=0,1'];
        yield 'ids with a space' => ['ids=1,%202'];
        yield 'more than 100 ids' => ['ids='.implode(',', range(1, 101))];
        yield 'active' => ['active=yes'];
        yield 'fields' => ['fields=all'];
    }

    /**
     * @dataProvider invalidParameters
     */
    public function test_invalid_parameters_are_a_bad_request(string $query): void
    {
        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('A query parameter is invalid.');

        self::answer(new CatalogDb(), $query);
    }

    public function test_a_page_costs_the_same_queries_whatever_its_size(): void
    {
        $db = new CatalogDb();
        $db->products(1, 300);
        $reader = new ProductReader($db, $db->scope(), $db);

        $db->queries = 0;
        $reader->read(self::query($db, 'limit=5'));
        $small = $db->queries;
        $db->queries = 0;
        $page = $reader->read(self::query($db, 'limit=300'));

        self::assertCount(300, $page->items);
        self::assertSame($small, $db->queries);
        self::assertSame(9, $small, 'base rows, texts, kx ids, categories, stock, features, tags, suppliers, images');
    }

    public function test_the_unit_price_of_a_shop_without_the_column(): void
    {
        $db = new CatalogDb();
        $db->legacy = true;
        $db->demo();

        self::assertSame('49.995000', self::answer($db)['data'][0]['unit_price'], '99.99 / 2');
    }

    public function test_content_hash_follows_the_content_only(): void
    {
        $db = new CatalogDb();
        $db->demo();
        $hash = self::answer($db)['data'][0]['content_hash'];

        $db->pdo->exec("UPDATE ps_product_shop SET date_upd = '2026-10-01 00:00:00' WHERE id_product = 1");
        $db->pdo->exec('UPDATE ps_stock_available SET quantity = 99 WHERE id_product = 1');
        self::assertSame($hash, self::answer($db)['data'][0]['content_hash'], 'dates and quantity are not content');

        $db->pdo->exec("UPDATE ps_product_shop SET price = '99.99' WHERE id_product = 1");
        self::assertSame($hash, self::answer($db)['data'][0]['content_hash'], 'the same amount written another way');

        $db->pdo->exec("UPDATE ps_product_lang SET name = 'Renamed' WHERE id_product = 1 AND id_lang = 2");
        self::assertNotSame($hash, self::answer($db)['data'][0]['content_hash']);
    }

    public function test_the_platform_link_is_not_content(): void
    {
        $db = new CatalogDb();
        $db->demo();
        $before = self::answer($db)['data'];

        $db->insert('kx_entity_map', ['entity_type' => 'product', 'external_id' => '2', 'kx_id' => '0192b2e4-2f7a-7c5b-8a1d-3e4f5a6b7c90']);
        $db->delete('kx_entity_map', "external_id = '1'");
        $after = self::answer($db)['data'];

        self::assertSame([null, '0192b2e4-2f7a-7c5b-8a1d-3e4f5a6b7c90'], [$after[0]['kx_id'], $after[1]['kx_id']], 'the link is answered');
        self::assertSame(array_column($before, 'content_hash'), array_column($after, 'content_hash'), 'adding or removing a kx_entity_map row');
    }

    /**
     * @return array<string, mixed>
     */
    private static function answer(CatalogDb $db, string $query = ''): array
    {
        $reader = new ProductReader($db, $db->scope(), $db);
        $page = $reader->read(self::query($db, $query));

        return self::decode($page, $reader->maxId());
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(Page $page, string $maxId): array
    {
        $answer = $page->answer($maxId, '2026-09-30T12:40:00Z');
        $json = (string) json_encode($answer);
        self::assertNotSame('', $json);

        return $answer;
    }

    public static function query(CatalogDb $db, string $query, int $max = 500): PageQuery
    {
        return PageQuery::from(Request::fromServer(['REQUEST_METHOD' => 'GET', 'QUERY_STRING' => 'path=catalog/x'.($query === '' ? '' : '&'.$query)], ''), $db->scope(), $max);
    }
}
