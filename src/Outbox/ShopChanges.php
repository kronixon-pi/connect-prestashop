<?php

/**
 * Kronixon Connect for PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.
 * It is also available at https://opensource.org/licenses/AFL-3.0
 *
 * @author    Kronixon
 * @copyright 2026 Kronixon
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

declare(strict_types=1);

namespace Kronixon\Connect\Outbox;

use Kronixon\Connect\Catalog\Decimal;
use Kronixon\Connect\Catalog\Sql;

/**
 * What a hook of Spec §7.2 tells about a change, as Changes. Only the hook's parameters are read, except
 * for a price: the stored price before an update (one lookup by primary key) is compared with the one
 * after it, so `price.updated` means the price did change.
 */
final class ShopChanges
{
    /** @var Sql */
    private $sql;

    /** @var array<string, string|null> `table:id` => the price before the update */
    private $before = [];

    public function __construct(Sql $sql)
    {
        $this->sql = $sql;
    }

    /**
     * actionProductAdd, actionProductUpdate, actionObjectProductUpdateAfter.
     *
     * @param  array<string, mixed>  $params
     * @return list<Change>
     */
    public function product(array $params, bool $created): array
    {
        $id = self::id($params['id_product'] ?? null) ?? self::idOf($params['product'] ?? ($params['object'] ?? null));
        if ($id === null) {
            return [];
        }
        $changes = [new Change($created ? 'product.created' : 'product.updated', $id)];
        if (! $created && isset($params['object']) && $this->priceChanged('product', $id)) {
            $changes[] = new Change('price.updated', $id, ['variant_id' => '0'], '0');
        }

        return $changes;
    }

    /**
     * actionProductDelete.
     *
     * @param  array<string, mixed>  $params
     * @return list<Change>
     */
    public function productDeleted(array $params): array
    {
        $id = self::id($params['id_product'] ?? null) ?? self::idOf($params['product'] ?? null);

        return $id === null ? [] : [new Change('product.deleted', $id)];
    }

    /**
     * actionUpdateQuantity: one change per stock row, the quantity it was set to (8.1+ also say by how much).
     *
     * @param  array<string, mixed>  $params
     * @return list<Change>
     */
    public function quantity(array $params): array
    {
        $product = self::id($params['id_product'] ?? null);
        if ($product === null || ! is_numeric($params['quantity'] ?? null)) {
            return [];
        }
        $variant = self::id($params['id_product_attribute'] ?? null) ?? '0';
        $quantity = (int) $params['quantity'];
        $payload = ['variant_id' => $variant, 'quantity' => $quantity];
        if (is_numeric($params['delta_quantity'] ?? null)) {
            $payload['previous_quantity'] = $quantity - (int) $params['delta_quantity'];
        }

        return [new Change('stock.updated', $product, $payload, $variant)];
    }

    /**
     * actionObjectCombination{Add,Update,Delete}After.
     *
     * @param  array<string, mixed>  $params
     * @return list<Change>
     */
    public function combination(array $params, string $kind): array
    {
        $object = $params['object'] ?? null;
        $id = self::idOf($object);
        $product = is_object($object) ? self::id($object->id_product ?? null) : null;
        if ($id === null || $product === null) {
            return [];
        }
        $changes = [new Change('combination.'.$kind, $id, ['product_id' => $product])];
        if ($kind === 'updated' && $this->priceChanged('product_attribute', $id)) {
            $changes[] = new Change('price.updated', $product, ['variant_id' => $id], $id);
        }

        return $changes;
    }

    /**
     * actionObjectProductUpdateBefore and actionObjectCombinationUpdateBefore: the stored price, for the
     * update's After hook.
     *
     * @param  array<string, mixed>  $params
     */
    public function beforeUpdate(array $params, string $table): void
    {
        $id = self::idOf($params['object'] ?? null);
        if ($id !== null) {
            $this->before[$table.':'.$id] = $this->price($table, $id);
        }
    }

    /**
     * actionObjectCategory{Add,Update,Delete}After.
     *
     * @param  array<string, mixed>  $params
     * @return list<Change>
     */
    public function category(array $params, string $kind): array
    {
        $object = $params['object'] ?? null;
        $id = self::idOf($object);
        if ($id === null || ! is_object($object)) {
            return [];
        }

        return [new Change('category.'.$kind, $id, ['parent_id' => self::id($object->id_parent ?? null) ?? '0'])];
    }

    /**
     * actionObjectImage{Add,Delete}After.
     *
     * @param  array<string, mixed>  $params
     * @return list<Change>
     */
    public function image(array $params, string $kind): array
    {
        $object = $params['object'] ?? null;
        $id = self::idOf($object);
        $product = is_object($object) ? self::id($object->id_product ?? null) : null;
        if ($id === null || $product === null || ! is_object($object)) {
            return [];
        }
        $payload = ['product_id' => $product];
        if (is_numeric($object->position ?? null)) {
            $payload['position'] = (int) $object->position;
        }
        $payload['cover'] = (bool) ($object->cover ?? false);

        return [new Change('image.'.$kind, $id, $payload)];
    }

    /**
     * actionObjectManufacturerUpdateAfter.
     *
     * @param  array<string, mixed>  $params
     * @return list<Change>
     */
    public function brand(array $params): array
    {
        $id = self::idOf($params['object'] ?? null);

        return $id === null ? [] : [new Change('brand.updated', $id)];
    }

    /**
     * actionObjectFeatureValueAddAfter (kind feature), actionObjectAttributeAddAfter (1.7.8) and
     * actionObjectProductAttributeAddAfter (8.0+) (kind attribute_group).
     *
     * @param  array<string, mixed>  $params
     * @return list<Change>
     */
    public function attributeValue(array $params, string $kind): array
    {
        $object = $params['object'] ?? null;
        $id = self::idOf($object);
        if ($id === null || ! is_object($object)) {
            return [];
        }
        $parent = self::id($kind === 'feature' ? ($object->id_feature ?? null) : ($object->id_attribute_group ?? null));
        if ($parent === null) {
            return [];
        }

        return [new Change('attribute_value.created', $id, ['kind' => $kind, 'parent_id' => $parent])];
    }

    private function priceChanged(string $table, string $id): bool
    {
        $key = $table.':'.$id;
        if (! array_key_exists($key, $this->before)) {
            return false;
        }
        $before = $this->before[$key];
        unset($this->before[$key]);
        $after = $this->price($table, $id);

        return $before === null || $after === null || Decimal::money($before) !== Decimal::money($after);
    }

    private function price(string $table, string $id): ?string
    {
        $primary = $table === 'product' ? 'id_product' : 'id_product_attribute';
        $rows = $this->sql->rows('SELECT price FROM '.$this->sql->table($table).' WHERE '.$primary.' = '.(int) $id);

        return isset($rows[0]['price']) ? (string) $rows[0]['price'] : null;
    }

    /**
     * @param  mixed  $object
     */
    private static function idOf($object): ?string
    {
        return is_object($object) ? self::id($object->id ?? null) : null;
    }

    /**
     * A positive id as the protocol's decimal string.
     *
     * @param  mixed  $value
     */
    private static function id($value): ?string
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $id = (int) $value;

            return $id > 0 ? (string) $id : null;
        }

        return null;
    }
}
