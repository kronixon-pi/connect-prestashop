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

use Kronixon\Connect\Api\Request;

/**
 * `GET catalog/prices` (module/catalog-prices.json): one row per product (`variant_id` "0") and per
 * combination, ordered by product then variant, with net prices in the shop default currency (PrestaShop
 * stores them without tax): a combination's `price` is the product's price plus its `price_impact`.
 * `specific_prices` are the product's catalog-wide specific prices (not a cart's) of this shop or all
 * shops, on the row of their combination ("0": the product row). The cursor names product and variant,
 * `until_id` bounds the product id, `since` is the product's `date_upd`. At most six queries a page.
 */
final class PriceReader implements Reader
{
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
        return 1000;
    }

    public function maxId(): string
    {
        return Rows::id($this->sql->rows('SELECT MAX(id_product) AS m FROM '.$this->sql->table('product'))[0]['m'] ?? null);
    }

    /** Rows are keyed by (product, variant): `v` is the variant id. */
    public function maxCursorVariant(Request $request): int
    {
        return PHP_INT_MAX;
    }

    public function read(PageQuery $query): Page
    {
        $n = $query->limit + 1;
        $since = $query->since === null ? '' : " AND ps.date_upd >= '".$query->since."'";
        $shop = ' AND ps.id_shop = '.$this->scope->shopId;
        $rows = [];
        foreach ($query->variantWindows('pa.id_product', 'pa.id_product_attribute') as $kind => $window) {
            $variants = $this->sql->rows('SELECT pa.id_product, pa.id_product_attribute, pas.price, pas.wholesale_price FROM '.$this->sql->table('product_attribute')
                .' pa INNER JOIN '.$this->sql->table('product_attribute_shop').' pas ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = '
                .$this->scope->shopId.($since === '' ? '' : ' INNER JOIN '.$this->sql->table('product_shop').' ps ON ps.id_product = pa.id_product'.$shop)
                .' WHERE '.$window.$since.' ORDER BY pa.id_product, pa.id_product_attribute LIMIT '.$n);
            $products = [];
            if ($kind === 'range') {
                // Driven from `product` by its primary key, as ProductReader is: `product_shop` alone lets
                // MySQL take its (id_shop, id_tax_rules_group) index on PrestaShop 8.2+ and sort every
                // product of the shop for each page.
                $products = $this->sql->rows('SELECT p.id_product, 0 AS id_product_attribute FROM '.$this->sql->table('product').' p INNER JOIN '
                    .$this->sql->table('product_shop').' ps ON ps.id_product = p.id_product'.$shop.' WHERE '
                    .str_replace('pa.', 'p.', $window).$since.' ORDER BY p.id_product LIMIT '.$n);
            }
            $window = array_merge($products, $variants);
            usort($window, static function (array $a, array $b): int {
                return [(int) $a['id_product'], (int) $a['id_product_attribute']] <=> [(int) $b['id_product'], (int) $b['id_product_attribute']];
            });
            $rows = array_merge($rows, $window);
        }
        $more = count($rows) > $query->limit;
        $rows = array_slice($rows, 0, $query->limit);
        $ids = array_values(array_unique(array_map(static function (array $row): int {
            return (int) $row['id_product'];
        }, $rows)));

        $base = [];
        foreach ($this->sql->rows('SELECT ps.id_product, ps.price, ps.wholesale_price, ps.id_tax_rules_group, ps.unit_price_ratio, '
            .($this->scope->unitPriceColumn ? 'ps.unit_price' : 'NULL AS unit_price').', ps.date_upd FROM '.$this->sql->table('product_shop')
            .' ps WHERE ps.id_product IN ('.Rows::in($ids).')'.$shop) as $row) {
            $base[(int) $row['id_product']] = $row;
        }
        $specific = [];
        foreach ($this->sql->rows('SELECT sp.* FROM '.$this->sql->table('specific_price').' sp WHERE sp.id_product IN ('.Rows::in($ids).') AND sp.id_cart = 0'
            .' AND sp.id_shop IN (0, '.$this->scope->shopId.')') as $row) {
            $specific[(int) $row['id_product']][(int) $row['id_product_attribute']][(int) $row['id_specific_price']] = $this->specific($row);
        }

        $items = [];
        foreach ($rows as $row) {
            $productId = (int) $row['id_product'];
            $variantId = (int) $row['id_product_attribute'];
            $product = $base[$productId] ?? ['price' => '0', 'wholesale_price' => '0', 'id_tax_rules_group' => '0', 'unit_price_ratio' => '0', 'unit_price' => '0', 'date_upd' => null];
            $prices = $specific[$productId][$variantId] ?? [];
            ksort($prices);
            $item = [
                'product_id' => (string) $productId,
                'variant_id' => (string) $variantId,
                'price' => $variantId === 0 ? Decimal::money($product['price']) : Decimal::add((string) $product['price'], (string) $row['price']),
                'wholesale_price' => Decimal::money($variantId === 0 ? $product['wholesale_price'] : $row['wholesale_price']),
                'tax_rules_group_id' => (int) $product['id_tax_rules_group'],
            ];
            if ($variantId === 0) {
                $item['unit_price'] = $this->scope->unitPriceColumn ? Decimal::money($product['unit_price']) : Decimal::divide((string) $product['price'], (string) $product['unit_price_ratio']);
            } else {
                $item['price_impact'] = Decimal::money($row['price']);
            }
            $item['specific_prices'] = array_values($prices);
            $item['date_upd'] = $this->scope->utc($product['date_upd']);
            $items[] = $item;
        }

        return new Page($items, $more, static function (array $item): string {
            return Cursor::encode((int) $item['product_id'], (int) $item['variant_id']);
        });
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>
     */
    private function specific(array $row): array
    {
        return [
            'external_id' => Rows::id($row['id_specific_price']),
            'id_currency' => (int) $row['id_currency'],
            'id_country' => (int) $row['id_country'],
            'id_group' => (int) $row['id_group'],
            'id_customer' => (int) $row['id_customer'],
            'from_quantity' => max(1, (int) $row['from_quantity']),
            'price' => Decimal::money($row['price']),
            'reduction' => Decimal::money($row['reduction']),
            'reduction_type' => $row['reduction_type'] === 'percentage' ? 'percentage' : 'amount',
            'reduction_tax' => (int) $row['reduction_tax'] === 0 ? 0 : 1,
            'from' => $this->scope->utc($row['from']),
            'to' => $this->scope->utc($row['to']),
        ];
    }
}
