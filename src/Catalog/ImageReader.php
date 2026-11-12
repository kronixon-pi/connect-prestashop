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
 * `GET catalog/images` (module/catalog-images.json): the product images of the paired shop by image
 * id, all or of one `product_id`: position, cover, alt per language, the original image's URL and one
 * URL per product image type, and `kx_key`/`sha256` of an image the platform uploaded. `since` is the
 * product's `date_upd`. Three queries a page.
 */
final class ImageReader implements Reader
{
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
        return Rows::id($this->sql->rows('SELECT MAX(id_image) AS m FROM '.$this->sql->table('image'))[0]['m'] ?? null);
    }

    public function maxCursorVariant(Request $request): ?int
    {
        return null;
    }

    public function read(PageQuery $query): Page
    {
        $productId = $query->idParam('product_id');
        if ($query->afterId >= $query->untilId) {
            return Page::none();
        }
        $where = 'i.id_image > '.$query->afterId.' AND i.id_image <= '.$query->untilId;
        if ($productId !== null) {
            $where .= ' AND i.id_product = '.$productId;
        }
        $join = '';
        if ($query->since !== null) {
            $join = ' INNER JOIN '.$this->sql->table('product_shop').' ps ON ps.id_product = i.id_product AND ps.id_shop = '.$this->scope->shopId;
            $where .= " AND ps.date_upd >= '".$query->since."'";
        }
        $rows = $this->sql->rows('SELECT i.id_image, i.id_product, i.position, ish.cover, m.kx_key, m.sha256 FROM '.$this->sql->table('image').' i INNER JOIN '
            .$this->sql->table('image_shop').' ish ON ish.id_image = i.id_image AND ish.id_shop = '.$this->scope->shopId.' LEFT JOIN '
            .$this->sql->table('kx_image_map').' m ON m.id_image = i.id_image'.$join.' WHERE '.$where.' ORDER BY i.id_image LIMIT '.($query->limit + 1));
        $more = count($rows) > $query->limit;
        $rows = array_slice($rows, 0, $query->limit);
        $ids = array_map(static function (array $row): int {
            return (int) $row['id_image'];
        }, $rows);
        $products = array_values(array_unique(array_map(static function (array $row): int {
            return (int) $row['id_product'];
        }, $rows)));

        $alt = Rows::multilang($this->scope, $this->sql->rows('SELECT id_image, id_lang, legend FROM '.$this->sql->table('image_lang')
            .' WHERE id_image IN ('.Rows::in($ids).')'), 'id_image', ['legend']);
        $slugs = [];
        foreach ($this->sql->rows('SELECT id_product, link_rewrite FROM '.$this->sql->table('product_lang').' WHERE id_shop = '.$this->scope->shopId
            .' AND id_lang = '.$this->scope->defaultLangId.' AND id_product IN ('.Rows::in($products).')') as $row) {
            $slugs[(int) $row['id_product']] = Rows::text($row['link_rewrite']);
        }

        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row['id_image'];
            $slug = $slugs[(int) $row['id_product']] ?? '';
            $urls = [];
            foreach ($this->scope->imageTypes as $type) {
                $urls[$type] = $this->urls->url($id, $slug, $type);
            }
            $sha = $row['sha256'];
            $items[] = [
                'external_id' => (string) $id,
                'product_id' => Rows::id($row['id_product']),
                'position' => max(0, (int) $row['position']),
                'cover' => Rows::bool($row['cover']),
                'alt' => Rows::field($alt, $id, 'legend'),
                'url' => $this->urls->url($id, $slug, null),
                'urls' => $urls === [] ? new \stdClass() : $urls,
                'sha256' => is_string($sha) && preg_match('/\A[0-9a-f]{64}\z/', $sha) === 1 ? $sha : null,
                'kx_key' => $row['kx_key'] === null ? null : substr(Rows::text($row['kx_key']), 0, 64),
            ];
        }

        return new Page($items, $more, static function (array $item): string {
            return Cursor::encode((int) $item['external_id']);
        });
    }
}
