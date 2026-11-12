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
 * `GET catalog/categories` (module/catalog-categories.json): the categories of the paired shop by id,
 * with parent, depth, position in the shop, texts per language, product count and `content_hash`.
 * PrestaShop's Root (no parent) and the shop's Home category carry `root: true`, Home also `is_home`;
 * the platform maps both to its catalog root. Pages follow ids, not the tree: a client that needs
 * parents first orders by `level` (a child's level is above its parent's). Four queries a page.
 *
 * `content_hash` covers every member but `kx_id` (a link to the platform), `date_upd`, `product_count`
 * (a counter, not content) and the hash itself.
 */
final class CategoryReader implements Reader
{
    public const ENTITY = 'category';

    private const TEXTS = ['name', 'link_rewrite', 'description', 'meta_title', 'meta_description'];

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
        return Rows::id($this->sql->rows('SELECT MAX(id_category) AS m FROM '.$this->sql->table('category'))[0]['m'] ?? null);
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
            'c.id_category, c.id_parent, c.level_depth, c.active, c.date_upd, cs.position',
            $this->sql->table('category').' c INNER JOIN '.$this->sql->table('category_shop').' cs ON cs.id_category = c.id_category AND cs.id_shop = '.$this->scope->shopId,
            'c.id_category',
            $query->since === null ? '' : "c.date_upd >= '".$query->since."'"
        );
        $ids = Rows::ints($rows, 'id_category');
        $texts = Rows::multilang($this->scope, $this->sql->rows('SELECT id_category, id_lang, '.implode(', ', self::TEXTS).' FROM '.$this->sql->table('category_lang')
            .' WHERE id_shop = '.$this->scope->shopId.' AND id_category IN ('.Rows::in($ids).')'), 'id_category', self::TEXTS);
        $counts = Rows::counts($this->sql, $this->sql->table('category_product').' cp'.Rows::inShop($this->sql, $this->scope, 'cp'), 'cp.id_category', $ids);
        $kx = Rows::kxIds($this->sql, self::ENTITY, $ids);

        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row['id_category'];
            $item = [
                'external_id' => (string) $id,
                'kx_id' => $kx[$id] ?? null,
                'parent_id' => Rows::id($row['id_parent']),
                'root' => (int) $row['id_parent'] === 0 || $id === $this->scope->homeCategoryId,
                'is_home' => $id === $this->scope->homeCategoryId,
                'level' => max(0, (int) $row['level_depth']),
                'position' => max(0, (int) $row['position']),
                'active' => Rows::bool($row['active']),
            ];
            foreach (self::TEXTS as $field) {
                $item[$field] = Rows::field($texts, $id, $field);
            }
            // kx_id links the category to the platform; it is not content of the shop.
            $hashed = $item;
            unset($hashed['kx_id']);
            $item['product_count'] = $counts[$id] ?? 0;
            $date = $this->scope->utc($row['date_upd']);
            if ($date !== null) {
                $item['date_upd'] = $date;
            }
            $item['content_hash'] = ContentHash::of($hashed);
            $items[] = $item;
        }

        return new Page($items, $more, static function (array $item): string {
            return Cursor::encode((int) $item['external_id']);
        });
    }
}
