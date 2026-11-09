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

/**
 * `GET catalog/variants` (module/catalog-variants.json): the combinations of the paired shop by
 * combination id, all or of one `product_id`, with their shop values (impacts, default, minimal
 * quantity), option values, images and the default stock row. `since` is the product's `date_upd`
 * (a combination has none). Five queries a page.
 */
final class VariantReader implements Reader
{
    public const ENTITY = 'variant';

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

    public function maxCursorVariant(): ?int
    {
        return null;
    }

    public function read(PageQuery $query): Page
    {
        $productId = $query->idParam('product_id');
        if ($query->afterId >= $query->untilId) {
            return Page::none();
        }
        $where = 'pa.id_product_attribute > '.$query->afterId.' AND pa.id_product_attribute <= '.$query->untilId;
        if ($productId !== null) {
            $where .= ' AND pa.id_product = '.$productId;
        }
        $join = '';
        if ($query->since !== null) {
            $join = ' INNER JOIN '.$this->sql->table('product_shop').' ps ON ps.id_product = pa.id_product AND ps.id_shop = '.$this->scope->shopId;
            $where .= " AND ps.date_upd >= '".$query->since."'";
        }
        $rows = $this->sql->rows('SELECT pa.id_product_attribute, pa.id_product, pa.reference, pa.ean13, pa.mpn, pas.price, pas.wholesale_price,'
            .' pas.weight, pas.default_on, pas.minimal_quantity, pas.available_date FROM '.$this->sql->table('product_attribute').' pa INNER JOIN '
            .$this->sql->table('product_attribute_shop').' pas ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = '.$this->scope->shopId
            .$join.' WHERE '.$where.' ORDER BY pa.id_product_attribute LIMIT '.($query->limit + 1));
        $more = count($rows) > $query->limit;
        $rows = array_slice($rows, 0, $query->limit);
        $ids = array_map(static function (array $row): int {
            return (int) $row['id_product_attribute'];
        }, $rows);
        $in = Rows::in($ids);

        $options = [];
        foreach ($this->sql->rows('SELECT pac.id_product_attribute, pac.id_attribute, a.id_attribute_group FROM '.$this->sql->table('product_attribute_combination')
            .' pac INNER JOIN '.$this->sql->table('attribute').' a ON a.id_attribute = pac.id_attribute WHERE pac.id_product_attribute IN ('.$in.')') as $row) {
            $options[(int) $row['id_product_attribute']][] = [(int) $row['id_attribute_group'], (int) $row['id_attribute']];
        }
        $images = [];
        // Only images the shop has (a shared combination may also point at another shop's images).
        foreach ($this->sql->rows('SELECT pai.id_product_attribute, pai.id_image FROM '.$this->sql->table('product_attribute_image').' pai INNER JOIN '
            .$this->sql->table('image_shop').' ish ON ish.id_image = pai.id_image AND ish.id_shop = '.$this->scope->shopId
            .' WHERE pai.id_product_attribute IN ('.$in.')') as $row) {
            $images[(int) $row['id_product_attribute']][] = (int) $row['id_image'];
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

        return new Page($items, $more, static function (array $item): string {
            return Cursor::encode((int) $item['external_id']);
        });
    }
}
