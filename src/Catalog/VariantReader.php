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

/**
 * `GET catalog/variants` (module/catalog-variants.json): the combinations of the paired shop by
 * combination id, all or of one `product_id`, with their shop values (impacts, default, minimal
 * quantity), option values, images and the default stock row. `since` is the product's `date_upd`
 * (a combination has none). Six queries a page; a page without items runs only its base query.
 *
 * With `product_id_in` (up to 100 product ids) the page holds the combinations of those products,
 * ordered by product, then combination, and its cursor names both (`{"id":<product>,"v":<combination>}`);
 * `until_id` and `product_id` do not combine with it. At most seven queries a page.
 */
final class VariantReader implements Reader
{
    public const ENTITY = 'variant';

    /** The most product ids of one `product_id_in`. */
    public const MAX_PRODUCT_IDS = 100;

    /** @var Sql */
    private $sql;

    /** @var Scope */
    private $scope;

    public function __construct(Sql $sql, Scope $scope)
    {
        $this->sql = $sql;
        $this->scope = $scope;
    }

    public function maxLimit(): int
    {
        return 500;
    }

    public function maxId(): string
    {
        return Rows::id($this->sql->rows('SELECT MAX(id_product_attribute) AS m FROM '.$this->sql->table('product_attribute'))[0]['m'] ?? null);
    }

    /** `v` (a combination id) only on `product_id_in` pages; read() refuses it on the others. */
    public function maxCursorVariant(): int
    {
        return PHP_INT_MAX;
    }

    public function read(PageQuery $query): Page
    {
        $products = $query->idListParam('product_id_in', self::MAX_PRODUCT_IDS);
        if ($products !== null) {
            $conflicts = array_values(array_filter(['product_id', 'until_id'], static function (string $name) use ($query): bool {
                return $query->param($name) !== null;
            }));
            if ($conflicts !== []) {
                throw ApiError::badQuery(array_merge($conflicts, ['product_id_in']));
            }

            return $this->ofProducts($query, $products);
        }
        if ($query->afterVariant !== null) {
            throw ApiError::badQuery(['cursor']);
        }
        $productId = $query->idParam('product_id');
        if ($query->afterId >= $query->untilId) {
            return Page::none();
        }
        $where = 'pa.id_product_attribute > '.$query->afterId.' AND pa.id_product_attribute <= '.$query->untilId;
        if ($productId !== null) {
            $where .= ' AND pa.id_product = '.$productId;
        }
        $rows = $this->rows($query, $where, 'pa.id_product_attribute', $query->limit + 1);
        $more = count($rows) > $query->limit;

        return $this->page(array_slice($rows, 0, $query->limit), $more, static function (array $item): string {
            return Cursor::encode((int) $item['external_id']);
        });
    }

    /**
     * A page of the combinations of $products after the cursor's (product, combination): the rest of
     * the cursor's product, then the later products of the list. Each is a range of the
     * `id_product` index, read in index order, so a page reads about the rows it answers.
     *
     * @param  list<int>  $products  ascending
     */
    private function ofProducts(PageQuery $query, array $products): Page
    {
        $windows = [];
        if ($query->afterVariant !== null && in_array($query->afterId, $products, true)) {
            $windows[] = 'pa.id_product = '.$query->afterId.' AND pa.id_product_attribute > '.$query->afterVariant;
        }
        $later = array_values(array_filter($products, static function (int $id) use ($query): bool {
            return $id > $query->afterId;
        }));
        if ($later !== []) {
            $windows[] = 'pa.id_product IN ('.Rows::in($later).')';
        }
        $rows = [];
        foreach ($windows as $where) {
            $wanted = $query->limit + 1 - count($rows);
            if ($wanted > 0) {
                $rows = array_merge($rows, $this->rows($query, $where, 'pa.id_product, pa.id_product_attribute', $wanted));
            }
        }
        $more = count($rows) > $query->limit;

        return $this->page(array_slice($rows, 0, $query->limit), $more, static function (array $item): string {
            return Cursor::encode((int) $item['product_id'], (int) $item['external_id']);
        });
    }

    /**
     * The shop's combination rows matching $where (and `since`), in $order, at most $limit.
     *
     * @return list<array<string, string|null>>
     */
    private function rows(PageQuery $query, string $where, string $order, int $limit): array
    {
        $join = '';
        if ($query->since !== null) {
            $join = ' INNER JOIN '.$this->sql->table('product_shop').' ps ON ps.id_product = pa.id_product AND ps.id_shop = '.$this->scope->shopId;
            $where .= " AND ps.date_upd >= '".$query->since."'";
        }

        return $this->sql->rows('SELECT pa.id_product_attribute, pa.id_product, pa.reference, pa.ean13, pa.mpn, pas.price, pas.wholesale_price,'
            .' pas.weight, pas.default_on, pas.minimal_quantity, pas.available_date FROM '.$this->sql->table('product_attribute').' pa INNER JOIN '
            .$this->sql->table('product_attribute_shop').' pas ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = '.$this->scope->shopId
            .$join.' WHERE '.$where.' ORDER BY '.$order.' LIMIT '.$limit);
    }

    /**
     * The items of a page's combination rows: option values, images, stock and `kx_id` read by the
     * page's ids, five queries; none for a page without rows.
     *
     * @param  list<array<string, string|null>>  $rows
     * @param  callable(array<string, mixed>): string  $cursorOf
     */
    private function page(array $rows, bool $more, callable $cursorOf): Page
    {
        // No ids, no follow-up queries: `IN (0)` would match the base stock row (combination 0) of
        // every product of the shop.
        if ($rows === []) {
            return new Page([], $more, $cursorOf);
        }
        $ids = array_map(static function (array $row): int {
            return (int) $row['id_product_attribute'];
        }, $rows);
        $in = Rows::in($ids);

        // Two queries, not a join: MySQL drives a join from the small `attribute` table and reads every
        // combination row of the shop through the (id_attribute, id_product_attribute) primary key.
        $pairs = $this->sql->rows('SELECT id_product_attribute, id_attribute FROM '.$this->sql->table('product_attribute_combination')
            .' WHERE id_product_attribute IN ('.$in.')');
        $attributes = array_values(array_unique(array_map(static function (array $row): int {
            return (int) $row['id_attribute'];
        }, $pairs)));
        $groups = [];
        foreach ($this->sql->rows('SELECT id_attribute, id_attribute_group FROM '.$this->sql->table('attribute').' WHERE id_attribute IN ('.Rows::in($attributes).')') as $row) {
            $groups[(int) $row['id_attribute']] = (int) $row['id_attribute_group'];
        }
        $options = [];
        foreach ($pairs as $row) {
            $attribute = (int) $row['id_attribute'];
            if (isset($groups[$attribute])) {  // a combination row of a deleted value: skipped, as the join did
                $options[(int) $row['id_product_attribute']][] = [$groups[$attribute], $attribute];
            }
        }
        $images = [];
        // Only images the shop has (a shared combination may also point at another shop's images); read
        // from the page's ids, as ProductReader does.
        foreach ($this->sql->rows('SELECT pai.id_product_attribute, pai.id_image, ish.id_shop FROM '.$this->sql->table('product_attribute_image').' pai LEFT JOIN '
            .$this->sql->table('image_shop').' ish ON ish.id_image = pai.id_image AND ish.id_shop = '.$this->scope->shopId
            .' WHERE pai.id_product_attribute IN ('.$in.')') as $row) {
            if ($row['id_shop'] !== null) {
                $images[(int) $row['id_product_attribute']][] = (int) $row['id_image'];
            }
        }
        $stock = [];
        foreach ($this->sql->rows('SELECT id_product_attribute, quantity FROM '.$this->sql->table('stock_available').' sa WHERE '.$this->scope->stockWhere('sa')
            .' AND sa.id_product_attribute IN ('.$in.')') as $row) {
            $stock[(int) $row['id_product_attribute']] = (int) $row['quantity'];
        }
        $kx = Rows::kxIds($this->sql, self::ENTITY, $ids);

        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row['id_product_attribute'];
            $values = $options[$id] ?? [];
            sort($values);
            $imageIds = array_values(array_unique($images[$id] ?? []));
            sort($imageIds);
            $date = (string) $row['available_date'];
            $items[] = [
                'external_id' => (string) $id,
                'product_id' => Rows::id($row['id_product']),
                'kx_id' => $kx[$id] ?? null,
                'reference' => Rows::text($row['reference']),
                'ean13' => Rows::text($row['ean13']),
                'mpn' => Rows::text($row['mpn']),
                'price_impact' => Decimal::money($row['price']),
                'wholesale_price' => Decimal::money($row['wholesale_price']),
                'weight_impact' => Decimal::money($row['weight']),
                'quantity' => $stock[$id] ?? 0,
                'default' => Rows::bool($row['default_on']),
                'option_values' => array_map(static function (array $v): array {
                    return ['group_id' => (string) $v[0], 'value_id' => (string) $v[1]];
                }, $values),
                'image_ids' => array_map('strval', $imageIds),
                'minimal_quantity' => max(1, (int) $row['minimal_quantity']),
                'available_date' => preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}/', $date) === 1 && strncmp($date, '0000', 4) !== 0 ? substr($date, 0, 10) : null,
            ];
        }

        return new Page($items, $more, $cursorOf);
    }
}
