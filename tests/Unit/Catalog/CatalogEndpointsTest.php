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
        $this->db->taxonomy();
        $this->module = new TestModule();
        $this->module->pair();
        $db = $this->db;
        $catalog = new CatalogEndpoints(static function () use ($db) {
            return $db->scope();
        }, CatalogEndpoints::readers($db, $db), $this->module->clock);
        $this->module->router = $catalog->register($this->module->router);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2?: array<string, string>}>
     */
    public function endpoints(): iterable
    {
        yield 'products' => ['catalog/products', 'catalog-products.json'];
        yield 'variants' => ['catalog/variants', 'catalog-variants.json'];
        yield 'variants of several products' => ['catalog/variants', 'catalog-variants.json', ['product_id_in' => '2,1']];
        yield 'images' => ['catalog/images', 'catalog-images.json'];
        yield 'prices' => ['catalog/prices', 'catalog-prices.json'];
        yield 'stock' => ['catalog/stock', 'catalog-stock.json'];
        yield 'categories' => ['catalog/categories', 'catalog-categories.json'];
        yield 'brands' => ['catalog/brands', 'catalog-brands.json'];
        yield 'suppliers' => ['catalog/suppliers', 'catalog-suppliers.json'];
        yield 'attributes' => ['catalog/attributes', 'catalog-attributes.json'];
        yield 'attribute values of features' => ['catalog/attribute-values', 'catalog-attribute-values.json', ['kind' => 'feature']];
        yield 'attribute values of groups' => ['catalog/attribute-values', 'catalog-attribute-values.json', ['kind' => 'attribute_group']];
        yield 'tags' => ['catalog/tags', 'catalog-tags.json'];
    }

    /**
     * @dataProvider endpoints
     */
    public function test_a_walk_by_next_cursor_through_the_kernel(string $endpoint, string $schema, array $parameters = []): void
    {
        $query = $parameters + ['limit' => '1'];
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
        $response = $this->get('catalog/variants', ['product_id_in' => implode(',', range(1, 101))], '8056df7f-8d8a-445a-9102-1e318c620001');
        self::assertSame([400, 'bad_request', ['product_id_in']], [$response->status(), json_decode($response->body(), true)['error']['code'], json_decode($response->body(), true)['error']['details']['errors']]);
        self::assertTrue(TestModule::signedWith($response, '8056df7f-8d8a-445a-9102-1e318c620001', (string) $this->module->platform->secret));
    }

    public function test_only_the_endpoints_that_write_v_accept_it_in_a_cursor(): void
    {
        $accepted = [];
        $i = 0;
        foreach (array_keys(CatalogEndpoints::readers($this->db, $this->db)) as $endpoint) {
            $nonce = '9056df7f-8d8a-445a-9102-1e318c62'.sprintf('%04d', ++$i);
            $response = $this->get('catalog/'.$endpoint, ['cursor' => Cursor::encode(1, 0)], $nonce);
            if ($response->status() === 200) {
                $accepted[] = $endpoint;
            } else {
                self::assertSame([400, ['cursor']], [$response->status(), json_decode($response->body(), true)['error']['details']['errors']], $endpoint);
            }
        }

        self::assertSame(['prices', 'stock', 'attributes'], $accepted, 'rows keyed by product and variant, by id and kind');
    }

    public function test_the_attributes_cursor_carries_a_kind_0_or_1(): void
    {
        $statuses = [];
        foreach ([0, 1, 2, 7] as $i => $v) {
            $statuses[$v] = $this->get('catalog/attributes', ['cursor' => Cursor::encode(2, $v)], 'b056df7f-8d8a-445a-9102-1e318c62000'.$i)->status();
        }

        self::assertSame([0 => 200, 1 => 200, 2 => 400, 7 => 400], $statuses);
    }

    public function test_an_endpoint_reads_max_id_once(): void
    {
        $i = 0;
        foreach ([['catalog/products', [], '5'], ['catalog/attribute-values', ['kind' => 'feature'], '312'], ['catalog/attribute-values', ['kind' => 'attribute_group'], '3']] as [$endpoint, $query, $maxId]) {
            $this->db->queries = 0;
            $response = $this->get($endpoint, $query, 'a056df7f-8d8a-445a-9102-1e318c62'.sprintf('%04d', ++$i));
            $queries = $this->db->queries;
            $reader = CatalogEndpoints::readers($this->db, $this->db)[substr($endpoint, 8)]($this->db->scope());
            $this->db->queries = 0;
            $reader->read(ProductReaderTest::query($this->db, http_build_query($query)));

            self::assertSame($maxId, json_decode($response->body(), true)['meta']['max_id'], $endpoint);
            self::assertSame($this->db->queries + ($endpoint === 'catalog/products' ? 1 : 0), $queries, $endpoint.': the page, and MAX() only when the page has no max_id');
        }
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
