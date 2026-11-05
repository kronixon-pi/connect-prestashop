<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Auth;

use InvalidArgumentException;
use Kronixon\Connect\Auth\Canonical;
use Kronixon\Connect\Auth\KeyRing;
use PHPUnit\Framework\TestCase;

final class CanonicalTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public function queries(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'only transport parameters' => ['fc=module&module=kronixonconnect&controller=api&path=health&id_lang=1&isolang=en&id_shop=1', ''];
        yield 'sorted by key' => ['b=2&a=1&c=3', 'a=1&b=2&c=3'];
        yield 'ties sorted by value' => ['tag=b&tag=a&tag=c', 'tag=a&tag=b&tag=c'];
        yield 'byte order, upper case first' => ['b=1&B=1&a=1', 'B=1&a=1&b=1'];
        yield 'key without =' => ['flag&a=1', 'a=1&flag='];
        yield 'empty pairs ignored' => ['a=1&&b=2&', 'a=1&b=2'];
        yield 'plus is a space, %2B a plus' => ['q=a+b%2Bc', 'q=a%20b%2Bc'];
        yield 'lower-case hex is normalised' => ['q=%c3%bc', 'q=%C3%BC'];
        yield 'reserved characters encoded' => ['q=a/b?c=d&r=x,y:z', 'q=a%2Fb%3Fc%3Dd&r=x%2Cy%3Az'];
        yield 'unreserved characters kept' => ['q=A-z_0.9~', 'q=A-z_0.9~'];
        yield 'encoded key' => ['ids%5B%5D=1', 'ids%5B%5D=1'];
        yield 'a transport name inside a value stays' => ['q=path', 'q=path'];
    }

    /**
     * @dataProvider queries
     */
    public function test_canonical_query(string $raw, string $expected): void
    {
        self::assertSame($expected, Canonical::query($raw));
    }

    public function test_path_line_has_no_question_mark_without_parameters(): void
    {
        self::assertSame('/v1/health', Canonical::pathLine('/v1/health', 'path=health'));
        self::assertSame('/v1/catalog/tags?limit=1', Canonical::pathLine('/v1/catalog/tags', 'path=catalog/tags&limit=1'));
    }

    public function test_request_and_response_strings_are_domain_separated(): void
    {
        $request = Canonical::request('get', '/v1/health', '', 1, 'n', '');
        $response = Canonical::response('n', 1, 200, '');

        self::assertSame("GET\n/v1/health\n1\nn\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855", $request);
        self::assertStringStartsWith("RESPONSE\n", $response);
        self::assertSame(4, substr_count($response, "\n"));
    }

    public function test_key_ring_candidates(): void
    {
        $ring = new KeyRing('c', 'p', 100);

        self::assertSame(['cur' => 'c', 'prev' => 'p'], $ring->candidates(null, 100));
        self::assertSame(['cur' => 'c'], $ring->candidates(null, 101));
        self::assertSame(['prev' => 'p'], $ring->candidates('prev', 50));
        self::assertSame([], $ring->candidates('prev', 101));
        self::assertSame([], $ring->candidates('other', 50));
        self::assertSame(['cur' => 'c'], (new KeyRing('c', ''))->candidates(null, 0));
    }

    public function test_a_key_ring_needs_a_current_secret(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new KeyRing('');
    }
}
