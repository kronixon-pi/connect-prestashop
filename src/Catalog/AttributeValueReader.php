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
 * `GET catalog/attribute-values` (module/catalog-attribute-values.json): the values of one `kind`
 * (required), by id, all or of one `parent_id`. Feature values carry `value` per language and `custom`
 * (a per-product text value; the platform keeps it as text); attribute values carry `name`, `color`
 * and `position`. `max_id` is the highest value id of the kind. Neither has a change date, so `since`
 * is ignored. Four queries a page (with the kind's `max_id`).
 */
final class AttributeValueReader implements Reader
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

    /** Both kinds; a page carries its own kind's (Page::$maxId). */
    public function maxId(): string
    {
        return (string) max((int) $this->max('feature_value', 'id_feature_value'), (int) $this->max('attribute', 'id_attribute'));
    }

    public function maxCursorVariant(Request $request): ?int
    {
        return null;
    }

    public function read(PageQuery $query): Page
    {
        $kind = $query->param('kind');
        if ($kind !== 'feature' && $kind !== 'attribute_group') {
            throw ApiError::badQuery(['kind']);
        }
        $parent = $query->idParam('parent_id');
        $page = $kind === 'feature' ? $this->features($query, $parent) : $this->attributes($query, $parent);
        $page->maxId = $kind === 'feature' ? $this->max('feature_value', 'id_feature_value') : $this->max('attribute', 'id_attribute');

        return $page;
    }

    private function features(PageQuery $query, ?int $parent): Page
    {
        [$rows, $more] = Rows::byId(
            $this->sql,
            $query,
            'fv.id_feature_value, fv.id_feature, fv.custom'.($this->scope->featureValuePosition ? ', fv.position' : ''),
            $this->sql->table('feature_value').' fv INNER JOIN '.$this->sql->table('feature_shop').' fs ON fs.id_feature = fv.id_feature AND fs.id_shop = '.$this->scope->shopId,
            'fv.id_feature_value',
            $parent === null ? '' : 'fv.id_feature = '.$parent
        );
        $ids = Rows::ints($rows, 'id_feature_value');
        $texts = Rows::multilang($this->scope, $this->sql->rows('SELECT id_feature_value, id_lang, value FROM '.$this->sql->table('feature_value_lang')
            .' WHERE id_feature_value IN ('.Rows::in($ids).')'), 'id_feature_value', ['value']);
        $kx = Rows::kxIds($this->sql, 'feature_value', $ids);
        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row['id_feature_value'];
            $item = [
                'external_id' => (string) $id,
                'parent_id' => Rows::id($row['id_feature']),
                'kx_id' => $kx[$id] ?? null,
                'custom' => Rows::bool($row['custom']),
                'value' => Rows::field($texts, $id, 'value'),
            ];
            if (isset($row['position'])) {
                $item['position'] = max(0, (int) $row['position']);
            }
            $items[] = $item;
        }

        return self::page($items, $more);
    }

    private function attributes(PageQuery $query, ?int $parent): Page
    {
        [$rows, $more] = Rows::byId(
            $this->sql,
            $query,
            'a.id_attribute, a.id_attribute_group, a.color, a.position',
            // The value and its group in the shop (a value in the shop under another shop's group names a group the shop lacks).
            $this->sql->table('attribute').' a INNER JOIN '.$this->sql->table('attribute_shop').' s ON s.id_attribute = a.id_attribute AND s.id_shop = '.$this->scope->shopId
                .' INNER JOIN '.$this->sql->table('attribute_group_shop').' gs ON gs.id_attribute_group = a.id_attribute_group AND gs.id_shop = '.$this->scope->shopId,
            'a.id_attribute',
            $parent === null ? '' : 'a.id_attribute_group = '.$parent
        );
        $ids = Rows::ints($rows, 'id_attribute');
        $texts = Rows::multilang($this->scope, $this->sql->rows('SELECT id_attribute, id_lang, name FROM '.$this->sql->table('attribute_lang')
            .' WHERE id_attribute IN ('.Rows::in($ids).')'), 'id_attribute', ['name']);
        $kx = Rows::kxIds($this->sql, 'attribute', $ids);
        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row['id_attribute'];
            $color = (string) $row['color'];
            $items[] = [
                'external_id' => (string) $id,
                'parent_id' => Rows::id($row['id_attribute_group']),
                'kx_id' => $kx[$id] ?? null,
                'name' => Rows::field($texts, $id, 'name'),
                'color' => preg_match('/\A#[0-9a-fA-F]{6}\z/', $color) === 1 ? $color : null,
                'position' => max(0, (int) $row['position']),
            ];
        }

        return self::page($items, $more);
    }

    private function max(string $table, string $id): string
    {
        return Rows::id($this->sql->rows('SELECT MAX('.$id.') AS m FROM '.$this->sql->table($table))[0]['m'] ?? null);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private static function page(array $items, bool $more): Page
    {
        return new Page($items, $more, static function (array $item): string {
            return Cursor::encode((int) $item['external_id']);
        });
    }
}
