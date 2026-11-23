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

namespace Kronixon\Connect\Write;

use Cache;
use Combination;
use Kronixon\Connect\Catalog\ProductReader;
use Kronixon\Connect\Catalog\Reader;
use Kronixon\Connect\Catalog\VariantReader;
use Product;
use StockAvailable;
use Tools;

/**
 * `POST variants` (module/variants.json): the option groups, values and combinations of one product in
 * one transaction, through `AttributeGroup`, `Attribute`/`ProductAttribute` and `Combination`.
 *
 * Combinations are found by `external_id`, `kx_id` or their set of values and then updated; only
 * `mode: replace` deletes the product's other combinations of the paired shop. Option groups and
 * values belong to the whole shop, not to the product: they are found or created, never renamed here
 * (POST attributes and attribute-values do that). `content_hash` is the product's.
 */
final class VariantWriter extends ShopWriter
{
    /** @var OptionGroups */
    private $options;

    /** @var int the product of the item being written */
    private $productId = 0;

    public function __construct(Shop $shop)
    {
        parent::__construct($shop);
        $this->options = new OptionGroups($shop);
    }

    public function write(Item $item): array
    {
        $productId = $item->id('product_id');
        if ($productId === null || ! $this->shop->holds('product', $productId)) {
            throw ItemError::missing('product_id', 'product_not_found');
        }
        $product = new Product($productId, false, null, $this->shop->scope->shopId);
        if (in_array((string) $product->product_type, ['pack', 'virtual'], true)) {
            throw new ItemError('product_type_unsupported', 'Packs and virtual products have no combinations', 'product_id');
        }
        $combinations = $item->objects('combinations');
        $mode = $item->choice('mode', ['replace', 'merge']);
        if ($combinations === null || ! $item->has('option_groups')) {
            throw ItemError::invalid($combinations === null ? 'combinations' : 'option_groups');
        }
        $this->productId = $productId;
        $current = $this->current($productId);
        if ($this->left(array_keys($current)) !== []) {
            throw ItemError::leftOver('product_id');
        }
        $this->options->read($item->objects('option_groups') ?? []);
        $refs = $this->check($combinations, $current);

        $changed = $this->options->create();
        $ids = [];
        $default = null;
        foreach ($combinations as $combination) {
            $values = $this->options->attributeIds($refs[$combination->key()]);
            $id = $this->resolve($combination, $values, $current);
            if ($id !== null && in_array($id, $ids, true)) {
                throw new ItemError('combination_duplicate', 'Two combinations are the same', $combination->at('values'));
            }
            [$id, $written] = $this->combination($productId, $id, $combination, $values, $current);
            $ids[$combination->key()] = $id;
            $changed = $written || $changed;
            $default = $default ?? ($combination->bool('default') === true ? $id : null);
            $this->shop->map->link(VariantReader::ENTITY, $id, $combination->kxId());
        }
        foreach ($mode === 'replace' ? array_diff(array_keys($current), $ids) : [] as $other) {
            if (! (new Combination($other))->delete()) {
                throw ItemError::notSaved('combinations');
            }
            $changed = true;
        }
        $changed = $this->makeDefault($productId, $default) || $changed;
        if ($changed) {
            $this->touch($productId);
        }

        return self::done($productId, false, $changed) + $this->options->ids() + ['combinations' => (object) array_map('strval', $ids)];
    }

    public function prepare(Item $item): void
    {
        parent::prepare($item);
        $this->productId = 0;
    }

    public function rolledBack(Item $item): bool
    {
        $clean = parent::rolledBack($item);
        if (! $clean && $this->productId > 0) {
            $this->noHalfDefault($this->productId);
        }

        return $clean;
    }

    protected function reader(): Reader
    {
        return new ProductReader($this->shop->db, $this->shop->scope, $this->shop->urls);
    }

    /**
     * The combinations a failed item left.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function left(array $ids): array
    {
        return array_values(array_filter($ids, function (int $id): bool {
            return $this->shop->pending->isLeft('product_attribute', $id);
        }));
    }

    /** PrestaShop makes any combination that is there the default one: never a half-written one. */
    private function noHalfDefault(int $productId): void
    {
        $ids = array_keys($this->current($productId));
        $left = $this->left($ids);
        if ($left === []) {
            return;
        }
        $whole = array_values(array_diff($ids, $left));
        Cache::clean('objectmodel_Product_'.$productId.'_*');
        $product = new Product($productId, false, null, $this->shop->scope->shopId);
        $default = $whole === [] ? [] : $this->shop->db->rows('SELECT id_product_attribute FROM '.$this->shop->db->table('product_attribute_shop')
            .' WHERE default_on = 1 AND id_shop = '.$this->shop->scope->shopId.' AND id_product_attribute IN ('.implode(',', $whole).')');
        $product->deleteDefaultAttributes();
        if ($whole !== []) {
            $product->setDefaultAttribute((int) ($default[0]['id_product_attribute'] ?? $whole[0]));

            return;
        }
        $fields = $this->shop->fields($product);
        $fields->set('cache_default_attribute', 0);
        $fields->save('product');
    }

    /**
     * Everything that can be refused of the combinations, before anything is written.
     *
     * @param  list<Item>  $combinations
     * @param  array<int, list<int>>  $current
     * @return array<string, list<string>> combination key => its value references
     */
    private function check(array $combinations, array $current): array
    {
        $refs = [];
        foreach ($combinations as $combination) {
            if ($combination->key() === '' || isset($refs[$combination->key()])) {
                throw ItemError::invalid($combination->at('key'));
            }
            $id = $combination->id('external_id');
            if ($id !== null && ! isset($current[$id])) {
                throw ItemError::missing($combination->at('external_id'));
            }
            $combination->bool('default');
            $combination->strings('image_keys');
            $combination->int('quantity_initial', PHP_INT_MIN);
            $this->fields(new Combination(), $combination, 1)->check();
            $values = $this->options->references($combination);
            if (in_array($values, $refs, true)) {
                throw new ItemError('combination_duplicate', 'Two combinations are the same', $combination->at('values'));
            }
            $refs[$combination->key()] = $values;
        }

        return $refs;
    }

    private function fields(Combination $combination, Item $item, int $productId): Fields
    {
        $fields = $this->shop->fields($combination)->sentAs(['reference' => $item->at('reference'), 'ean13' => $item->at('ean13'), 'mpn' => $item->at('mpn'), 'price' => $item->at('price_impact'),
            'wholesale_price' => $item->at('wholesale_price'), 'weight' => $item->at('weight_impact'), 'minimal_quantity' => $item->at('minimal_quantity'), ]);
        $creating = $fields->creating();
        if ($creating) {
            $fields->set('id_product', $productId);
            $combination->minimal_quantity = 1;
        }
        foreach (['reference' => 64, 'ean13' => 13, 'mpn' => 40] as $code => $length) {
            $fields->set($code, $item->string($code, $length) ?? ($creating ? '' : null));
        }
        foreach (['price' => 'price_impact', 'wholesale_price' => 'wholesale_price', 'weight' => 'weight_impact'] as $field => $member) {
            $fields->amount($field, $item->money($member) ?? ($creating ? '0' : null));
        }
        $fields->set('minimal_quantity', $item->int('minimal_quantity', 1));

        return $fields;
    }

    /**
     * The product's combinations in the paired shop: id => its sorted attribute ids.
     *
     * @return array<int, list<int>>
     */
    private function current(int $productId): array
    {
        $current = [];
        foreach ($this->shop->db->rows('SELECT pa.id_product_attribute, pac.id_attribute FROM '.$this->shop->db->table('product_attribute').' pa INNER JOIN '
            .$this->shop->db->table('product_attribute_shop').' pas ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = '.$this->shop->scope->shopId
            .' LEFT JOIN '.$this->shop->db->table('product_attribute_combination').' pac ON pac.id_product_attribute = pa.id_product_attribute'
            .' WHERE pa.id_product = '.$productId.' ORDER BY pa.id_product_attribute, pac.id_attribute') as $row) {
            $id = (int) $row['id_product_attribute'];
            $current[$id] = $current[$id] ?? [];
            if ($row['id_attribute'] !== null) {
                $current[$id][] = (int) $row['id_attribute'];
            }
        }

        return $current;
    }

    /**
     * @param  list<int>  $values
     * @param  array<int, list<int>>  $current
     */
    private function resolve(Item $combination, array $values, array $current): ?int
    {
        return $this->shop->map->resolve($combination, VariantReader::ENTITY, static function (int $id) use ($current): bool {
            return isset($current[$id]);
        }, static function () use ($values, $current): array {
            $same = array_search($values, $current, true);

            return $same === false ? [] : [$same];
        });
    }

    /**
     * @param  list<int>  $values
     * @param  array<int, list<int>>  $current
     * @return array{0: int, 1: bool} id and whether anything was written
     */
    private function combination(int $productId, ?int $id, Item $item, array $values, array $current): array
    {
        $creating = $id === null;
        $combination = new Combination($id, null, $this->shop->scope->shopId);
        $fields = $this->fields($combination, $item, $productId);
        $changed = $creating || $fields->changed();
        $fields->save('combination');
        $id = (int) $combination->id;
        if ($creating || $current[$id] !== $values) {
            $images = $this->images($id);
            // setAttributes() also removes the combination's images.
            if (! $combination->setAttributes($values) || ! $combination->setImages($images)) {
                throw ItemError::notSaved('combination');
            }
            $changed = true;
        }
        $quantity = $item->int('quantity_initial', PHP_INT_MIN);
        if ($creating && $quantity !== null) {
            StockAvailable::setQuantity($productId, $id, $quantity, $this->shop->scope->shopId, false);
        }

        return [$id, $this->showImages($combination, $productId, $item->strings('image_keys')) || $changed];
    }

    /**
     * The pictures sent by their client keys (kx_image_map); pictures the shop added itself stay.
     *
     * @param  list<string>|null  $keys
     */
    private function showImages(Combination $combination, int $productId, ?array $keys): bool
    {
        if ($keys === null) {
            return false;
        }
        $known = [];
        foreach ($this->shop->db->rows('SELECT id_image, kx_key FROM '.$this->shop->db->table('kx_image_map').' WHERE id_product = '.$productId) as $row) {
            $known[(string) $row['kx_key']] = (int) $row['id_image'];
        }
        if (array_diff($keys, array_map('strval', array_keys($known))) !== []) {
            $this->shop->warnings->add('image_key_unknown');
        }
        $current = $this->images((int) $combination->id);
        $wanted = array_values(array_unique(array_merge(array_diff($current, $known), array_intersect_key($known, array_flip($keys)))));
        sort($wanted);
        if ($wanted === $current) {
            return false;
        }
        if (! $combination->setImages($wanted)) {
            throw ItemError::notSaved('combination images');
        }

        return true;
    }

    /**
     * @return list<int> ascending
     */
    private function images(int $combinationId): array
    {
        $ids = array_map(static function (array $row): int {
            return (int) $row['id_image'];
        }, $this->shop->db->rows('SELECT id_image FROM '.$this->shop->db->table('product_attribute_image').' WHERE id_product_attribute = '.$combinationId.' AND id_image > 0'));
        sort($ids);

        return $ids;
    }

    /** One combination is the default: the one sent as such, else the one there, else the first. */
    private function makeDefault(int $productId, ?int $wanted): bool
    {
        $rows = $this->shop->db->rows('SELECT pas.id_product_attribute, pas.default_on FROM '.$this->shop->db->table('product_attribute_shop').' pas INNER JOIN '
            .$this->shop->db->table('product_attribute').' pa ON pa.id_product_attribute = pas.id_product_attribute WHERE pa.id_product = '.$productId
            .' AND pas.id_shop = '.$this->shop->scope->shopId.' ORDER BY pas.id_product_attribute');
        $now = null;
        foreach ($rows as $row) {
            $now = (int) $row['default_on'] === 1 ? (int) $row['id_product_attribute'] : $now;
        }
        $wanted = $wanted ?? $now ?? (isset($rows[0]) ? (int) $rows[0]['id_product_attribute'] : null);
        if ($wanted === null || $wanted === $now) {
            return false;
        }
        Cache::clean('objectmodel_Product_'.$productId.'_*');
        $product = new Product($productId, false, null, $this->shop->scope->shopId);
        if (! $product->deleteDefaultAttributes() || ! $product->setDefaultAttribute($wanted)) {
            throw ItemError::notSaved('default combination');
        }

        return true;
    }

    /** The product changed with its combinations: its type, its `date_upd`, its hooks and search index. */
    private function touch(int $productId): void
    {
        Cache::clean('objectmodel_Product_'.$productId.'_*');
        $product = new Product($productId, false, null, $this->shop->scope->shopId);
        $fields = $this->shop->fields($product);
        $fields->set('product_type', $this->current($productId) === [] ? 'standard' : 'combinations');
        $fields->set('indexed', false);
        $fields->touch('date_upd');
        $fields->save('product');
        if (is_callable([Tools::class, 'clearColorListCache'])) {  // until PrestaShop 8
            Tools::clearColorListCache($productId);
        }
    }
}
