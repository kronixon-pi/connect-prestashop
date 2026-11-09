<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Catalog;

use Kronixon\Connect\Api\ApiError;
use Kronixon\Connect\Api\Request;
use Kronixon\Connect\Catalog\Cursor;
use Kronixon\Connect\Catalog\Decimal;
use Kronixon\Connect\Catalog\Page;
use Kronixon\Connect\Catalog\PageQuery;
use Kronixon\Connect\Tests\Support\CatalogDb;
use PHPUnit\Framework\TestCase;

/**
 * The paging core of `GET catalog/*`: the cursor's one accepted form, the query members, the page
 * envelope and its 8 MB cut, protocol amounts.
 */
final class PagingTest extends TestCase
{
    public function test_a_cursor_is_base64url_json_of_the_last_position(): void
    {
        self::assertSame('eyJpZCI6MTIzfQ', Cursor::encode(123), 'the form of the schema example');
        self::assertSame([123, null], Cursor::decode('eyJpZCI6MTIzfQ'));
        self::assertSame([7, 0], Cursor::decode(Cursor::encode(7, 0)));
        self::assertSame([PHP_INT_MAX, 3], Cursor::decode(Cursor::encode(PHP_INT_MAX, 3)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function tamperedCursors(): iterable
    {
        $b64 = static function (string $json): string {
            return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        };
        yield 'empty' => [''];
        yield 'padding' => ['eyJpZCI6MTIzfQ=='];
        yield 'standard alphabet' => ['eyJpZCI6MTIzfQ+/'];
        yield 'not base64' => ['%%%'];
        yield 'not JSON' => [$b64('id=123')];
        yield 'id as a string' => [$b64('{"id":"123"}')];
        yield 'negative id' => [$b64('{"id":-1}')];
        yield 'float id' => [$b64('{"id":1.0}')];
        yield 'another member' => [$b64('{"id":1,"x":2}')];
        yield 'members reordered' => [$b64('{"v":2,"id":1}')];
        yield 'spaces' => [$b64('{"id": 1}')];
        yield 'leading zero' => [$b64('{"id":01}')];
        yield 'one character flipped' => [substr_replace(Cursor::encode(123), 'A', 3, 1)];
        yield 'too long' => [str_repeat('A', 513)];
    }

    /**
     * @dataProvider tamperedCursors
     */
    public function test_any_other_cursor_is_a_bad_request(string $cursor): void
    {
        try {
            Cursor::decode($cursor);
            self::fail('accepted '.$cursor);
        } catch (ApiError $e) {
            self::assertSame(400, $e->status());
            self::assertSame('bad_request', $e->errorCode());
            self::assertSame(['errors' => ['cursor']], $e->details());
        }
    }

    public function test_query_members(): void
    {
        $scope = (new CatalogDb())->scope();
        $query = PageQuery::from(self::request('limit=250&cursor='.Cursor::encode(40).'&until_id=900&since=2026-09-01T07:00:00Z'), $scope, 500);

        self::assertSame(250, $query->limit);
        self::assertSame(40, $query->afterId);
        self::assertNull($query->afterVariant);
        self::assertSame(900, $query->untilId);
        self::assertSame('2026-09-01 10:00:00', $query->since, 'since is compared in the shop time zone (Europe/Sofia, UTC+3 in September)');

        $default = PageQuery::from(self::request(''), $scope, 1000);
        self::assertSame(100, $default->limit);
        self::assertSame(0, $default->afterId);
        self::assertSame(PHP_INT_MAX, $default->untilId);
        self::assertSame(PHP_INT_MAX, PageQuery::from(self::request('until_id=99999999999999999999'), $scope, 500)->untilId, '20 digits: above every shop id');
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public function invalidQueries(): iterable
    {
        yield 'limit 0' => ['limit=0', ['limit']];
        yield 'limit above the endpoint maximum' => ['limit=501', ['limit']];
        yield 'limit with a sign' => ['limit=%2B5', ['limit']];
        yield 'until_id with a leading zero' => ['until_id=010', ['until_id']];
        yield 'until_id negative' => ['until_id=-1', ['until_id']];
        yield 'since without Z' => ['since=2026-09-01T07:00:00', ['since']];
        yield 'since that does not exist' => ['since=2026-02-30T00:00:00Z', ['since']];
        yield 'several' => ['limit=x&cursor=x!&until_id=x', ['limit', 'cursor', 'until_id']];
    }

    /**
     * @dataProvider invalidQueries
     *
     * @param  list<string>  $members
     */
    public function test_invalid_query_members_are_a_bad_request(string $query, array $members): void
    {
        try {
            PageQuery::from(self::request($query), (new CatalogDb())->scope(), 500);
            self::fail('accepted '.$query);
        } catch (ApiError $e) {
            self::assertSame(400, $e->status());
            self::assertSame(['errors' => $members], $e->details());
        }
    }

    public function test_a_range_is_empty_once_the_cursor_reaches_until_id(): void
    {
        $scope = (new CatalogDb())->scope();

        self::assertFalse(PageQuery::from(self::request('cursor='.Cursor::encode(9).'&until_id=10'), $scope, 500)->exhausted());
        self::assertTrue(PageQuery::from(self::request('cursor='.Cursor::encode(10).'&until_id=10'), $scope, 500)->exhausted());
        self::assertFalse(PageQuery::from(self::request('cursor='.Cursor::encode(10, 3).'&until_id=10'), $scope, 500)->exhausted(), 'variants of id 10 remain');
    }

    public function test_the_page_envelope(): void
    {
        $cursor = static function (array $item): string {
            return Cursor::encode((int) $item['id']);
        };
        $page = new Page([['id' => 1], ['id' => 2]], true, $cursor);
        self::assertSame([
            'data' => [['id' => 1], ['id' => 2]],
            'meta' => ['count' => 2, 'next_cursor' => Cursor::encode(2), 'has_more' => true, 'max_id' => '50', 'generated_at' => '2026-09-30T12:40:00Z'],
        ], $page->answer('50', '2026-09-30T12:40:00Z'));

        $last = (new Page([['id' => 3]], false, $cursor))->answer('3', '2026-09-30T12:40:00Z');
        self::assertNull($last['meta']['next_cursor']);
        self::assertFalse($last['meta']['has_more']);
        self::assertSame(['count' => 0, 'next_cursor' => null, 'has_more' => false, 'max_id' => '0', 'generated_at' => 'x'], Page::none()->answer('0', 'x')['meta']);
    }

    public function test_items_past_the_size_limit_move_to_the_next_page_and_a_page_keeps_one(): void
    {
        $cursor = static function (array $item): string {
            return Cursor::encode((int) $item['id']);
        };
        $items = [['id' => 1, 't' => str_repeat('a', 60)], ['id' => 2, 't' => str_repeat('b', 60)], ['id' => 3, 't' => str_repeat('c', 60)]];

        $page = new Page($items, false, $cursor, 160); // ≈ 77 bytes an item
        self::assertSame([1, 2], array_column($page->items, 'id'));
        self::assertTrue($page->hasMore);
        self::assertSame(Cursor::encode(2), $page->nextCursor);

        $single = new Page($items, false, $cursor, 10);
        self::assertSame([1], array_column($single->items, 'id'), 'one item above the limit still goes, so the walk advances');
    }

    public function test_amounts(): void
    {
        self::assertSame('99.990000', Decimal::money('99.990000'));
        self::assertSame('99.990000', Decimal::money('99.99'));
        self::assertSame('0.000000', Decimal::money('-0.000000'));
        self::assertSame('-1.000000', Decimal::money('-1'));
        self::assertSame('0.123456', Decimal::money('.1234567'), 'cut, never rounded');
        self::assertSame('0.000000', Decimal::money('abc'));
        self::assertSame('0.000000', Decimal::money(null));
        self::assertSame('104.990000', Decimal::add('99.990000', '5'));
        self::assertSame('-0.500000', Decimal::add('1.5', '-2'));
        self::assertSame('0.000000', Decimal::add('-1', '1'));
        self::assertSame('9.996667', Decimal::divide('29.990000', '3.000000'));
        self::assertSame('0.000000', Decimal::divide('29.990000', '0.000000'));
        self::assertSame('99.99', Decimal::canonical('99.990000'));
        self::assertSame('0', Decimal::canonical('-0.000000'));
        self::assertSame('2', Decimal::canonical('2.000000'));
    }

    private static function request(string $query): Request
    {
        return Request::fromServer(['REQUEST_METHOD' => 'GET', 'QUERY_STRING' => 'path=catalog/products'.($query === '' ? '' : '&'.$query)], '');
    }
}
