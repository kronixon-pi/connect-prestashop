<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Catalog;

use Kronixon\Connect\Catalog\Cursor;
use Kronixon\Connect\Catalog\ImageReader;
use Kronixon\Connect\Catalog\PriceReader;
use Kronixon\Connect\Catalog\ProductReader;
use Kronixon\Connect\Catalog\Reader;
use Kronixon\Connect\Catalog\StockReader;
use Kronixon\Connect\Catalog\VariantReader;
use Kronixon\Connect\Tests\Support\CatalogDb;
use Kronixon\Connect\Tests\Support\ProtocolSchemas;
use PHPUnit\Framework\TestCase;

/**
 * The guarantee of an id-range walk (README.md, "Catalog reads"): the platform reads `meta.max_id`, splits `(0, max_id]`
 * into K ranges as its IdRange::split() does and reads each from the cursor `{"id":<after>}` with
 * `until_id`. Every row that exists for the whole walk comes exactly once; no row comes twice, even
 * while rows are created and deleted between pages; rows created past `max_id` are outside every range.
 */
final class RangeWalkTest extends TestCase
{
    /**
     * Per reader: its schema, the reader, the key of an item, the id `until_id` bounds, how to seed
     * 600 ids with gaps, and how to delete or create the rows of one id while a walk runs.
     *
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public function readers(): iterable
    {
        $externalId = static function (array $item): int {
            return (int) $item['external_id'];
        };
        yield 'products' => [[
            'schema' => 'catalog-products.json',
            'reader' => static function (CatalogDb $db): Reader {
                return new ProductReader($db, $db->scope(), $db);
            },
            'key' => $externalId,
            'range' => $externalId,
            'seed' => static function (CatalogDb $db, int $id): void {
                $db->product($id);
            },
            'delete' => static function (CatalogDb $db, int $id): void {
                $db->deleteProduct($id);
            },
        ]];
        yield 'variants' => [[
            'schema' => 'catalog-variants.json',
            'reader' => static function (CatalogDb $db): Reader {
                return new VariantReader($db, $db->scope());
            },
            'key' => $externalId,
            'range' => $externalId,
            'seed' => static function (CatalogDb $db, int $id): void {
                $db->variant($id, 1 + $id % 5);
            },
            'delete' => static function (CatalogDb $db, int $id): void {
                $db->deleteVariant($id);
            },
        ]];
        yield 'images' => [[
            'schema' => 'catalog-images.json',
            'reader' => static function (CatalogDb $db): Reader {
                return new ImageReader($db, $db->scope(), $db);
            },
            'key' => $externalId,
            'range' => $externalId,
            'seed' => static function (CatalogDb $db, int $id): void {
                $db->image($id, 1 + $id % 5);
            },
            'delete' => static function (CatalogDb $db, int $id): void {
                $db->deleteImage($id);
            },
        ]];
        // Rows keyed by product and variant: every 3rd product has two combinations.
        $productRow = static function (array $item): int {
            return (int) $item['product_id'];
        };
        $rowKey = static function (array $item): string {
            return $item['product_id'].':'.$item['variant_id'];
        };
        $seed = static function (CatalogDb $db, int $id): void {
            $db->product($id);
            if ($id % 3 === 0) {
                $db->variant($id * 10 + 1, $id);
                $db->variant($id * 10 + 2, $id);
            }
        };
        $delete = static function (CatalogDb $db, int $id): void {
            $db->deleteProduct($id);
        };
        yield 'prices' => [[
            'schema' => 'catalog-prices.json',
            'reader' => static function (CatalogDb $db): Reader {
                return new PriceReader($db, $db->scope());
            },
            'key' => $rowKey, 'range' => $productRow, 'seed' => $seed, 'delete' => $delete,
        ]];
        yield 'stock' => [[
            'schema' => 'catalog-stock.json',
            'reader' => static function (CatalogDb $db): Reader {
                return new StockReader($db, $db->scope());
            },
            'key' => $rowKey, 'range' => $productRow, 'seed' => $seed, 'delete' => $delete,
        ]];
    }

    /**
     * @dataProvider readers
     *
     * @param  array<string, mixed>  $case
     */
    public function test_k_ranges_read_every_row_once(array $case): void
    {
        $db = self::catalog($case);
        $expected = array_map('strval', array_keys(self::all($db, $case)));
        self::assertGreaterThan(400, count($expected));

        foreach ([1, 3, 8] as $k) {
            $seen = [];
            foreach (self::split((int) $case['reader']($db)->maxId(), $k) as [$after, $until]) {
                self::walk($db, $case, $after, $until, $seen);
            }
            self::assertSame($expected, self::sorted($seen), "K = $k");
        }
    }

    /**
     * @dataProvider readers
     *
     * @param  array<string, mixed>  $case
     */
    public function test_rows_created_and_deleted_during_the_walk(array $case): void
    {
        $db = self::catalog($case);
        $before = self::all($db, $case);
        $maxId = (int) $case['reader']($db)->maxId();
        $ranges = self::split($maxId, 3);
        $deleted = [];
        $next = $maxId + 1;
        $pages = 0;
        $seen = [];
        $between = static function () use ($db, $case, $ranges, &$deleted, &$next, &$pages): void {
            // Between pages: delete one id in each range (read already or not yet), create one past max_id.
            $pages++;
            foreach ($ranges as [$after, $until]) {
                $victim = $after + 1 + (($pages * 7) % ($until - $after));
                $case['delete']($db, $victim);
                $deleted[$victim] = true;
            }
            $case['seed']($db, $next++);
        };
        foreach ($ranges as [$after, $until]) {
            self::walk($db, $case, $after, $until, $seen, $between);
        }
        $lived = [];
        foreach ($before as $key => $range) {
            if (! isset($deleted[$range])) {
                $lived[] = $key;
            }
        }

        self::assertGreaterThan(10, $pages);
        self::assertSame([], array_diff($lived, array_keys($seen)), 'every row that lived through the walk was read');
        self::assertSame([], array_filter($seen, static function (int $n): bool {
            return $n > 1;
        }), 'no row twice');
        self::assertSame([], array_diff(array_keys($seen), array_keys($before)), 'rows created past max_id are outside every range');
    }

    /**
     * Ids 1…600 with every 7th missing, and 5000, 5001, 90000, so the ranges are uneven.
     *
     * @param  array<string, mixed>  $case
     */
    private static function catalog(array $case): CatalogDb
    {
        $db = new CatalogDb();
        $db->pdo->beginTransaction();
        foreach (array_merge(range(1, 600), [5000, 5001, 90000]) as $id) {
            if ($id % 7 !== 0 || $id > 600) {
                $case['seed']($db, $id);
            }
        }
        $db->pdo->commit();

        return $db;
    }

    /**
     * The platform's IdRange::split(): at most $k contiguous ranges (after, until] covering (0, $max].
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function split(int $max, int $k): array
    {
        $size = intdiv($max, $k) + ($max % $k === 0 ? 0 : 1);
        $ranges = [];
        for ($after = 0; $after < $max; $after = $until) {
            $until = $max - $after <= $size ? $max : $after + $size;
            $ranges[] = [$after, $until];
        }

        return $ranges;
    }

    /**
     * Reads one range page by page (limit 37) into $seen (key => times seen), validating every answer
     * and that every item is inside the range.
     *
     * @param  array<string, mixed>  $case
     * @param  array<string, int>  $seen
     */
    private static function walk(CatalogDb $db, array $case, int $after, int $until, array &$seen, ?callable $between = null): void
    {
        $reader = $case['reader']($db);
        $cursor = Cursor::encode($after);
        for ($guard = 0; $guard < 10000; $guard++) {
            $answer = ProductReaderTest::decode($reader->read(ProductReaderTest::query($db, 'limit=37&until_id='.$until.'&cursor='.$cursor, $reader->maxLimit())), $reader->maxId());
            self::assertSame([], ProtocolSchemas::errors($case['schema'], $answer));
            foreach ($answer['data'] as $item) {
                $range = $case['range']($item);
                self::assertTrue($range > $after && $range <= $until, "$range in ($after, $until]");
                $key = (string) $case['key']($item);
                $seen[$key] = ($seen[$key] ?? 0) + 1;
            }
            if (! $answer['meta']['has_more']) {
                return;
            }
            $cursor = (string) $answer['meta']['next_cursor'];
            if ($between !== null) {
                $between();
            }
        }
        self::fail('the walk did not end');
    }

    /**
     * Every row of a walk without ranges: key => its range id.
     *
     * @param  array<string, mixed>  $case
     * @return array<string, int>
     */
    private static function all(CatalogDb $db, array $case): array
    {
        $seen = [];
        self::walk($db, $case, 0, PHP_INT_MAX, $seen);
        self::assertSame([], array_filter($seen, static function (int $n): bool {
            return $n > 1;
        }));
        $rows = [];
        foreach (array_keys($seen) as $key) {
            $rows[(string) $key] = 0;
        }
        // The range id of every key comes from a second read of the same rows.
        $reader = $case['reader']($db);
        $cursor = Cursor::encode(0);
        do {
            $answer = ProductReaderTest::decode($reader->read(ProductReaderTest::query($db, 'limit='.$reader->maxLimit().'&cursor='.$cursor, $reader->maxLimit())), '0');
            foreach ($answer['data'] as $item) {
                $rows[(string) $case['key']($item)] = $case['range']($item);
            }
            $cursor = (string) $answer['meta']['next_cursor'];
        } while ($answer['meta']['has_more']);
        ksort($rows, SORT_NATURAL);

        return $rows;
    }

    /**
     * @param  array<string, int>  $seen
     * @return list<string>
     */
    private static function sorted(array $seen): array
    {
        self::assertSame([], array_filter($seen, static function (int $n): bool {
            return $n > 1;
        }), 'no row twice');
        $keys = array_map('strval', array_keys($seen));
        sort($keys, SORT_NATURAL);

        return $keys;
    }
}
