<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Outbox;

use Kronixon\Connect\Outbox\Change;
use Kronixon\Connect\Outbox\ShopChanges;
use Kronixon\Connect\Tests\Support\CatalogDb;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The hooks' parameters as changes (Spec §7.2), on PrestaShop 1.7.8 and 8.1+ parameters.
 */
final class ShopChangesTest extends TestCase
{
    /** @var CatalogDb */
    private $db;

    /** @var ShopChanges */
    private $changes;

    protected function setUp(): void
    {
        $this->db = new CatalogDb();
        $this->db->demo();
        $this->changes = new ShopChanges($this->db);
    }

    public function test_products(): void
    {
        self::assertSame([['product.created', '7', []]], self::flat($this->changes->product(['id_product' => 7, 'product' => self::object(7)], true)));
        self::assertSame([['product.updated', '7', []]], self::flat($this->changes->product(['id_product' => '7'], false)));
        self::assertSame([['product.deleted', '7', []]], self::flat($this->changes->productDeleted(['id_product' => 7])));
        self::assertSame([], $this->changes->product(['id_product' => 0], false), 'no id, no change');
    }

    public function test_a_price_change_is_told_by_the_stored_price_before_and_after_the_update(): void
    {
        $product = self::object(1);
        $this->changes->beforeUpdate(['object' => $product], 'product');
        self::assertSame([['product.updated', '1', []]], self::flat($this->changes->product(['object' => $product], false)), 'same price');

        $this->changes->beforeUpdate(['object' => $product], 'product');
        $this->db->execute("UPDATE ps_product SET price = '89.990000' WHERE id_product = 1");
        self::assertSame([['product.updated', '1', []], ['price.updated', '1', ['variant_id' => '0']]], self::flat($this->changes->product(['object' => $product], false)));

        self::assertSame([['product.updated', '1', []]], self::flat($this->changes->product(['object' => $product], false)), 'no Before hook, no price comparison');
    }

    public function test_stock_rows_with_and_without_the_delta_of_8_1(): void
    {
        $legacy = $this->changes->quantity(['id_product' => 1, 'id_product_attribute' => 0, 'quantity' => 6]);
        self::assertSame([['stock.updated', '1', ['variant_id' => '0', 'quantity' => 6]]], self::flat($legacy));
        self::assertSame('stock.updated:1:0', $legacy[0]->key);

        $recent = $this->changes->quantity(['id_product' => 1, 'id_product_attribute' => 3, 'quantity' => 4, 'delta_quantity' => -2, 'id_shop' => 1]);
        self::assertSame([['stock.updated', '1', ['variant_id' => '3', 'quantity' => 4, 'previous_quantity' => 6]]], self::flat($recent));
        self::assertSame('stock.updated:1:3', $recent[0]->key);
        self::assertSame([], $this->changes->quantity(['id_product' => 1, 'quantity' => null]));
    }

    public function test_combinations_categories_images_brands_and_values(): void
    {
        $combination = self::object(3, ['id_product' => '1']);
        self::assertSame([['combination.created', '3', ['product_id' => '1']]], self::flat($this->changes->combination(['object' => $combination], 'created')));
        self::assertSame([['combination.deleted', '3', ['product_id' => '1']]], self::flat($this->changes->combination(['object' => $combination], 'deleted')));
        self::assertSame([['category.updated', '45', ['parent_id' => '2']]], self::flat($this->changes->category(['object' => self::object(45, ['id_parent' => 2])], 'updated')));
        self::assertSame([['image.created', '900', ['product_id' => '2', 'position' => 1, 'cover' => true]]], self::flat($this->changes->image(['object' => self::object(900, ['id_product' => 2, 'position' => '1', 'cover' => '1'])], 'created')));
        self::assertSame([['brand.updated', '12', []]], self::flat($this->changes->brand(['object' => self::object(12)])));
        self::assertSame([['attribute_value.created', '310', ['kind' => 'feature', 'parent_id' => '7']]], self::flat($this->changes->attributeValue(['object' => self::object(310, ['id_feature' => 7])], 'feature')));
        self::assertSame([['attribute_value.created', '25', ['kind' => 'attribute_group', 'parent_id' => '4']]], self::flat($this->changes->attributeValue(['object' => self::object(25, ['id_attribute_group' => '4'])], 'attribute_group')));
        self::assertSame([], $this->changes->image(['object' => null], 'deleted'));
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private static function object(int $id, array $fields = []): stdClass
    {
        $object = new stdClass();
        $object->id = $id;
        foreach ($fields as $name => $value) {
            $object->{$name} = $value;
        }

        return $object;
    }

    /**
     * @param  list<Change>  $changes
     * @return list<array{0: string, 1: string|null, 2: array<string, mixed>}>
     */
    private static function flat(array $changes): array
    {
        return array_map(static function (Change $change): array {
            return [$change->type, $change->externalId, $change->payload];
        }, $changes);
    }
}
