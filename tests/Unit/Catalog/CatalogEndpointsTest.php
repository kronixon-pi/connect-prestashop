<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Catalog;

use Kronixon\Connect\Api\Response;
use Kronixon\Connect\Catalog\CatalogEndpoints;
use Kronixon\Connect\Catalog\Cursor;
use Kronixon\Connect\Tests\Support\CatalogDb;
use Kronixon\Connect\Tests\Support\ProtocolSchemas;
use Kronixon\Connect\Tests\Support\TestModule;
use PHPUnit\Framework\TestCase;

/**
 * `GET catalog/*` through the Kernel as the platform calls it: signed request, signed answer, the page
 * envelope with `max_id` and `generated_at`, a walk by `next_cursor`, refusals as signed 400s.
 */
final class CatalogEndpointsTest extends TestCase
{
    /** @var TestModule */
    private $module;

    /** @var CatalogDb */
    private $db;

    protected function setUp(): void
    {
        $this->db = new CatalogDb();
        $this->db->demo();
        $this->module = new TestModule();
        $this->module->pair();
        $db = $this->db;
        $catalog = new CatalogEndpoints(static function () use ($db) {
            return $db->scope();
        }, CatalogEndpoints::readers($db, $db), $this->module->clock);
        $this->module->router = $catalog->register($this->module->router);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public function endpoints(): iterable
    {
        yield 'products' => ['catalog/products', 'catalog-products.json'];
    }

    /**
     * @dataProvider endpoints
     */
    public function test_a_walk_by_next_cursor_through_the_kernel(string $endpoint, string $schema): void
    {
        $query = ['limit' => '2'];
        $pages = 0;
        do {
            $nonce = '4056df7f-8d8a-445a-9102-1e318c62'.sprintf('%04d', ++$pages);
            $response = $this->get($endpoint, $query, $nonce);
            self::assertSame(200, $response->status(), $response->body());
            self::assertTrue(TestModule::signedWith($response, $nonce, (string) $this->module->platform->secret));
            self::assertSame([], ProtocolSchemas::errors($schema, $response->body()));
            $answer = json_decode($response->body(), true);
            self::assertSame('2026-09-30T12:40:00Z', $answer['meta']['generated_at']);
            self::assertMatchesRegularExpression('/\A[1-9][0-9]*\z/', $answer['meta']['max_id']);
            $query['cursor'] = (string) $answer['meta']['next_cursor'];
        } while ($answer['meta']['has_more'] && $pages < 50);

        self::assertGreaterThan(1, $pages);
        self::assertNull($answer['meta']['next_cursor']);
    }

    public function test_refusals_are_signed_bad_requests(): void
    {
        $base64 = rtrim(strtr(base64_encode('{"id":"2"}'), '+/', '-_'), '=');
        foreach ([['cursor' => $base64], ['cursor' => Cursor::encode(2).'x'], ['limit' => '501'], ['until_id' => '01'], ['fields' => 'x']] as $i => $query) {
            $nonce = '7056df7f-8d8a-445a-9102-1e318c62000'.$i;
            $response = $this->get('catalog/products', $query, $nonce);

            self::assertSame(400, $response->status(), json_encode($query));
            self::assertSame('bad_request', json_decode($response->body(), true)['error']['code']);
            self::assertTrue(TestModule::signedWith($response, $nonce, (string) $this->module->platform->secret));
        }
        self::assertSame(405, $this->get('catalog/products', [], '8056df7f-8d8a-445a-9102-1e318c620000', 'POST')->status());
    }

    /**
     * @param  array<string, string>  $query
     */
    private function get(string $endpoint, array $query, string $nonce, string $method = 'GET'): Response
    {
        $server = $this->module->signedServer((string) $this->module->platform->secret, $method, $endpoint, $query, '', $nonce);

        return $this->module->kernel()->handle($server, '');
    }
}
