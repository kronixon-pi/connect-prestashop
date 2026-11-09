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
 * `GET catalog/attributes` (module/catalog-attributes.json): features and attribute groups of the
 * paired shop in one list, told apart by `kind`, ordered by id and then kind (feature before attribute
 * group of the same id). The cursor is `{"id":N,"v":K}` with K = 0 for a feature and 1 for an attribute
 * group; `{"id":N}` starts after both of id N; `until_id` bounds the id of both and `max_id` is the
 * higher of the two. `value_count` counts a feature's predefined values (not the per-product custom
 * ones) and a group's values. `facets` are left out: the module announces no `facets` capability.
 * Neither has a change date, so `since` is ignored. At most eight queries a page.
 */
final class AttributeReader implements Reader
{
    /** kind index => [kind, table, id column, shop table, kx entity type] */
    private const KINDS = [
        0 => ['feature', 'feature', 'id_feature', 'feature_shop', 'feature'],
        1 => ['attribute_group', 'attribute_group', 'id_attribute_group', 'attribute_group_shop', 'attribute_group'],
    ];

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
        $row = $this->sql->rows('SELECT (SELECT MAX(id_feature) FROM '.$this->sql->table('feature').') AS f, (SELECT MAX(id_attribute_group) FROM '
            .$this->sql->table('attribute_group').') AS g')[0] ?? [];

        return (string) max((int) ($row['f'] ?? 0), (int) ($row['g'] ?? 0));
    }

    public function read(PageQuery $query): Page
    {
        $rows = [];
        foreach (self::KINDS as $kind => [, $table, $id, $shopTable]) {
            // Rows of this kind after the cursor's (id, kind): the same id too when the cursor's kind is lower.
            $inclusive = $query->afterVariant !== null && $kind > $query->afterVariant;
            if ($query->afterId > $query->untilId || (! $inclusive && $query->afterId === $query->untilId)) {
                continue;
            }
            foreach ($this->sql->rows('SELECT t.'.$id.' AS id, t.position'.($kind === 1 ? ', t.group_type, t.is_color_group' : '').' FROM '
                .$this->sql->table($table).' t INNER JOIN '.$this->sql->table($shopTable).' s ON s.'.$id.' = t.'.$id.' AND s.id_shop = '.$this->scope->shopId
                .' WHERE t.'.$id.' '.($inclusive ? '>=' : '>').' '.$query->afterId.' AND t.'.$id.' <= '.$query->untilId
                .' ORDER BY t.'.$id.' LIMIT '.($query->limit + 1)) as $row) {
                $rows[] = ['kind' => $kind] + $row;
            }
        }
        usort($rows, static function (array $a, array $b): int {
            return [(int) $a['id'], $a['kind']] <=> [(int) $b['id'], $b['kind']];
        });
        $more = count($rows) > $query->limit;
        $rows = array_slice($rows, 0, $query->limit);

        $related = [];
        foreach (self::KINDS as $kind => [, $table, $id, , $entity]) {
            $ids = array_map('intval', array_column(array_filter($rows, static function (array $row) use ($kind): bool {
                return $row['kind'] === $kind;
            }), 'id'));
            $fields = $kind === 1 ? ['name', 'public_name'] : ['name'];
            $related[$kind] = [
                'texts' => Rows::multilang($this->scope, $this->sql->rows('SELECT '.$id.', id_lang, '.implode(', ', $fields).' FROM '
                    .$this->sql->table($table.'_lang').' WHERE '.$id.' IN ('.Rows::in($ids).')'), $id, $fields),
                'counts' => $kind === 1
                    ? Rows::counts($this->sql, $this->sql->table('attribute'), 'id_attribute_group', $ids)
                    : Rows::counts($this->sql, $this->sql->table('feature_value'), 'id_feature', $ids, 'SUM(CASE WHEN custom = 0 THEN 1 ELSE 0 END)'),
                'kx' => Rows::kxIds($this->sql, $entity, $ids),
            ];
        }

        $items = [];
        foreach ($rows as $row) {
            $kind = $row['kind'];
            $id = (int) $row['id'];
            $item = [
                'kind' => self::KINDS[$kind][0],
                'external_id' => (string) $id,
                'kx_id' => $related[$kind]['kx'][$id] ?? null,
                'position' => max(0, (int) $row['position']),
                'name' => Rows::field($related[$kind]['texts'], $id, 'name'),
            ];
            if ($kind === 1) {
                $item['public_name'] = Rows::field($related[$kind]['texts'], $id, 'public_name');
                $item['group_type'] = in_array($row['group_type'], ['color', 'radio', 'select'], true) ? (string) $row['group_type'] : 'select';
                $item['is_color_group'] = Rows::bool($row['is_color_group']);
            }
            $item['value_count'] = $related[$kind]['counts'][$id] ?? 0;
            $items[] = $item;
        }

        return new Page($items, $more, static function (array $item): string {
            return Cursor::encode((int) $item['external_id'], $item['kind'] === 'feature' ? 0 : 1);
        });
    }
}
