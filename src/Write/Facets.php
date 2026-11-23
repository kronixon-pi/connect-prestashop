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

namespace Kronixon\Connect\Write;

use Kronixon\Connect\Catalog\Rows;
use Kronixon\Connect\Catalog\Scope;

/**
 * The shop's ps_facetedsearch (3.x on 1.7.8, 4.x on 8.x/9.x; the capability `facets`): its own public
 * index methods, and the filters of a category as its back office stores them. A filter of a feature
 * or an attribute group is a row of `layered_category` (what the storefront reads) and a member
 * `layered_selection_feat_<id>` / `layered_selection_ag_<id>` of every filter template of the shop
 * that covers the category (`layered_filter`, PHP-serialized), so it is still there after somebody
 * saves a template in the back office, which rebuilds `layered_category` from the templates.
 */
final class Facets
{
    public const FEATURE = 'id_feature';

    public const GROUP = 'id_attribute_group';

    /** @var object|null the module, when it is installed and active */
    private $module;

    /** @var Database */
    private $db;

    /** @var Scope */
    private $scope;

    /**
     * @param  object|null  $module  Ps_Facetedsearch
     */
    public function __construct($module, Database $db, Scope $scope)
    {
        $this->module = $module;
        $this->db = $db;
        $this->scope = $scope;
    }

    public function available(): bool
    {
        return $this->module !== null;
    }

    /** The price ranges of a product in every shop and currency (`layered_price_index`). */
    public function indexPrices(int $productId): void
    {
        if ($this->module !== null && method_exists($this->module, 'indexProductPrices')) {
            $this->module->indexProductPrices($productId);
        }
    }

    /** The combinations' attributes of a product (`layered_product_attribute`). */
    public function indexAttributes(int $productId): void
    {
        if ($this->module !== null && method_exists($this->module, 'indexAttributes')) {
            $this->module->indexAttributes($productId);
        }
    }

    /** Features and groups created since the last run become indexable (URL parts of the filters). */
    public function indexables(): void
    {
        foreach (['indexFeatures', 'indexAttributeGroup'] as $method) {
            if ($this->module !== null && method_exists($this->module, $method)) {
                $this->module->{$method}();
            }
        }
    }

    /** The storefront's cached filter blocks go (a TRUNCATE: never inside an item's transaction). */
    public function invalidate(): void
    {
        if ($this->module !== null && method_exists($this->module, 'invalidateLayeredFilterBlockCache')) {
            $this->module->invalidateLayeredFilterBlockCache();
        }
    }

    /**
     * The categories of the paired shop that filter by the feature or group.
     *
     * @return array<int, array{position: int, filter_type: int, show_limit: int}>
     */
    public function of(string $type, int $value): array
    {
        $filters = [];
        foreach ($this->db->rows('SELECT id_category, position, filter_type, filter_show_limit FROM '.$this->db->table('layered_category').' WHERE id_shop = '.$this->scope->shopId
            ." AND controller = 'category' AND type = '".$type."' AND id_value = ".$value.' ORDER BY id_category') as $row) {
            $filters[(int) $row['id_category']] = ['position' => (int) $row['position'], 'filter_type' => (int) $row['filter_type'], 'show_limit' => (int) $row['filter_show_limit']];
        }

        return $filters;
    }

    /**
     * The filters of the feature or group: $wanted are added or changed, with $replace the others go.
     * A position that is null is the category's next one for a new filter and kept for an existing one.
     *
     * @param  array<int, array{position: int|null, filter_type: int, show_limit: int}>  $wanted  category id => filter
     * @return bool whether anything changed
     */
    public function write(string $type, int $value, array $wanted, bool $replace): bool
    {
        $current = $this->of($type, $value);
        $changed = false;
        $table = $this->db->table('layered_category');
        $where = ' WHERE id_shop = '.$this->scope->shopId." AND controller = 'category' AND type = '".$type."' AND id_value = ".$value;
        foreach ($wanted as $category => $filter) {
            $now = $current[$category] ?? null;
            $position = $filter['position'] ?? ($now === null ? $this->next($category) : $now['position']);
            if ($now === null) {
                $this->db->bulk('INSERT INTO '.$table.' (id_shop, controller, id_category, id_value, type, position, filter_type, filter_show_limit) VALUES ('
                    .$this->scope->shopId.", 'category', ".$category.', '.$value.", '".$type."', ".$position.', '.$filter['filter_type'].', '.$filter['show_limit'].')');
                $changed = true;
            } elseif ([$now['position'], $now['filter_type'], $now['show_limit']] !== [$position, $filter['filter_type'], $filter['show_limit']]) {
                $this->db->bulk('UPDATE '.$table.' SET position = '.$position.', filter_type = '.$filter['filter_type'].', filter_show_limit = '.$filter['show_limit'].$where.' AND id_category = '.$category);
                $changed = true;
            }
        }
        $gone = $replace ? array_diff(array_keys($current), array_keys($wanted)) : [];
        if ($gone !== []) {
            $this->db->bulk('DELETE FROM '.$table.$where.' AND id_category IN ('.Rows::in(array_values($gone)).')');
            $changed = true;
        }

        return $this->templates($type, $value, $wanted, $replace) || $changed;
    }

    /**
     * The member of the feature or group in the shop's filter templates: in those that cover a category
     * of $wanted, and with $replace out of those that cover none.
     *
     * @param  array<int, array{position: int|null, filter_type: int, show_limit: int}>  $wanted
     */
    private function templates(string $type, int $value, array $wanted, bool $replace): bool
    {
        $member = ($type === self::FEATURE ? 'layered_selection_feat_' : 'layered_selection_ag_').$value;
        $changed = false;
        foreach ($this->db->rows('SELECT id_layered_filter, filters FROM '.$this->db->table('layered_filter').' ORDER BY id_layered_filter') as $row) {
            $filters = @unserialize((string) $row['filters'], ['allowed_classes' => false]);
            if (! is_array($filters) || ! in_array($this->scope->shopId, array_map('intval', (array) ($filters['shop_list'] ?? [])), true)) {
                continue;
            }
            $covered = array_values(array_intersect(array_map('intval', (array) ($filters['categories'] ?? [])), array_keys($wanted)));
            $before = $filters[$member] ?? null;
            if ($covered !== []) {
                $filter = $wanted[$covered[0]];
                $filters[$member] = ['filter_type' => $filter['filter_type'], 'filter_show_limit' => $filter['show_limit']];
            } elseif ($replace) {
                unset($filters[$member]);
            }
            if (($filters[$member] ?? null) !== $before) {
                $this->db->bulk('UPDATE '.$this->db->table('layered_filter').' SET filters = '.$this->db->quote(serialize($filters)).' WHERE id_layered_filter = '.(int) $row['id_layered_filter']);
                $changed = true;
            }
        }

        return $changed;
    }

    private function next(int $category): int
    {
        $rows = $this->db->rows('SELECT MAX(position) AS p FROM '.$this->db->table('layered_category').' WHERE id_shop = '.$this->scope->shopId
            ." AND controller = 'category' AND id_category = ".$category);

        return (int) ($rows[0]['p'] ?? 0) + 1;
    }
}
