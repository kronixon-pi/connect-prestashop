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
 * `GET catalog/brands` (module/catalog-brands.json): the manufacturers of the paired shop by id, with
 * texts per language, the logo URL (null without a logo), the number of products and `date_upd`.
 * `since` is the manufacturer's `date_upd`. Four queries a page.
 */
final class BrandReader implements Reader
{
    public const ENTITY = 'brand';

    private const TEXTS = ['description', 'short_description', 'meta_title', 'meta_description'];

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
        return Rows::id($this->sql->rows('SELECT MAX(id_manufacturer) AS m FROM '.$this->sql->table('manufacturer'))[0]['m'] ?? null);
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
            'm.id_manufacturer, m.name, m.active, m.date_upd',
            $this->sql->table('manufacturer').' m INNER JOIN '.$this->sql->table('manufacturer_shop').' ms ON ms.id_manufacturer = m.id_manufacturer AND ms.id_shop = '.$this->scope->shopId,
            'm.id_manufacturer',
            $query->since === null ? '' : "m.date_upd >= '".$query->since."'"
        );
        $ids = Rows::ints($rows, 'id_manufacturer');
        $texts = Rows::multilang($this->scope, $this->sql->rows('SELECT id_manufacturer, id_lang, '.implode(', ', self::TEXTS).' FROM '
            .$this->sql->table('manufacturer_lang').' WHERE id_manufacturer IN ('.Rows::in($ids).')'), 'id_manufacturer', self::TEXTS);
        $counts = Rows::counts($this->sql, $this->sql->table('product').' p'.Rows::inShop($this->sql, $this->scope, 'p'), 'p.id_manufacturer', $ids);
        $kx = Rows::kxIds($this->sql, self::ENTITY, $ids);

        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row['id_manufacturer'];
            $item = [
                'external_id' => (string) $id,
                'kx_id' => $kx[$id] ?? null,
                'name' => Rows::name($row['name'], 64),
                'active' => Rows::bool($row['active']),
            ];
            foreach (self::TEXTS as $field) {
                $item[$field] = Rows::field($texts, $id, $field);
            }
            $item['logo_url'] = $this->urls->brandLogo($id);
            $item['product_count'] = $counts[$id] ?? 0;
            $date = $this->scope->utc($row['date_upd']);
            if ($date !== null) {
                $item['date_upd'] = $date;
            }
            $items[] = $item;
        }

        return new Page($items, $more, static function (array $item): string {
            return Cursor::encode((int) $item['external_id']);
        });
    }
}
