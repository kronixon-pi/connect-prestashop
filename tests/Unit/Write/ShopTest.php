<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Write;

use Context;
use Kronixon\Connect\Catalog\AttributeReader;
use Kronixon\Connect\Catalog\AttributeValueReader;
use Kronixon\Connect\Catalog\BrandReader;
use Kronixon\Connect\Catalog\CategoryReader;
use Kronixon\Connect\Catalog\ContentHash;
use Kronixon\Connect\Catalog\PageQuery;
use Kronixon\Connect\Catalog\ProductReader;
use Kronixon\Connect\Catalog\TagReader;
use Kronixon\Connect\Compat\Ps9;
use Kronixon\Connect\Redirect\RedirectStore;
use Kronixon\Connect\Tests\Support\CatalogDb;
use Kronixon\Connect\Tests\Support\MemorySettings;
use Kronixon\Connect\Write\ItemHashes;
use Kronixon\Connect\Write\Shop;
use Kronixon\Connect\Write\Warnings;
use PHPUnit\Framework\TestCase;
use Product;

require_once dirname(__DIR__, 2).'/Support/prestashop-stubs.php';

/**
 * What the writers of one request share, and the hashes they answer.
 */
final class ShopTest extends TestCase
{
    /** @var CatalogDb */
    private $db;

    /** @var Shop */
    private $shop;

    protected function setUp(): void
    {
        $this->db = new CatalogDb();
        $this->shop = new Shop(new Context(), $this->db->scope(), new Ps9(), $this->db, $this->db, new RedirectStore($this->db, new MemorySettings(), 1), new Warnings(), false);
    }

    public function test_only_entities_of_the_paired_shop_are_held(): void
    {
        $this->db->product(5);
        $this->db->insert('product', ['id_product' => 6]);
        $this->db->insert('product_shop', ['id_product' => 6, 'id_shop' => 2]);

        self::assertTrue($this->shop->holds('product', 5));
        self::assertFalse($this->shop->holds('product', 6));
        self::assertFalse($this->shop->holds('product', 7));
    }

    public function test_after_a_rollback_only_what_the_item_created_and_a_hook_committed_goes(): void
    {
        $this->db->product(77);  // the id the stub's add() gives: a hook committed this one
        $committed = new Product();
        $this->shop->fields($committed)->save('product');
        $this->shop->dropCreated();
        self::assertSame(['add', 'delete'], $committed->calls);

        $this->db->delete('product', 'id_product = 77');
        $rolledBack = new Product();
        $this->shop->fields($rolledBack)->save('product');
        $this->shop->dropCreated();
        self::assertSame(['add'], $rolledBack->calls, 'the rollback removed it');

        $this->db->insert('product', ['id_product' => 77]);
        $kept = new Product();
        $this->shop->fields($kept)->save('product');
        $this->shop->keepCreated();
        $this->shop->dropCreated();
        self::assertSame(['add'], $kept->calls, 'an earlier item is not touched');

        $existing = new Product();
        $existing->id = 77;
        $fields = $this->shop->fields($existing);
        $fields->set('reference', 'x');
        $fields->save('product');
        $this->shop->dropCreated();
        self::assertSame(['update'], $existing->calls, 'an update is never deleted');
    }

    public function test_names_are_read_once_and_follow_what_the_request_creates(): void
    {
        $reads = 0;
        $rows = static function () use (&$reads): array {
            $reads++;

            return [['id' => '1', 'name' => 'Red'], ['id' => '2', 'name' => ' rED  '], ['id' => '3', 'name' => 'Blue']];
        };

        self::assertSame([1, 2], $this->shop->named('v', 'RED', $rows));
        self::assertSame([], $this->shop->named('v', 'Green', $rows));
        $this->shop->remember('v', 'Green', 4);
        self::assertSame([4], $this->shop->named('v', 'green', $rows));
        self::assertSame(1, $reads);
        $this->shop->dropCreated();
        self::assertSame([], $this->shop->named('v', 'green', $rows), 'rolled back: read again');
        self::assertSame(2, $reads);
    }

    public function test_hashes_are_those_of_the_reads(): void
    {
        $this->db->demo();
        $this->db->taxonomy();
        $scope = $this->db->scope();
        $products = new ProductReader($this->db, $scope, $this->db);
        $page = $products->read(new PageQuery(500))->items;
        $hashes = ItemHashes::read($products, [(int) $page[1]['external_id'], (int) $page[0]['external_id'], 99999]);
        self::assertSame([$page[0]['external_id'] => $page[0]['content_hash'], $page[1]['external_id'] => $page[1]['content_hash']], $this->sorted($hashes));

        $categories = (new CategoryReader($this->db, $scope))->read(new PageQuery(500))->items;
        self::assertSame($this->sorted(array_column($categories, 'content_hash', 'external_id')), $this->sorted(ItemHashes::read(new CategoryReader($this->db, $scope), array_map('intval', array_column($categories, 'external_id')))));

        // Reads without a hash: the platform's rule for them.
        foreach ([new BrandReader($this->db, $scope, $this->db), new TagReader($this->db, $scope)] as $reader) {
            $item = $reader->read(new PageQuery(500))->items[0];
            $without = array_diff_key($item, array_flip(['kx_id', 'date_upd', 'product_count', 'value_count', 'content_hash', 'facets']));
            self::assertSame([$item['external_id'] => ContentHash::of($without)], ItemHashes::read($reader, [(int) $item['external_id']]));
        }
    }

    public function test_hashes_of_a_read_that_mixes_kinds(): void
    {
        $this->db->insert('feature', ['id_feature' => 2, 'position' => 1]);
        $this->db->insert('feature_lang', ['id_feature' => 2, 'id_lang' => 1, 'name' => 'Size']);
        $this->db->link('feature_shop', 'id_feature', 2);
        $this->db->insert('attribute_group', ['id_attribute_group' => 2]);
        $this->db->insert('attribute_group_lang', ['id_attribute_group' => 2, 'id_lang' => 1, 'name' => 'Colour', 'public_name' => 'Colour']);
        $this->db->link('attribute_group_shop', 'id_attribute_group', 2);
        $this->db->insert('feature_value', ['id_feature_value' => 3, 'id_feature' => 2]);
        $reader = new AttributeReader($this->db, $this->db->scope());

        $feature = ItemHashes::read($reader, [2], [], 'feature');
        $group = ItemHashes::read($reader, [2], [], 'attribute_group');
        self::assertCount(1, $feature);
        self::assertCount(1, $group);
        self::assertNotSame($feature, $group);
        self::assertSame(['3'], array_map('strval', array_keys(ItemHashes::read(new AttributeValueReader($this->db, $this->db->scope()), [3], ['kind' => 'feature']))));
    }

    /**
     * @param  array<array-key, string>  $map
     * @return array<array-key, string>
     */
    private function sorted(array $map): array
    {
        ksort($map);

        return $map;
    }
}
