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
 * `GET catalog/tags` (module/catalog-tags.json): tags by id (a PrestaShop tag belongs to one language)
 * in the shop's languages, with the number of products; the products' tags are in `catalog/products`.
 * Tags have no change date, so `since` is ignored. Two queries a page.
 */
final class TagReader implements Reader
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
        return 500;
    }

    public function maxId(): string
    {
        return Rows::id($this->sql->rows('SELECT MAX(id_tag) AS m FROM '.$this->sql->table('tag'))[0]['m'] ?? null);
    }

    public function maxCursorVariant(): ?int
    {
        return null;
    }

    public function read(PageQuery $query): Page
    {
        [$rows, $more] = Rows::byId(
            $this->sql,
            $query,
            't.id_tag, t.id_lang, t.name',
            $this->sql->table('tag').' t',
            't.id_tag',
            "t.name <> '' AND t.id_lang IN (".Rows::in(array_keys($this->scope->languages)).')'
        );
        $ids = Rows::ints($rows, 'id_tag');
        $counts = Rows::counts($this->sql, $this->sql->table('product_tag'), 'id_tag', $ids);

        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row['id_tag'];
            $items[] = [
                'external_id' => (string) $id,
                'lang' => $this->scope->languages[(int) $row['id_lang']],
                'name' => Rows::name($row['name'], 32),
                'product_count' => $counts[$id] ?? 0,
            ];
        }

        return new Page($items, $more, static function (array $item): string {
            return Cursor::encode((int) $item['external_id']);
        });
    }
}
