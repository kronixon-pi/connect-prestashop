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
 * `GET catalog/suppliers` (module/catalog-suppliers.json): the suppliers of the paired shop by id, with
 * texts per language and the number of products they supply (`product_supplier`). `since` is the
 * supplier's `date_upd`. Four queries a page.
 */
final class SupplierReader implements Reader
{
    public const ENTITY = 'supplier';

    private const TEXTS = ['description', 'meta_title', 'meta_description'];

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
        return Rows::id($this->sql->rows('SELECT MAX(id_supplier) AS m FROM '.$this->sql->table('supplier'))[0]['m'] ?? null);
    }

    public function maxCursorVariant(Request $request): ?int
    {
        return null;
    }

    public function read(PageQuery $query): Page
    {
        [$rows, $more] = Rows::byId(
            $this->sql,
            $query,
            's.id_supplier, s.name, s.active',
            $this->sql->table('supplier').' s INNER JOIN '.$this->sql->table('supplier_shop').' ss ON ss.id_supplier = s.id_supplier AND ss.id_shop = '.$this->scope->shopId,
            's.id_supplier',
            $query->since === null ? '' : "s.date_upd >= '".$query->since."'"
        );
        $ids = Rows::ints($rows, 'id_supplier');
        $texts = Rows::multilang($this->scope, $this->sql->rows('SELECT id_supplier, id_lang, '.implode(', ', self::TEXTS).' FROM '
            .$this->sql->table('supplier_lang').' WHERE id_supplier IN ('.Rows::in($ids).')'), 'id_supplier', self::TEXTS);
        $counts = Rows::counts($this->sql, $this->sql->table('product_supplier').' psu'.Rows::inShop($this->sql, $this->scope, 'psu'), 'psu.id_supplier', $ids, 'COUNT(DISTINCT psu.id_product)');
        $kx = Rows::kxIds($this->sql, self::ENTITY, $ids);

        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row['id_supplier'];
            $item = [
                'external_id' => (string) $id,
                'kx_id' => $kx[$id] ?? null,
                'name' => Rows::name($row['name'], 0),
                'active' => Rows::bool($row['active']),
            ];
            foreach (self::TEXTS as $field) {
                $item[$field] = Rows::field($texts, $id, $field);
            }
            $item['product_count'] = $counts[$id] ?? 0;
            $items[] = $item;
        }

        return new Page($items, $more, static function (array $item): string {
            return Cursor::encode((int) $item['external_id']);
        });
    }
}
