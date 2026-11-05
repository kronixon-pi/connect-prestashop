<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Api;

use Kronixon\Connect\Api\ApiError;
use Kronixon\Connect\Api\Budget;
use Kronixon\Connect\Api\Request;
use Kronixon\Connect\Api\Response;
use Kronixon\Connect\Api\Router;
use Kronixon\Connect\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class ResponseRouterBudgetTest extends TestCase
{
    public function test_error_envelope_with_retry_after(): void
    {
        $response = Response::error(409, 'busy', 'Another write holds the lock.', 'r-1', 1790772000, [], 5);

        self::assertSame('{"error":{"code":"busy","message":"Another write holds the lock.","request_id":"r-1","server_time":1790772000,"retry_after":5}}', $response->body());
        self::assertSame('5', $response->header('Retry-After'));
    }

    public function test_json_keeps_slashes_unicode_and_money_strings(): void
    {
        $response = Response::json(200, ['url' => 'https://shop.example.com/a', 'name' => 'Zürich', 'price' => '99.990000', 'w' => 1.0]);

        self::assertSame('{"url":"https://shop.example.com/a","name":"Zürich","price":"99.990000","w":1.0}', $response->body());
    }

    public function test_wire_compresses_only_large_bodies_when_accepted(): void
    {
        $big = Response::json(200, ['data' => str_repeat('x', 2000)]);
        $small = Response::json(200, ['ok' => true]);

        [$headers, $body] = $big->wire('br, gzip;q=0.8', false);
        self::assertSame('gzip', $headers['Content-Encoding']);
        self::assertSame($big->body(), gzdecode($body), 'the compressed bytes are the signed body');
        self::assertSame((string) strlen($body), $headers['Content-Length']);

        self::assertArrayNotHasKey('Content-Encoding', $small->wire('gzip', false)[0]);
        self::assertArrayNotHasKey('Content-Encoding', $big->wire('gzip;q=0', false)[0]);
        self::assertArrayNotHasKey('Content-Encoding', $big->wire('gzip', true)[0], 'zlib.output_compression already compresses');
        self::assertArrayNotHasKey('Content-Encoding', $big->wire('', false)[0]);
        self::assertSame('no-store', $big->wire('', false)[0]['Cache-Control']);
    }

    public function test_accepts_gzip(): void
    {
        self::assertTrue(Response::acceptsGzip('gzip'));
        self::assertTrue(Response::acceptsGzip('deflate, GZIP;q=0.5'));
        self::assertTrue(Response::acceptsGzip('*'));
        self::assertFalse(Response::acceptsGzip('gzip;q=0'));
        self::assertFalse(Response::acceptsGzip('br'));
        self::assertFalse(Response::acceptsGzip(''));
    }

    public function test_router_dispatches_known_routes_and_refuses_the_rest(): void
    {
        $router = (new Router())->add('get', '/v1/health', static function (Request $request, Budget $budget): Response {
            return Response::json(200, ['ok' => true, 'budget' => $budget->seconds()]);
        });
        $budget = new Budget(new FixedClock(0.0));

        self::assertSame('{"ok":true,"budget":25}', $router->dispatch($this->request('GET', 'health'), $budget)->body());
        self::assertSame(['/v1/health'], $router->paths());

        try {
            $router->dispatch($this->request('POST', 'health'), $budget);
            self::fail('expected 405');
        } catch (ApiError $error) {
            self::assertSame(405, $error->status());
            self::assertSame(['allowed' => ['GET']], $error->details());
        }
        try {
            $router->dispatch($this->request('GET', 'nope'), $budget);
            self::fail('expected 404');
        } catch (ApiError $error) {
            self::assertSame('not_found', $error->errorCode());
        }
    }

    public function test_budget_is_clamped_and_counts_down(): void
    {
        $clock = new FixedClock(1000.0);
        $budget = new Budget($clock);
        self::assertSame(25, $budget->seconds());
        self::assertSame(30, (new Budget($clock, 99))->seconds());
        self::assertSame(1, (new Budget($clock, 0))->seconds());

        $clock->advance(23.4);
        self::assertFalse($budget->exhausted());
        self::assertSame(23400, $budget->elapsedMs());
        $clock->advance(0.2);
        self::assertTrue($budget->exhausted(), 'less than 1.5 s left');
        $clock->advance(10);
        self::assertSame(0.0, $budget->remaining());
    }

    private function request(string $method, string $path): Request
    {
        return Request::fromServer(['REQUEST_METHOD' => $method, 'QUERY_STRING' => 'path='.$path], '');
    }
}
