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

use Configuration;
use FeatureValue;
use Product;
use ProductSupplier;
use Tag;
use Validate;

/**
 * What a product is linked to: categories, feature values, tags and suppliers. The wanted*() methods
 * check what was sent before anything is written; the others compare it with what the paired shop has
 * and call PrestaShop's own methods only when they differ (true: changed); links to entities of other
 * shops are neither read nor touched. Only `mode: replace` removes a link that was not sent (of tags: in
 * the languages sent).
 */
final class ProductRelations
{
    /** @var Shop */
    private $shop;

    public function __construct(Shop $shop)
    {
        $this->shop = $shop;
    }

    /**
     * The categories the group asks for: the list with the default one first; null without the group.
     * A create without it, and a replacement by no category, belong to Home.
     *
     * @return array{ids: list<int>, default: int|null, replace: bool}|null
     */
    public function wantedCategories(?Item $group, bool $creating): ?array
    {
        if ($group === null && ! $creating) {
            return null;
        }
        $default = $group === null ? null : $group->id('default_id');
        $ids = [];
        foreach ($group === null ? [] : $group->strings('ids') ?? [] as $i => $id) {
            $ids[] = $this->held('category', (string) $id, 'categories.ids.'.$i, 'category_not_found');
        }
        if ($default !== null) {
            $this->held('category', (string) $default, 'categories.default_id', 'category_not_found');
            array_unshift($ids, $default);
        }
        $replace = $group !== null && $group->has('ids') && $group->choice('mode', ['replace', 'merge']) === 'replace';
        if ($ids === [] && ($creating || $replace)) {
            $ids = [$this->shop->scope->homeCategoryId];
        }

        return ['ids' => array_values(array_unique($ids)), 'default' => $default ?? ($creating ? $ids[0] : null), 'replace' => $replace];
    }

    /**
     * @param  array{ids: list<int>, default: int|null, replace: bool}  $wanted
     */
    public function categories(Product $product, array $wanted): bool
    {
        $current = [];
        foreach ($this->shop->db->rows('SELECT cp.id_category FROM '.$this->shop->db->table('category_product').' cp INNER JOIN '.$this->shop->db->table('category_shop')
            .' cs ON cs.id_category = cp.id_category AND cs.id_shop = '.$this->shop->scope->shopId.' WHERE cp.id_product = '.(int) $product->id) as $row) {
            $current[] = (int) $row['id_category'];
        }
        $missing = array_values(array_diff($wanted['ids'], $current));
        $extra = $wanted['replace'] ? array_diff($current, $wanted['ids']) : [];
        if ($missing === [] && $extra === []) {
            return false;
        }
        if (! ($extra === [] ? $product->addToCategories($missing) : $product->updateCategories($wanted['ids']))) {
            throw ItemError::notSaved('product categories');
        }

        return true;
    }

    /**
     * The default category: the one sent, else the first one sent when a replacement dropped the product's.
     *
     * @param  array{ids: list<int>, default: int|null, replace: bool}  $wanted
     */
    public function defaultCategory(Product $product, array $wanted): ?int
    {
        if ($wanted['default'] !== null) {
            return $wanted['default'];
        }

        return $wanted['replace'] && ! in_array((int) $product->id_category_default, $wanted['ids'], true) ? $wanted['ids'][0] : null;
    }

    /**
     * @param  array<int, array{ids: array<int, true>, custom: list<array<int, string>>}>  $wanted
     */
    public function features(Product $product, Item $group, array $wanted): bool
    {
        $current = $this->currentFeatures((int) $product->id);
        $final = $group->choice('mode', ['replace', 'merge']) === 'replace' ? [] : $current;
        foreach ($wanted as $feature => $values) {
            $have = $current[$feature] ?? ['ids' => [], 'custom' => []];
            if (isset($final[$feature])) {  // merge: the values sent join, a custom text takes the place of the one there
                $values['ids'] += $have['ids'];
                $values['custom'] = $values['custom'] === [] ? $have['custom'] : $values['custom'];
            }
            // A custom text in the languages sent; the languages not sent keep what the shop has.
            if (count($values['custom']) === 1 && count($have['custom']) === 1) {
                $values['custom'] = [$values['custom'][0] + $have['custom'][0]];
            }
            $final[$feature] = $values;
        }
        foreach ($final as $feature => $values) {
            $final[$feature]['custom'] = array_map(function (array $texts): array {
                return $texts + array_fill_keys(array_keys($this->shop->scope->languages), $this->shop->inDefaultLanguage($texts));
            }, $values['custom']);
        }
        if (self::comparable($final) === self::comparable($current)) {
            return false;
        }
        $ok = $product->deleteFeatures();
        foreach ($final as $feature => $values) {
            foreach (array_keys($values['ids']) as $valueId) {
                $product->addFeaturesToDB($feature, $valueId);
            }
            foreach ($values['custom'] as $texts) {
                $value = new FeatureValue();
                $value->id_feature = $feature;
                $value->custom = 1;
                $value->value = $texts;
                $ok = $this->shop->pending->add($value) && $ok;
                $product->addFeaturesToDB($feature, (int) $value->id);
            }
        }
        if (! $ok) {
            throw ItemError::notSaved('product features');
        }

        return true;
    }

    /**
     * The tags sent, by language id and the form they are compared in.
     *
     * @return array<int, array<string, string>>
     */
    public function wantedTags(Item $group): array
    {
        $wanted = [];
        foreach ($group->lists('values') ?? [] as $iso => $names) {
            $langId = $this->shop->texts->known((string) $iso, 'tags.values');
            if ($langId === null) {
                continue;
            }
            $wanted[$langId] = [];
            foreach ($names as $i => $name) {
                $name = Texts::oneLine($name);
                if ($name === '' || mb_strlen($name, 'UTF-8') > TagWriter::LENGTH || ! Validate::isGenericName($name)) {
                    throw ItemError::invalid('tags.values.'.$iso.'.'.$i);
                }
                $wanted[$langId][Texts::matchKey($name)] = $name;
            }
        }

        return $wanted;
    }

    /**
     * `mode: replace` makes the list sent a language's tags; `merge`, and no mode, adds to them.
     * PrestaShop deletes a tag no product has when a product's tags are replaced (its own rule).
     *
     * @param  array<int, array<string, string>>  $tags  wantedTags()
     */
    public function tags(Product $product, Item $group, array $tags): bool
    {
        $replace = $group->choice('mode', ['replace', 'merge']) === 'replace';
        $changed = false;
        foreach ($tags as $langId => $wanted) {
            $current = [];
            foreach ($this->shop->db->rows('SELECT t.name FROM '.$this->shop->db->table('product_tag').' pt INNER JOIN '.$this->shop->db->table('tag')
                .' t ON t.id_tag = pt.id_tag WHERE pt.id_product = '.(int) $product->id.' AND pt.id_lang = '.$langId) as $row) {
                $current[Texts::matchKey((string) $row['name'])] = true;
            }
            $missing = array_diff_key($wanted, $current);
            if ($missing === [] && (! $replace || array_diff_key($current, $wanted) === [])) {
                continue;
            }
            if ($replace) {
                Tag::deleteProductTagsInLang((int) $product->id, $langId);
            }
            $add = $replace ? $wanted : $missing;
            if ($add !== [] && ! Tag::addTags($langId, (int) $product->id, array_values($add))) {
                throw ItemError::notSaved('product tags');
            }
            $changed = true;
        }

        return $changed;
    }

    /**
     * The supplier rows sent, by supplier id.
     *
     * @return array<int, array{reference: string|null, price: string|null, currency: int|null}>
     */
    public function wantedSuppliers(Item $group): array
    {
        $wanted = [];
        foreach ($group->objects('rows') ?? [] as $row) {
            $supplier = $this->held('supplier', (string) $row->string('supplier_id'), $row->at('supplier_id'), 'supplier_not_found');
            $iso = $row->string('currency');
            $currency = $iso === null ? null : array_search($iso, $this->shop->scope->currencies, true);
            if ($currency === false) {
                throw ItemError::missing($row->at('currency'), 'currency_not_found');
            }
            $reference = $row->string('reference', 64);
            if ($reference !== null && ! Validate::isReference($reference)) {
                throw ItemError::invalid($row->at('reference'));
            }
            $wanted[$supplier] = ['reference' => $reference, 'price' => $row->money('price_te'), 'currency' => $currency];
        }

        return $wanted;
    }

    /**
     * @param  array<int, array{reference: string|null, price: string|null, currency: int|null}>  $wanted  wantedSuppliers()
     */
    public function suppliers(Product $product, Item $group, array $wanted): bool
    {
        $changed = false;
        foreach ($wanted as $supplier => $row) {
            $id = (int) ProductSupplier::getIdByProductAndSupplier((int) $product->id, 0, $supplier);
            $fields = $this->shop->fields(new ProductSupplier($id === 0 ? null : $id));
            if ($id === 0) {
                $fields->set('id_product', (int) $product->id);
                $fields->set('id_product_attribute', 0);
                $fields->set('id_supplier', $supplier);
            }
            $fields->set('product_supplier_reference', $row['reference'] ?? ($id === 0 ? '' : null));
            $fields->amount('product_supplier_price_te', $row['price'] ?? ($id === 0 ? '0' : null));
            $fields->set('id_currency', $row['currency'] ?? ($id === 0 ? (int) Configuration::get('PS_CURRENCY_DEFAULT') : null));
            $changed = $changed || $id === 0 || $fields->changed();
            $fields->save('product supplier');
        }
        if ($group->has('rows') && $group->choice('mode', ['replace', 'merge']) === 'replace') {
            foreach ($this->shop->db->rows('SELECT ps.id_product_supplier, ps.id_supplier FROM '.$this->shop->db->table('product_supplier').' ps INNER JOIN '.$this->shop->db->table('supplier_shop')
                .' s ON s.id_supplier = ps.id_supplier AND s.id_shop = '.$this->shop->scope->shopId.' WHERE ps.id_product_attribute = 0 AND ps.id_product = '.(int) $product->id) as $row) {
                if (! isset($wanted[(int) $row['id_supplier']])) {
                    (new ProductSupplier((int) $row['id_product_supplier']))->delete();
                    $changed = true;
                }
            }
        }

        return $changed;
    }

    /** An id of an entity the paired shop has. */
    public function held(string $table, string $id, string $field, string $code): int
    {
        if (preg_match('/\A[1-9][0-9]{0,17}\z/', $id) !== 1) {
            throw ItemError::invalid($field);
        }
        if (! $this->shop->holds($table, (int) $id)) {
            throw ItemError::missing($field, $code);
        }

        return (int) $id;
    }

    /**
     * The values sent by feature: predefined ids, custom texts by language.
     *
     * @return array<int, array{ids: array<int, true>, custom: list<array<int, string>>}>
     */
    public function wantedFeatures(Item $group): array
    {
        $wanted = [];
        foreach ($group->objects('values') ?? [] as $row) {
            $feature = $this->held('feature', (string) $row->string('feature_id'), $row->at('feature_id'), 'feature_not_found');
            $wanted[$feature] = $wanted[$feature] ?? ['ids' => [], 'custom' => []];
            if ($row->has('value_id')) {
                $value = $row->id('value_id') ?? 0;
                if ($this->shop->db->rows('SELECT 1 FROM '.$this->shop->db->table('feature_value').' WHERE id_feature_value = '.$value.' AND id_feature = '.$feature) === []) {
                    throw ItemError::missing($row->at('value_id'), 'feature_value_not_found');
                }
                $wanted[$feature]['ids'][$value] = true;
                continue;
            }
            $texts = $this->shop->texts->name($row, 'custom', false);
            if ($texts === null || array_filter($texts, [self::class, 'isCustomText']) !== $texts) {
                throw ItemError::invalid($row->at('custom'));
            }
            if ($texts !== []) {  // nothing in a language of the shop: warned about
                $wanted[$feature]['custom'][] = $texts;
            }
        }

        return $wanted;
    }

    /** `FeatureValue::$value`. */
    private static function isCustomText(string $text): bool
    {
        return mb_strlen($text, 'UTF-8') <= 255 && Validate::isGenericName($text);
    }

    /**
     * The product's values of the shop's features; custom ones as their texts by language.
     *
     * @return array<int, array{ids: array<int, true>, custom: list<array<int, string>>}>
     */
    private function currentFeatures(int $productId): array
    {
        $current = [];
        $custom = [];
        foreach ($this->shop->db->rows('SELECT fp.id_feature, fp.id_feature_value, fv.custom FROM '.$this->shop->db->table('feature_product').' fp INNER JOIN '
            .$this->shop->db->table('feature_shop').' fs ON fs.id_feature = fp.id_feature AND fs.id_shop = '.$this->shop->scope->shopId.' LEFT JOIN '
            .$this->shop->db->table('feature_value').' fv ON fv.id_feature_value = fp.id_feature_value WHERE fp.id_product = '.$productId.' ORDER BY fp.id_feature_value') as $row) {
            $feature = (int) $row['id_feature'];
            $current[$feature] = $current[$feature] ?? ['ids' => [], 'custom' => []];
            if ((int) $row['custom'] === 1) {
                $custom[(int) $row['id_feature_value']] = $feature;
            } else {
                $current[$feature]['ids'][(int) $row['id_feature_value']] = true;
            }
        }
        $texts = [];
        if ($custom !== []) {
            foreach ($this->shop->db->rows('SELECT id_feature_value, id_lang, value FROM '.$this->shop->db->table('feature_value_lang')
                .' WHERE id_feature_value IN ('.implode(',', array_keys($custom)).')') as $row) {
                $texts[(int) $row['id_feature_value']][(int) $row['id_lang']] = (string) $row['value'];
            }
        }
        foreach ($custom as $valueId => $feature) {
            $current[$feature]['custom'][] = $texts[$valueId] ?? [];
        }

        return $current;
    }

    /**
     * @param  array<int, array{ids: array<int, true>, custom: list<array<int, string>>}>  $features
     * @return array<int, array{0: list<int>, 1: list<string>}>
     */
    private static function comparable(array $features): array
    {
        $comparable = [];
        ksort($features);
        foreach ($features as $feature => $values) {
            $ids = array_keys($values['ids']);
            sort($ids);
            $custom = array_map(static function (array $texts): string {
                ksort($texts);

                return (string) json_encode($texts);
            }, $values['custom']);
            sort($custom);
            if ($ids !== [] || $custom !== []) {
                $comparable[$feature] = [$ids, $custom];
            }
        }

        return $comparable;
    }
}
