<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Catalog;

use Kronixon\Connect\Catalog\Cursor;
use Kronixon\Connect\Catalog\PriceReader;
use Kronixon\Connect\Catalog\StockReader;
use Kronixon\Connect\Tests\Support\CatalogDb;
use Kronixon\Connect\Tests\Support\ProtocolSchemas;
use PHPUnit\Framework\TestCase;

/**
 * `GET catalog/prices` and `GET catalog/stock`: rows keyed by product and variant, a cursor that names
 * both, `until_id` on the product id.
 */
final class PriceStockReaderTest extends TestCase
{
    public function test_prices(): void
    {
        $db = new CatalogDb();
        $db->demo();
        $reader = new PriceReader($db, $db->scope());
        $answer = ProductReaderTest::decode($reader->read(ProductReaderTest::query($db, '', 1000)), $reader->maxId());

        self::assertSame([], ProtocolSchemas::errors('catalog-prices.json', $answer));
        self::assertSame(['1:0', '2:0', '2:10', '2:11', '3:0', '4:0', '5:0'], self::keys($answer['data']));
        self::assertSame([
            'product_id' => '1', 'variant_id' => '0', 'price' => '99.990000', 'wholesale_price' => '80.000000', 'tax_rules_group_id' => 1,
            'unit_price' => '49.995000', 'specific_prices' => [], 'date_upd' => '2026-09-30T12:40:00Z',
        ], $answer['data'][0]);
        [$product, $red, $blue] = array_slice($answer['data'], 1, 3);
        self::assertSame(['20.000000', '25.000000', '17.500000'], [$product['price'], $red['price'], $blue['price']], 'a variant: price + impact');
        self::assertSame(['5.000000', '-2.500000'], [$red['price_impact'], $blue['price_impact']]);
        self::assertSame(['5001'], array_column($product['specific_prices'], 'external_id'), 'not the cart-bound 5003');
        self::assertSame([], $red['specific_prices']);
        self::assertSame([
            'external_id' => '5002', 'id_currency' => 0, 'id_country' => 0, 'id_group' => 0, 'id_customer' => 0, 'from_quantity' => 3,
            'price' => '15.000000', 'reduction' => '0.000000', 'reduction_type' => 'amount', 'reduction_tax' => 1,
            'from' => '2026-09-30T21:00:00Z', 'to' => null,
        ], $blue['specific_prices'][0]);
        self::assertSame(['percentage', '0.100000'], [$product['specific_prices'][0]['reduction_type'], $product['specific_prices'][0]['reduction']]);

        self::assertSame(['1:0'], self::keys(ProductReaderTest::decode($reader->read(ProductReaderTest::query($db, 'since=2026-09-30T12:00:00Z', 1000)), '5')['data']));
    }

    public function test_stock(): void
    {
        $db = new CatalogDb();
        $db->demo();
        $reader = new StockReader($db, $db->scope());
        $answer = ProductReaderTest::decode($reader->read(ProductReaderTest::query($db, '', 1000)), $reader->maxId());

        self::assertSame([], ProtocolSchemas::errors('catalog-stock.json', $answer));
        self::assertSame(['1:0', '2:0', '2:10', '2:11', '3:0', '4:0', '5:0'], self::keys($answer['data']));
        self::assertSame([
            'product_id' => '2', 'variant_id' => '10', 'location' => 'default', 'quantity' => 10, 'physical_quantity' => 11,
            'reserved_quantity' => 1, 'out_of_stock' => 2, 'depends_on_stock' => false, 'date_upd' => null,
        ], $answer['data'][2]);
    }

    public function test_the_cursor_continues_inside_a_product_and_until_id_bounds_the_product(): void
    {
        $db = new CatalogDb();
        $db->demo();
        foreach ([new PriceReader($db, $db->scope()), new StockReader($db, $db->scope())] as $reader) {
            $first = $reader->read(ProductReaderTest::query($db, 'limit=3', 1000));
            self::assertSame(['1:0', '2:0', '2:10'], self::keys($first->items));
            self::assertSame(Cursor::encode(2, 10), $first->nextCursor);

            $rest = $reader->read(ProductReaderTest::query($db, 'limit=3&cursor='.$first->nextCursor.'&until_id=4', 1000));
            self::assertSame(['2:11', '3:0', '4:0'], self::keys($rest->items));
            self::assertFalse($rest->hasMore, 'product 5 is past until_id');

            self::assertSame(['3:0', '4:0', '5:0'], self::keys($reader->read(ProductReaderTest::query($db, 'cursor='.Cursor::encode(2), 1000))->items), '{"id":2} is after every row of product 2');
            self::assertSame([], $reader->read(ProductReaderTest::query($db, 'cursor='.Cursor::encode(4, 0).'&until_id=3', 1000))->items);
        }
    }

    public function test_pages_cost_a_bounded_number_of_queries(): void
    {
        $db = new CatalogDb();
        $db->demo();
        foreach ([[new PriceReader($db, $db->scope()), 6], [new StockReader($db, $db->scope()), 2]] as [$reader, $most]) {
            foreach (['limit=1', 'limit=1000', 'limit=1&cursor='.Cursor::encode(2, 10)] as $query) {
                $db->queries = 0;
                $reader->read(ProductReaderTest::query($db, $query, 1000));
                self::assertLessThanOrEqual($most, $db->queries, get_class($reader).' '.$query);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private static function keys(array $rows): array
    {
        return array_map(static function (array $row): string {
            return $row['product_id'].':'.$row['variant_id'];
        }, $rows);
    }
}
