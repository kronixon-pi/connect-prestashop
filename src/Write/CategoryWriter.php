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

use Category;
use Configuration;
use Kronixon\Connect\Catalog\CategoryReader;
use Kronixon\Connect\Catalog\Reader;
use Kronixon\Connect\Compat\MetaLengths;

/**
 * `POST categories` (module/categories.json) through `Category`. Items are written in order, so a
 * `parent_key` names an earlier item; without a parent a category belongs under the shop's Home (one
 * that already stands directly under the Root stays there). The nested tree is rebuilt once, after the
 * last item, unless `options.regenerate_tree` is `none`. Root, Home and other shops' roots are
 * PrestaShop's own and refused. No deletes: a category is deactivated.
 */
final class CategoryWriter extends ShopWriter
{
    public const ENTITY = CategoryReader::ENTITY;

    /** @var bool */
    private $regenerate;

    /** @var bool */
    private $treeChanged = false;

    /** @var array<string, int> item key => id of the items written so far */
    private $written = [];

    public function __construct(Shop $shop, Item $options)
    {
        parent::__construct($shop);
        $this->regenerate = $options->choice('regenerate_tree', ['end', 'none']) !== 'none';
    }

    public function write(Item $item): array
    {
        $parent = $this->parent($item);
        $name = $this->shop->texts->name($item, 'name', false);
        if ($name === null || $name === []) {
            throw ItemError::invalid('name', 'is required');
        }
        $id = $this->shop->map->resolve($item, self::ENTITY, function (int $id): bool {
            return $this->shop->holds('category', $id);
        }, function () use ($item, $parent, $name): array {
            return self::matches($item, 'name_under_parent') ? $this->under($parent ?? $this->shop->scope->homeCategoryId, $this->shop->inDefaultLanguage($name)) : [];
        });
        $name = $this->shop->texts->name($item, 'name', $id === null);

        // Scopes the category_shop rows PrestaShop writes to the paired shop, as its own form does.
        $_POST['checkBoxShopAsso_category'] = [$this->shop->scope->shopId => $this->shop->scope->shopId];
        $category = new Category($id, null, $this->shop->scope->shopId);
        $category->doNotRegenerateNTree = true;
        if ($id !== null && ((int) $category->id_parent === 0 || (bool) $category->is_root_category || $id === $this->shop->scope->homeCategoryId)) {
            throw new ItemError('root_category', 'The Root and Home categories are not written', 'external_id');
        }

        $fields = $this->shop->fields($category);
        $fields->text('name', $name);
        $slugs = $this->shop->slugs($this->shop->texts->of($item, 'link_rewrite'));
        if ($id === null) {
            $slugs = ($slugs ?? []) + array_map([$this->shop, 'slug'], $name ?? []);
            $category->is_root_category = false;
            $category->active = true;
        }
        $lengths = MetaLengths::of('category');
        $fields->text('link_rewrite', $slugs);
        $fields->text('description', $this->shop->texts->of($item, 'description'), true);
        $fields->text('meta_title', $this->shop->texts->meta($item, 'meta_title', $lengths['meta_title']));
        $fields->text('meta_description', $this->shop->texts->meta($item, 'meta_description', $lengths['meta_description']));
        $fields->set('active', $item->bool('active'));
        $position = $item->int('position');
        $this->move($category, $fields, $parent);
        $changed = $fields->changed();
        $fields->save('category');
        $changed = $this->place($category, $position) || $changed;

        $result = self::done((int) $category->id, $id === null, $changed);
        $this->shop->map->link(self::ENTITY, (int) $category->id, $item->kxId());
        $this->written[$item->key()] = (int) $category->id;

        return $result;
    }

    public function rolledBack(Item $item): bool
    {
        unset($this->written[$item->key()]);

        return parent::rolledBack($item);
    }

    public function finish(): void
    {
        parent::finish();
        if ($this->treeChanged && $this->regenerate) {
            Category::regenerateEntireNtree();
        }
    }

    protected function reader(): Reader
    {
        return new CategoryReader($this->shop->db, $this->shop->scope);
    }

    /** The parent the item names; null when it names none. */
    private function parent(Item $item): ?int
    {
        $key = $item->string('parent_key');
        if ($key !== null) {
            if (! isset($this->written[$key])) {
                throw ItemError::missing('parent_key', 'parent_not_found');
            }

            return $this->written[$key];
        }
        $parent = $item->id('parent_external_id');
        if ($parent !== null && ! $this->shop->holds('category', $parent)) {
            throw ItemError::missing('parent_external_id', 'parent_not_found');
        }

        return $parent;
    }

    private function move(Category $category, Fields $fields, ?int $parent): void
    {
        $current = (int) $category->id_parent;
        if ($parent === null) {
            if (! $fields->creating() && $current === (int) Configuration::get('PS_ROOT_CATEGORY')) {
                return;
            }
            $parent = $this->shop->scope->homeCategoryId;
        }
        if ($parent === $current) {
            return;
        }
        if (! $fields->creating()) {
            $this->refuseCycle((int) $category->id, $parent);
            $category->position = (int) Category::getLastPosition($parent, $this->shop->scope->shopId);
            $fields->touch('position');
            $fields->touch('level_depth');
        }
        $fields->set('id_parent', $parent);
        $this->treeChanged = true;
    }

    private function refuseCycle(int $id, int $parent): void
    {
        for ($ancestor = $parent, $steps = 0; $ancestor > 0 && $steps < 100; $steps++) {
            if ($ancestor === $id) {
                throw new ItemError('parent_cycle', 'A category cannot be under itself', 'parent_external_id');
            }
            $rows = $this->shop->db->rows('SELECT id_parent FROM '.$this->shop->db->table('category').' WHERE id_category = '.$ancestor);
            $ancestor = (int) ($rows[0]['id_parent'] ?? 0);
        }
    }

    /** The position among its siblings in the paired shop; null leaves it. */
    private function place(Category $category, ?int $position): bool
    {
        $rows = $this->shop->db->rows('SELECT position FROM '.$this->shop->db->table('category_shop').' WHERE id_category = '.(int) $category->id.' AND id_shop = '.$this->shop->scope->shopId);
        if ($position === null || (int) ($rows[0]['position'] ?? -1) === $position) {
            return false;
        }
        if (! $category->addPosition($position, $this->shop->scope->shopId)) {
            throw ItemError::notSaved('category position');
        }

        return true;
    }

    /**
     * The shop's categories under $parent named $name in the default language.
     *
     * @return list<int>
     */
    private function under(int $parent, string $name): array
    {
        $rows = $this->shop->db->rows('SELECT c.id_category, cl.name FROM '.$this->shop->db->table('category').' c INNER JOIN '.$this->shop->db->table('category_shop')
            .' cs ON cs.id_category = c.id_category AND cs.id_shop = '.$this->shop->scope->shopId.' INNER JOIN '.$this->shop->db->table('category_lang')
            .' cl ON cl.id_category = c.id_category AND cl.id_shop = cs.id_shop AND cl.id_lang = '.$this->shop->scope->defaultLangId
            .' WHERE c.id_parent = '.$parent.' ORDER BY c.id_category');
        $found = [];
        foreach ($rows as $row) {
            if (Texts::matchKey((string) $row['name']) === Texts::matchKey($name)) {
                $found[] = (int) $row['id_category'];
            }
        }

        return $found;
    }
}
