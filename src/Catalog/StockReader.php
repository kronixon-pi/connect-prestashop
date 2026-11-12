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
 * `GET catalog/stock` (module/catalog-stock.json): the `stock_available` rows of the paired shop (of
 * its group when the group shares stock), location `default`, ordered by product then variant; the
 * cursor names both, `until_id` bounds the product id, `max_id` is the highest product id. The table
 * has no change date, so `since` is ignored (every row is read). At most two queries a page, each an
 * ordered range of the (id_product, id_product_attribute, …) unique index.
 */
final class StockReader implements Reader
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
        $rows = [];
        foreach ($query->variantWindows('sa.id_product', 'sa.id_product_attribute') as $window) {
            $need = $query->limit + 1 - count($rows);
            if ($need <= 0) {
                break;
            }
            $rows = array_merge($rows, $this->sql->rows('SELECT sa.id_product, sa.id_product_attribute, sa.quantity, sa.physical_quantity, sa.reserved_quantity,'
                .' sa.depends_on_stock, sa.out_of_stock FROM '.$this->sql->table('stock_available').' sa WHERE '.$this->scope->stockWhere('sa')
                .' AND '.$window.' ORDER BY sa.id_product, sa.id_product_attribute LIMIT '.$need));
        }
        $more = count($rows) > $query->limit;
        $items = [];
        foreach (array_slice($rows, 0, $query->limit) as $row) {
            $items[] = [
                'product_id' => Rows::id($row['id_product']),
                'variant_id' => Rows::id($row['id_product_attribute']),
                'location' => 'default',
                'quantity' => (int) $row['quantity'],
                'physical_quantity' => (int) $row['physical_quantity'],
                'reserved_quantity' => (int) $row['reserved_quantity'],
                'out_of_stock' => min(2, max(0, (int) $row['out_of_stock'])),
                'depends_on_stock' => Rows::bool($row['depends_on_stock']),
                'date_upd' => null,
            ];
        }

        return new Page($items, $more, static function (array $item): string {
            return Cursor::encode((int) $item['product_id'], (int) $item['variant_id']);
        });
    }
}
