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

use stdClass;

/**
 * Small helpers the readers share: id lists for `IN (…)`, text that is valid UTF-8, translated fields
 * keyed by iso, kx ids from `kx_entity_map`.
 */
final class Rows
{
    private function __construct()
    {
    }

    /**
     * The ids for `IN (…)`: integers only, never empty (`0` matches no row).
     *
     * @param  list<int>  $ids
     */
    public static function in(array $ids): string
    {
        return $ids === [] ? '0' : implode(',', array_map('intval', $ids));
    }

    /**
     * One page of base rows by id: `$id` above the cursor and at most `until_id`, plus `$where`, in id
     * order, at most `limit` rows and whether more follow.
     *
     * @return array{0: list<array<string, string|null>>, 1: bool}
     */
    public static function byId(Sql $sql, PageQuery $query, string $select, string $from, string $id, string $where = ''): array
    {
        if ($query->afterId >= $query->untilId) {
            return [[], false];
        }
        $rows = $sql->rows('SELECT '.$select.' FROM '.$from.' WHERE '.$id.' > '.$query->afterId.' AND '.$id.' <= '.$query->untilId
            .($where === '' ? '' : ' AND '.$where).' ORDER BY '.$id.' LIMIT '.($query->limit + 1));

        return [array_slice($rows, 0, $query->limit), count($rows) > $query->limit];
    }

    /**
     * The integer values of one column.
     *
     * @param  list<array<string, string|null>>  $rows
     * @return list<int>
     */
    public static function ints(array $rows, string $column): array
    {
        return array_map(static function (array $row) use ($column): int {
            return (int) $row[$column];
        }, $rows);
    }

    /**
     * `COUNT(*)` per id of a page: `SELECT $id, COUNT(…) FROM … GROUP BY $id` as id => count.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    public static function counts(Sql $sql, string $from, string $id, array $ids, string $count = 'COUNT(*)'): array
    {
        $counts = [];
        foreach ($sql->rows('SELECT '.$id.' AS id, '.$count.' AS n FROM '.$from.' WHERE '.$id.' IN ('.self::in($ids).') GROUP BY '.$id) as $row) {
            $counts[(int) $row['id']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * The join that keeps a counter to the shop's products: `$alias.id_product` in the shop's `product_shop`.
     * None on an install with one shop, whose products are all that shop's.
     */
    public static function inShop(Sql $sql, Scope $scope, string $alias): string
    {
        return $scope->multistore
            ? ' INNER JOIN '.$sql->table('product_shop').' cps ON cps.id_product = '.$alias.'.id_product AND cps.id_shop = '.$scope->shopId
            : '';
    }

    /** Database text as valid UTF-8 (a broken byte becomes `?`), so an answer always encodes. */
    public static function text(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        $previous = mb_substitute_character();
        mb_substitute_character(0x3F);
        $clean = (string) mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        mb_substitute_character($previous);

        return $clean;
    }

    /**
     * A required name (the schemas want at least one character): the text, cut to $max characters when
     * $max > 0, `-` when PrestaShop holds none.
     */
    public static function name(?string $value, int $max): string
    {
        $text = self::text($value);
        if ($max > 0 && mb_strlen($text, 'UTF-8') > $max) {
            $text = mb_substr($text, 0, $max, 'UTF-8');
        }

        return $text === '' ? '-' : $text;
    }

    public static function bool(?string $value): bool
    {
        return (int) $value === 1;
    }

    /** An id as an `external_id` string (`"0"` for none). */
    public static function id(?string $value): string
    {
        return (string) max(0, (int) $value);
    }

    /**
     * Translated fields of a page's rows: `[id][field] => {iso: text}`, every language of the shop that
     * has a row, in id_lang order; a field without any row is `{}`.
     *
     * @param  list<array<string, string|null>>  $rows  with `$idColumn`, `id_lang` and the fields
     * @param  list<string>  $fields
     * @return array<int, array<string, array<string, string>|stdClass>>
     */
    public static function multilang(Scope $scope, array $rows, string $idColumn, array $fields): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $iso = $scope->languages[(int) $row['id_lang']] ?? null;
            if ($iso === null) {
                continue;
            }
            foreach ($fields as $field) {
                $byId[(int) $row[$idColumn]][$field][(int) $row['id_lang']] = [$iso, self::text($row[$field] ?? null)];
            }
        }
        $result = [];
        foreach ($byId as $id => $values) {
            foreach ($values as $field => $byLang) {
                ksort($byLang);
                $map = [];
                foreach ($byLang as $pair) {
                    $map[$pair[0]] = $pair[1];
                }
                $result[$id][$field] = $map;
            }
        }

        return $result;
    }

    /**
     * A field of multilang() for one id, `{}` when the id has no translation.
     *
     * @param  array<int, array<string, array<string, string>|stdClass>>  $translations
     * @return array<string, string>|stdClass
     */
    public static function field(array $translations, int $id, string $field)
    {
        return $translations[$id][$field] ?? new stdClass();
    }

    /**
     * The catalog's UUIDs of a page's entities (`kx_entity_map`, written by the write endpoints).
     *
     * @param  list<int>  $ids
     * @return array<int, string> id => kx_id
     */
    public static function kxIds(Sql $sql, string $entityType, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $quoted = [];
        foreach ($ids as $id) {
            $quoted[] = "'".(int) $id."'";
        }
        $map = [];
        foreach ($sql->rows('SELECT external_id, kx_id FROM '.$sql->table('kx_entity_map')
            ." WHERE entity_type = '".preg_replace('/[^a-z_]/', '', $entityType)."' AND external_id IN (".implode(',', $quoted).')') as $row) {
            $map[(int) $row['external_id']] = (string) $row['kx_id'];
        }

        return $map;
    }
}
