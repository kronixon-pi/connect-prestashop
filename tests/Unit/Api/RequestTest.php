<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Api;

use Kronixon\Connect\Api\ApiError;
use Kronixon\Connect\Api\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public function pathForms(): iterable
    {
        yield 'bare endpoint (Spec §5.1)' => ['path=capabilities', '/v1/capabilities'];
        yield 'with /v1 prefix' => ['path=/v1/status', '/v1/status'];
        yield 'with v1 prefix' => ['path=v1/catalog/products', '/v1/catalog/products'];
        yield 'encoded slash' => ['path=%2Fv1%2Fstock%2Fbulk', '/v1/stock/bulk'];
        yield 'trailing slash' => ['path=maintenance/cache-flush/', '/v1/maintenance/cache-flush'];
        yield 'transport params around it' => ['fc=module&module=kronixonconnect&controller=api&path=health&id_lang=1', '/v1/health'];
    }

    /**
     * @dataProvider pathForms
     */
    public function test_logical_path(string $query, string $expected): void
    {
        self::assertSame($expected, Request::logicalPath($query));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public function badPaths(): iterable
    {
        yield 'missing' => ['limit=1', 'bad_request'];
        yield 'empty' => ['path=', 'bad_request'];
        yield 'only the version' => ['path=/v1', 'bad_request'];
        yield 'traversal' => ['path=../config', 'bad_request'];
        yield 'upper case' => ['path=Capabilities', 'bad_request'];
        yield 'double slash' => ['path=catalog//products', 'bad_request'];
        yield 'query inside path' => ['path=catalog/products%3Fx%3D1', 'bad_request'];
        yield 'another protocol version' => ['path=/v2/capabilities', 'protocol_version_unsupported'];
    }

    /**
     * @dataProvider badPaths
     */
    public function test_bad_paths(string $query, string $code): void
    {
        try {
            Request::logicalPath($query);
            self::fail('expected '.$code);
        } catch (ApiError $error) {
            self::assertSame($code, $error->errorCode());
            self::assertSame(400, $error->status());
        }
    }

    public function test_method_override_only_turns_post_into_delete(): void
    {
        $request = Request::fromServer(['REQUEST_METHOD' => 'POST', 'QUERY_STRING' => 'path=images', 'HTTP_X_HTTP_METHOD_OVERRIDE' => 'delete'], '{}');
        self::assertSame('DELETE', $request->method());
        self::assertSame('POST', $request->transportMethod());

        foreach ([['GET', 'DELETE'], ['POST', 'PUT'], ['POST', 'GET']] as [$method, $override]) {
            try {
                Request::fromServer(['REQUEST_METHOD' => $method, 'QUERY_STRING' => 'path=images', 'HTTP_X_HTTP_METHOD_OVERRIDE' => $override], '');
                self::fail("$method with $override must be refused");
            } catch (ApiError $error) {
                self::assertSame('method_not_allowed', $error->errorCode());
            }
        }
    }

    public function test_only_get_post_delete_are_transport_methods(): void
    {
        foreach (['PUT', 'PATCH', 'HEAD', 'OPTIONS', ''] as $method) {
            try {
                Request::fromServer(['REQUEST_METHOD' => $method, 'QUERY_STRING' => 'path=health'], '');
                self::fail($method.' must be refused');
            } catch (ApiError $error) {
                self::assertSame(405, $error->status());
            }
        }
    }

    public function test_headers_are_case_insensitive(): void
    {
        $request = Request::fromServer(['REQUEST_METHOD' => 'GET', 'QUERY_STRING' => 'path=health', 'HTTP_X_KX_PROTOCOL' => 'v1', 'CONTENT_TYPE' => 'application/json'], '');

        self::assertSame('v1', $request->header('X-KX-Protocol'));
        self::assertSame('v1', $request->header('x-kx-protocol'));
        self::assertSame('application/json', $request->header('Content-Type'));
        self::assertNull($request->header('X-KX-Nonce'));
    }

    public function test_endpoint_query_hides_transport_parameters(): void
    {
        $request = Request::fromServer(['REQUEST_METHOD' => 'GET', 'QUERY_STRING' => 'path=catalog/products&limit=5&id_lang=2&q=a+b'], '');

        self::assertSame('5', $request->query('limit'));
        self::assertSame('a b', $request->query('q'));
        self::assertNull($request->query('id_lang'));
        self::assertNull($request->query('path'));
        self::assertNull($request->query('cursor'));
    }

    public function test_gzip_bodies_are_decoded(): void
    {
        $body = str_repeat('{"rows":[]}', 100);
        $request = Request::fromServer(['REQUEST_METHOD' => 'POST', 'QUERY_STRING' => 'path=stock/bulk', 'HTTP_CONTENT_ENCODING' => 'gzip'], (string) gzencode($body));

        self::assertSame($body, $request->body());
    }

    public function test_body_limits_apply_before_and_after_decompression(): void
    {
        $this->assertCode('payload_too_large', function (): void {
            Request::fromServer(['REQUEST_METHOD' => 'POST', 'QUERY_STRING' => 'path=products'], str_repeat('a', 101), 100);
        });
        $bomb = (string) gzencode(str_repeat('a', 200000));
        self::assertLessThan(1000, strlen($bomb));
        $this->assertCode('payload_too_large', function () use ($bomb): void {
            Request::fromServer(['REQUEST_METHOD' => 'POST', 'QUERY_STRING' => 'path=products', 'HTTP_CONTENT_ENCODING' => 'gzip'], $bomb, 100000);
        });
        self::assertSame(100, strlen(Request::fromServer(['REQUEST_METHOD' => 'POST', 'QUERY_STRING' => 'path=products'], str_repeat('a', 100), 100)->body()));
    }

    public function test_broken_or_unknown_encodings_are_bad_requests(): void
    {
        $this->assertCode('bad_request', function (): void {
            Request::fromServer(['REQUEST_METHOD' => 'POST', 'QUERY_STRING' => 'path=products', 'HTTP_CONTENT_ENCODING' => 'gzip'], 'not gzip');
        });
        $this->assertCode('bad_request', function (): void {
            Request::fromServer(['REQUEST_METHOD' => 'POST', 'QUERY_STRING' => 'path=products', 'HTTP_CONTENT_ENCODING' => 'gzip'], substr((string) gzencode(str_repeat('x', 5000)), 0, 20));
        });
        $this->assertCode('bad_request', function (): void {
            Request::fromServer(['REQUEST_METHOD' => 'POST', 'QUERY_STRING' => 'path=products', 'HTTP_CONTENT_ENCODING' => 'br'], 'x');
        });
    }

    private function assertCode(string $code, callable $call): void
    {
        try {
            $call();
        } catch (ApiError $error) {
            self::assertSame($code, $error->errorCode());

            return;
        }
        self::fail('expected '.$code);
    }
}
