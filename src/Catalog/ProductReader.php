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

namespace Kronixon\Connect\Catalog;

use Kronixon\Connect\Api\ApiError;
use Kronixon\Connect\Api\Request;

/**
 * `GET catalog/products` (module/catalog-products.json): the products of the paired shop by id, with
 * their texts per language, categories, features, tags, suppliers, images and the default stock row.
 * One page costs a fixed number of queries whatever its size (base rows, then one query per related
 * table for the page's ids); `fields=basic` reads only the base rows, names, categories and stock.
 *
 * Parameters besides paging: `ids` (up to 100), `active` (`all`, `1`, `0`), `fields` (`basic`, `full`).
 */
final class ProductReader implements Reader
{
    public const ENTITY = 'product';

    private const FULL_TEXTS = ['name', 'description_short', 'description', 'link_rewrite', 'meta_title',
        'meta_description', 'available_now', 'available_later', ];

    private const TYPES = ['combinations' => 'variable', 'pack' => 'pack', 'virtual' => 'virtual', 'standard' => 'simple'];

    /** The image type of `images[].url` when the shop has it; the original image otherwise. */
    private const IMAGE_TYPE = 'large_default';

    /** @var Sql */
    private $sql;

    /** @var Scope */
    private $scope;

    /** @var ImageUrls */
    private $urls;

    public function __construct(Sql $sql, Scope $scope, ImageUrls $urls)
    {
        $this->sql = $sql;
        $this->scope = $scope;
        $this->urls = $urls;
    }

    public function maxLimit(): int
    {
        return 500;
    }

    public function maxId(): string
    {
        return Rows::id($this->sql->rows('SELECT MAX(id_product) AS m FROM '.$this->sql->table('product'))[0]['m'] ?? null);
    }

    public function maxCursorVariant(Request $request): ?int
    {
        return null;
    }

    /**
     * @throws ApiError 400 for an invalid `ids`, `active` or `fields`
     */
    public function read(PageQuery $query): Page
    {
        $full = self::choice($query, 'fields', ['full', 'basic']) === 'full';
        $active = self::choice($query, 'active', ['all', '1', '0']);
        $ids = $query->param('ids');
        if ($ids !== null && preg_match('/\A[1-9][0-9]{0,18}(,[1-9][0-9]{0,18}){0,99}\z/', $ids) !== 1) {
            throw ApiError::badQuery(['ids']);
        }
        if ($query->afterId >= $query->untilId) {
            return Page::none();
        }

        $where = 'p.id_product > '.$query->afterId.' AND p.id_product <= '.$query->untilId;
        if ($active !== 'all') {
            $where .= ' AND ps.active = '.(int) $active;
        }
        if ($query->since !== null) {
            $where .= " AND ps.date_upd >= '".$query->since."'";
        }
        if ($ids !== null) {
            $where .= ' AND p.id_product IN ('.Rows::in(array_map('intval', explode(',', $ids))).')';
        }
        // The brand and the default supplier only when the shop has them (else "0", like no brand). As
        // subqueries of the page's rows: a join to these small tables made MySQL buffer and sort the
        // whole id range instead of stopping at LIMIT.
        $inShop = function (string $table, string $column): string {
            return '(SELECT x.'.$column.' FROM '.$this->sql->table($table).' x WHERE x.'.$column.' = p.'.$column.' AND x.id_shop = '.$this->scope->shopId.') AS '.$column;
        };
        $rows = $this->sql->rows('SELECT p.id_product, '.$inShop('supplier_shop', 'id_supplier').', '.$inShop('manufacturer_shop', 'id_manufacturer').','
            .' p.reference, p.ean13, p.upc, p.mpn,'
            .' p.isbn, p.supplier_reference, p.weight, p.width, p.height, p.depth, p.is_virtual, p.cache_is_pack, p.product_type,'
            .' ps.cache_default_attribute, ps.active, ps.visibility, ps.available_for_order, ps.show_price, ps.`condition`,'
            .' ps.online_only, ps.id_category_default, ps.price, ps.wholesale_price, ps.id_tax_rules_group, ps.unity,'
            .' ps.unit_price_ratio, '.($this->scope->unitPriceColumn ? 'ps.unit_price' : 'NULL AS unit_price').','
            .' ps.redirect_type, ps.id_type_redirected, ps.date_add, ps.date_upd'
            .' FROM '.$this->sql->table('product').' p INNER JOIN '.$this->sql->table('product_shop')
            .' ps ON ps.id_product = p.id_product AND ps.id_shop = '.$this->scope->shopId
            .' WHERE '.$where.' ORDER BY p.id_product LIMIT '.($query->limit + 1));
        $more = count($rows) > $query->limit;
        $rows = array_slice($rows, 0, $query->limit);
        $ids = array_map(static function (array $row): int {
            return (int) $row['id_product'];
        }, $rows);

        $related = $this->related($ids, $full);
        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->item($row, $related, $full);
        }

        return new Page($items, $more, static function (array $item): string {
            return Cursor::encode((int) $item['external_id']);
        });
    }

    /**
     * Everything a page's products carry besides their base row, one query per table.
     *
     * @param  list<int>  $ids
     * @return array<string, array<int, mixed>>
     */
    private function related(array $ids, bool $full): array
    {
        $in = Rows::in($ids);
        $texts = $full ? self::FULL_TEXTS : ['name'];
        $related = [
            'texts' => Rows::multilang($this->scope, $this->sql->rows('SELECT id_product, id_lang, '.implode(', ', $texts)
                .' FROM '.$this->sql->table('product_lang').' WHERE id_shop = '.$this->scope->shopId.' AND id_product IN ('.$in.')'), 'id_product', $texts),
            'kx' => Rows::kxIds($this->sql, self::ENTITY, $ids),
            'categories' => [], 'stock' => [], 'features' => [], 'tags' => [], 'suppliers' => [], 'images' => [],
        ];
        // Relations to other entities only where the shop has that entity (a multistore shop shares
        // products, not its category tree, features or suppliers). A LEFT JOIN read from the page's ids
        // and filtered here: with an inner join MySQL may start from the small *_shop table and walk
        // every product of each category or feature.
        foreach ($this->sql->rows('SELECT cp.id_product, cp.id_category, cs.id_shop FROM '.$this->sql->table('category_product').' cp LEFT JOIN '
            .$this->sql->table('category_shop').' cs ON cs.id_category = cp.id_category AND cs.id_shop = '.$this->scope->shopId.' WHERE cp.id_product IN ('.$in.')') as $row) {
            if ($row['id_shop'] !== null) {
                $related['categories'][(int) $row['id_product']][] = (int) $row['id_category'];
            }
        }
        foreach ($this->sql->rows('SELECT id_product, quantity, out_of_stock, depends_on_stock FROM '.$this->sql->table('stock_available')
            .' sa WHERE '.$this->scope->stockWhere('sa').' AND sa.id_product_attribute = 0 AND sa.id_product IN ('.$in.')') as $row) {
            $related['stock'][(int) $row['id_product']] = $row;
        }
        if (! $full) {
            return $related;
        }
        foreach ($this->sql->rows('SELECT fp.id_product, fp.id_feature, fp.id_feature_value, fv.custom, fs.id_shop FROM '.$this->sql->table('feature_product')
            .' fp LEFT JOIN '.$this->sql->table('feature_shop').' fs ON fs.id_feature = fp.id_feature AND fs.id_shop = '.$this->scope->shopId
            .' LEFT JOIN '.$this->sql->table('feature_value').' fv ON fv.id_feature_value = fp.id_feature_value WHERE fp.id_product IN ('.$in.')') as $row) {
            if ($row['id_shop'] !== null) {
                $related['features'][(int) $row['id_product']][] = [(int) $row['id_feature'], (int) $row['id_feature_value'], Rows::bool($row['custom'])];
            }
        }
        foreach ($this->sql->rows('SELECT pt.id_product, pt.id_lang, t.name FROM '.$this->sql->table('product_tag').' pt INNER JOIN '
            .$this->sql->table('tag').' t ON t.id_tag = pt.id_tag WHERE pt.id_product IN ('.$in.')') as $row) {
            $iso = $this->scope->languages[(int) $row['id_lang']] ?? null;
            if ($iso !== null) {
                $related['tags'][(int) $row['id_product']][(int) $row['id_lang']][] = Rows::text($row['name']);
            }
        }
        foreach ($this->sql->rows('SELECT psu.id_product, psu.id_supplier, psu.product_supplier_reference, psu.product_supplier_price_te, psu.id_currency, ss.id_shop FROM '
            .$this->sql->table('product_supplier').' psu LEFT JOIN '.$this->sql->table('supplier_shop').' ss ON ss.id_supplier = psu.id_supplier AND ss.id_shop = '
            .$this->scope->shopId.' WHERE psu.id_product_attribute = 0 AND psu.id_product IN ('.$in.')') as $row) {
            if ($row['id_shop'] !== null) {
                $related['suppliers'][(int) $row['id_product']][(int) $row['id_supplier']] = $row;
            }
        }
        foreach ($this->sql->rows('SELECT i.id_product, i.id_image, i.position, ish.cover, m.sha256 FROM '.$this->sql->table('image').' i INNER JOIN '
            .$this->sql->table('image_shop').' ish ON ish.id_image = i.id_image AND ish.id_shop = '.$this->scope->shopId.' LEFT JOIN '
            .$this->sql->table('kx_image_map').' m ON m.id_image = i.id_image WHERE i.id_product IN ('.$in.')') as $row) {
            $related['images'][(int) $row['id_product']][] = $row;
        }

        return $related;
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  array<string, array<int, mixed>>  $related
     * @return array<string, mixed>
     */
    private function item(array $row, array $related, bool $full): array
    {
        $id = (int) $row['id_product'];
        $categories = $related['categories'][$id] ?? [];
        sort($categories);
        $stock = $related['stock'][$id] ?? null;
        $texts = $related['texts'];

        $item = [
            'external_id' => (string) $id,
            'kx_id' => $related['kx'][$id] ?? null,
            'type' => $this->type($row),
            'reference' => Rows::text($row['reference']),
            'ean13' => Rows::text($row['ean13']),
            'upc' => Rows::text($row['upc']),
            'mpn' => Rows::text($row['mpn']),
            'isbn' => Rows::text($row['isbn']),
            'active' => Rows::bool($row['active']),
            'brand_id' => Rows::id($row['id_manufacturer']),
            'default_category_id' => Rows::id($row['id_category_default']),
            'category_ids' => array_map('strval', array_values(array_unique($categories))),
            'price' => Decimal::money($row['price']),
            'name' => Rows::field($texts, $id, 'name'),
        ];
        if ($full) {
            $item += [
                'supplier_reference' => Rows::text($row['supplier_reference']),
                'visibility' => in_array($row['visibility'], ['both', 'catalog', 'search', 'none'], true) ? (string) $row['visibility'] : 'both',
                'available_for_order' => Rows::bool($row['available_for_order']),
                'show_price' => Rows::bool($row['show_price']),
                'condition' => in_array($row['condition'], ['new', 'used', 'refurbished'], true) ? (string) $row['condition'] : 'new',
                'online_only' => Rows::bool($row['online_only']),
                'supplier_id' => Rows::id($row['id_supplier']),
                'wholesale_price' => Decimal::money($row['wholesale_price']),
                'tax_rules_group_id' => (int) $row['id_tax_rules_group'],
                'unit_price' => $this->scope->unitPriceColumn ? Decimal::money($row['unit_price']) : Decimal::divide((string) $row['price'], (string) $row['unit_price_ratio']),
                'unity' => Rows::text($row['unity']),
                'weight' => Decimal::money($row['weight']),
                'width' => Decimal::money($row['width']),
                'height' => Decimal::money($row['height']),
                'depth' => Decimal::money($row['depth']),
            ];
            foreach (array_slice(self::FULL_TEXTS, 1) as $field) {
                $item[$field] = Rows::field($texts, $id, $field);
            }
            $item += [
                'redirect' => ['type' => (string) $row['redirect_type'], 'target_id' => Rows::id($row['id_type_redirected'])],
                'attributes' => $this->features($related['features'][$id] ?? []),
                'tags' => $this->tags($related['tags'][$id] ?? []),
                'suppliers' => $this->suppliers($related['suppliers'][$id] ?? []),
                'images' => $this->images($related['images'][$id] ?? [], $texts[$id]['link_rewrite'][$this->scope->languages[$this->scope->defaultLangId] ?? ''] ?? ''),
                'out_of_stock' => $stock === null ? 2 : min(2, max(0, (int) $stock['out_of_stock'])),
                'depends_on_stock' => $stock !== null && Rows::bool($stock['depends_on_stock']),
            ];
        }
        // kx_id links the product to the platform; it is not content of the shop.
        $hashed = $item;
        unset($hashed['kx_id']);
        if (isset($hashed['images'])) {
            foreach ($hashed['images'] as $i => $image) {
                unset($hashed['images'][$i]['url']);
            }
        }
        $item['quantity'] = $stock === null ? 0 : (int) $stock['quantity'];
        if ($full) {
            $item['date_add'] = $this->scope->utc($row['date_add']) ?? '1970-01-01T00:00:00Z';
        }
        $item['date_upd'] = $this->scope->utc($row['date_upd']) ?? '1970-01-01T00:00:00Z';
        $item['content_hash'] = ContentHash::of($hashed);

        return $item;
    }

    /**
     * @param  array<string, string|null>  $row
     */
    private function type(array $row): string
    {
        $type = self::TYPES[(string) $row['product_type']] ?? null;
        if ($type !== null) {
            return $type;
        }
        if (Rows::bool($row['cache_is_pack'])) {
            return 'pack';
        }
        if (Rows::bool($row['is_virtual'])) {
            return 'virtual';
        }

        return (int) $row['cache_default_attribute'] > 0 ? 'variable' : 'simple';
    }

    /**
     * @param  list<array{0: int, 1: int, 2: bool}>  $features
     * @return list<array<string, mixed>>
     */
    private function features(array $features): array
    {
        sort($features);

        return array_map(static function (array $f): array {
            return ['feature_id' => (string) $f[0], 'value_id' => (string) $f[1], 'custom' => $f[2]];
        }, $features);
    }

    /**
     * @param  array<int, list<string>>  $byLang
     * @return array<string, list<string>>|\stdClass
     */
    private function tags(array $byLang)
    {
        ksort($byLang);
        $tags = [];
        foreach ($byLang as $langId => $names) {
            $names = array_values(array_unique($names));
            sort($names, SORT_STRING);
            $tags[$this->scope->languages[$langId]] = $names;
        }

        return $tags === [] ? new \stdClass() : $tags;
    }

    /**
     * @param  array<int, array<string, string|null>>  $suppliers  id_supplier => row
     * @return list<array<string, string>>
     */
    private function suppliers(array $suppliers): array
    {
        ksort($suppliers);
        $result = [];
        foreach ($suppliers as $supplierId => $row) {
            $supplier = [
                'supplier_id' => (string) $supplierId,
                'reference' => Rows::text($row['product_supplier_reference']),
                'price_te' => Decimal::money($row['product_supplier_price_te']),
            ];
            $currency = $this->scope->currencies[(int) $row['id_currency']] ?? null;
            if ($currency !== null) {
                $supplier['currency'] = $currency;
            }
            $result[] = $supplier;
        }

        return $result;
    }

    /**
     * @param  list<array<string, string|null>>  $rows
     * @return list<array<string, mixed>>
     */
    private function images(array $rows, string $slug): array
    {
        usort($rows, static function (array $a, array $b): int {
            return [(int) $a['position'], (int) $a['id_image']] <=> [(int) $b['position'], (int) $b['id_image']];
        });
        $type = in_array(self::IMAGE_TYPE, $this->scope->imageTypes, true) ? self::IMAGE_TYPE : null;
        $images = [];
        foreach ($rows as $row) {
            $sha = $row['sha256'] ?? null;
            $images[] = [
                'external_id' => (string) (int) $row['id_image'],
                'position' => max(0, (int) $row['position']),
                'cover' => Rows::bool($row['cover']),
                'url' => $this->urls->url((int) $row['id_image'], $slug, $type),
                'sha256' => is_string($sha) && preg_match('/\A[0-9a-f]{64}\z/', $sha) === 1 ? $sha : null,
            ];
        }

        return $images;
    }

    /**
     * @param  list<string>  $allowed  the first is the default
     *
     * @throws ApiError
     */
    private static function choice(PageQuery $query, string $name, array $allowed): string
    {
        $value = $query->param($name);
        if ($value === null) {
            return $allowed[0];
        }
        if (! in_array($value, $allowed, true)) {
            throw ApiError::badQuery([$name]);
        }

        return $value;
    }
}
