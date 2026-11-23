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

use Hook;
use Kronixon\Connect\Catalog\ProductReader;
use Kronixon\Connect\Catalog\Reader;
use Kronixon\Connect\Compat\MetaLengths;
use Product;
use Search;
use StockAvailable;

/**
 * `POST products` (module/products.json) through `Product` and its relations: only the groups the item
 * lists are written, of those only what differs, in the languages sent. Relations are written before
 * the one save of an update and after the insert of a create, so the hooks of the last save see all of
 * it. Creating needs a name in the shop's default language; prices and stock of an existing product
 * have their own endpoints (the `pricing` group writes what it is sent, `stock_initial` only a create).
 */
final class ProductWriter extends ShopWriter
{
    public const ENTITY = ProductReader::ENTITY;

    private const GROUPS = ['identity', 'content', 'seo', 'categories', 'brand', 'attributes', 'tags', 'visibility', 'suppliers', 'pricing', 'physical'];

    /** identity member => [column of `product`, its length] */
    private const CODES = ['reference' => 64, 'ean13' => 13, 'mpn' => 40, 'upc' => 12, 'isbn' => 32, 'supplier_reference' => 64];

    /** @var ProductRelations */
    private $relations;

    /** @var string */
    private $reindex;

    /** @var int */
    private $id = 0;

    /** @var bool the last item changed what the search index holds */
    private $searchable = false;

    public function __construct(Shop $shop, Item $options)
    {
        parent::__construct($shop);
        $this->relations = new ProductRelations($shop);
        $this->reindex = $options->choice('reindex', ['deferred', 'inline', 'none']) ?? 'deferred';
    }

    public function write(Item $item): array
    {
        $groups = $this->groups($item);
        $type = $item->choice('type', ['simple', 'variable']);
        $id = $this->shop->map->resolve($item, self::ENTITY, function (int $id): bool {
            return $this->shop->holds('product', $id);
        }, function () use ($item): array {
            return $this->match($item);
        });
        $product = new Product($id, false, null, $this->shop->scope->shopId);
        $fields = $this->shop->fields($product);
        $creating = $id === null;
        if ($creating) {
            $product->price = 0.0;
            $product->active = false;
            $product->product_type = $type === 'variable' ? 'combinations' : 'standard';
            $product->id_tax_rules_group = (int) Product::getIdTaxRulesGroupMostUsed();
            if (! isset($groups['content']) || ! $groups['content']->has('name')) {
                throw ItemError::invalid('content.name', 'is required');
            }
        }
        $slugs = (array) $product->link_rewrite;
        $this->texts($groups, $fields, $product);
        $this->codesAndFlags($groups, $fields);
        $this->numbers($groups, $fields);
        $categories = $this->relations->wantedCategories($groups['categories'] ?? null, $creating);
        if ($categories !== null) {
            $fields->set('id_category_default', $this->relations->defaultCategory($product, $categories));
        }

        $features = isset($groups['attributes']) ? $this->relations->wantedFeatures($groups['attributes']) : [];
        $tags = isset($groups['tags']) ? $this->relations->wantedTags($groups['tags']) : [];
        $suppliers = isset($groups['suppliers']) ? $this->relations->wantedSuppliers($groups['suppliers']) : [];
        $stock = $creating ? $this->wantedStock($item) : [null, null];
        $fields->check();

        // Nothing of the item is refused from here on: hooks may commit what is written (Batch).
        $changed = $creating || $fields->changed();
        if ($creating) {
            $fields->save('product');
            $this->shop->map->link(self::ENTITY, (int) $product->id, $item->kxId());
        }
        $related = $categories !== null && $this->relations->categories($product, $categories);
        $related = isset($groups['attributes']) && $this->relations->features($product, $groups['attributes'], $features) || $related;
        $related = $this->relations->tags($product, $tags) || $related;
        $related = isset($groups['suppliers']) && $this->relations->suppliers($product, $groups['suppliers'], $suppliers) || $related;
        $this->searchable = $creating || $related || $fields->changed(['name', 'description', 'description_short', 'id_manufacturer', 'reference', 'ean13', 'upc', 'isbn', 'mpn', 'active', 'visibility']);
        if ($creating) {
            $this->stock((int) $product->id, $stock[0], $stock[1]);
            Hook::exec('actionProductAdd', ['id_product' => (int) $product->id, 'product' => $product]);
            Hook::exec('actionProductUpdate', ['id_product' => (int) $product->id, 'product' => $product]);
        } else {
            $this->update($fields, $related, $groups);
        }
        $this->keepOldLinks($product, $slugs, $groups);
        $this->id = (int) $product->id;
        $this->shop->map->link(self::ENTITY, $this->id, $item->kxId());

        return self::done($this->id, $creating, $changed || $related) + $this->address($product);
    }

    public function committed(Item $item): void
    {
        parent::committed($item);
        if ($this->reindex === 'inline' && $this->searchable) {
            Search::indexation(false, $this->id);
        }
    }

    public function finish(): void
    {
        Product::flushPriceCache();
    }

    protected function reader(): Reader
    {
        return new ProductReader($this->shop->db, $this->shop->scope, $this->shop->urls);
    }

    /**
     * The groups listed in `groups` that the item carries.
     *
     * @return array<string, Item>
     */
    private function groups(Item $item): array
    {
        $listed = $item->strings('groups') ?? [];
        if ($listed === [] || array_diff($listed, self::GROUPS) !== []) {
            throw ItemError::invalid('groups');
        }
        $groups = [];
        foreach ($listed as $name) {
            $group = $item->object($name);
            if ($group === null) {
                throw ItemError::invalid($name, 'is listed in groups but not sent');
            }
            $group->choice('mode', ['replace', 'merge']);
            $groups[$name] = $group;
        }

        return $groups;
    }

    /**
     * The shop's products the first matching rule of `match_by` finds, by exact value.
     *
     * @return list<int>
     */
    private function match(Item $item): array
    {
        $identity = $item->object('identity');
        foreach ($identity === null ? [] : $item->strings('match_by') ?? [] as $rule) {
            $code = in_array($rule, ['ean13', 'reference', 'mpn', 'supplier_reference'], true) ? (string) $identity->string($rule) : '';
            if ($code === '') {
                continue;
            }
            $found = [];
            foreach ($this->shop->db->rows('SELECT p.id_product, p.'.$rule.' AS code FROM '.$this->shop->db->table('product').' p INNER JOIN '.$this->shop->db->table('product_shop')
                .' ps ON ps.id_product = p.id_product AND ps.id_shop = '.$this->shop->scope->shopId.' WHERE p.'.$rule.' = '.$this->shop->db->quote($code).' ORDER BY p.id_product') as $row) {
                if ((string) $row['code'] === $code) {
                    $found[] = (int) $row['id_product'];
                }
            }
            if ($found !== []) {
                return $found;
            }
        }

        return [];
    }

    /**
     * @param  array<string, Item>  $groups
     */
    private function texts(array $groups, Fields $fields, Product $product): void
    {
        $texts = $this->shop->texts;
        $name = null;
        if (isset($groups['content'])) {
            $content = $groups['content'];
            $name = $texts->name($content, 'name', $fields->creating());
            $fields->text('name', $name);
            $fields->text('description_short', $texts->of($content, 'description_short'), true);
            $fields->text('description', $texts->of($content, 'description'), true);
            $fields->text('available_now', $texts->line($content, 'available_now'));
            $fields->text('available_later', $texts->line($content, 'available_later'));
        }
        $slugs = isset($groups['seo']) ? $this->shop->slugs($texts->of($groups['seo'], 'link_rewrite')) : null;
        if ($fields->creating()) {
            $slugs = ($slugs ?? []) + array_map([$this->shop, 'slug'], $name ?? []);
        } elseif ($slugs !== null && ($groups['seo']->bool('preserve_link_rewrite') ?? true)) {
            $slugs = array_diff_key($slugs, array_diff((array) $product->link_rewrite, ['']));
        }
        $fields->text('link_rewrite', $slugs);
        if (isset($groups['seo'])) {
            $lengths = MetaLengths::of('product');
            $fields->text('meta_title', $texts->meta($groups['seo'], 'meta_title', $lengths['meta_title']));
            $fields->text('meta_description', $texts->meta($groups['seo'], 'meta_description', $lengths['meta_description']));
            $redirect = $groups['seo']->object('redirect');
            if ($redirect !== null && $redirect->has('type')) {
                ProductRedirect::set($this->shop, $fields, $redirect, 'type', 'target_id');
            }
        }
    }

    /**
     * @param  array<string, Item>  $groups
     */
    private function codesAndFlags(array $groups, Fields $fields): void
    {
        foreach (isset($groups['identity']) ? self::CODES : [] as $code => $length) {
            $fields->set($code, $groups['identity']->string($code, $length));
        }
        if (isset($groups['brand'])) {
            $brand = $groups['brand']->string('id');
            $fields->set('id_manufacturer', $brand === '0' ? 0 : $this->relations->held('manufacturer', (string) $brand, 'brand.id', 'brand_not_found'));
        }
        if (isset($groups['suppliers']) && $groups['suppliers']->has('default_id')) {
            $supplier = $groups['suppliers']->string('default_id');
            $fields->set('id_supplier', $supplier === '0' ? 0 : $this->relations->held('supplier', (string) $supplier, 'suppliers.default_id', 'supplier_not_found'));
        }
        if (isset($groups['visibility'])) {
            foreach (['active', 'available_for_order', 'show_price', 'online_only', 'indexed'] as $flag) {
                $fields->set($flag, $groups['visibility']->bool($flag));
            }
            $fields->set('visibility', $groups['visibility']->choice('visibility', ['both', 'catalog', 'search', 'none']));
            $fields->set('condition', $groups['visibility']->choice('condition', ['new', 'used', 'refurbished']));
        }
    }

    /**
     * @param  array<string, Item>  $groups
     */
    private function numbers(array $groups, Fields $fields): void
    {
        foreach (isset($groups['physical']) ? ['weight', 'width', 'height', 'depth'] : [] as $size) {
            $fields->amount($size, $groups['physical']->money($size));
        }
        if (! isset($groups['pricing'])) {
            return;
        }
        $pricing = $groups['pricing'];
        foreach (['price', 'wholesale_price', 'unit_price'] as $amount) {
            $fields->amount($amount, $pricing->money($amount));
        }
        $unity = $pricing->string('unity', 255);
        $fields->set('unity', $unity === null ? null : Texts::oneLine($unity));
        $tax = $pricing->int('tax_rules_group_id');
        if ($tax !== null && $tax !== 0 && $this->shop->db->rows('SELECT 1 FROM '.$this->shop->db->table('tax_rules_group').' WHERE id_tax_rules_group = '.$tax.' AND deleted = 0') === []) {
            throw ItemError::missing('pricing.tax_rules_group_id', 'tax_rules_group_not_found');
        }
        $fields->set('id_tax_rules_group', $tax);
        if (! $this->shop->compat->hasUnitPriceColumn() && $fields->changed(['price', 'unit_price'])) {
            // 1.7.8 stores the ratio of the two and writes it only when both are written.
            $fields->touch('price');
            $fields->touch('unit_price');
        }
    }

    /**
     * @param  array<string, Item>  $groups
     */
    private function update(Fields $fields, bool $related, array $groups): void
    {
        if (! $related && ! $fields->changed()) {
            return;
        }
        if ($this->reindex === 'deferred' && $this->searchable && (! isset($groups['visibility']) || ! $groups['visibility']->has('indexed'))) {
            $fields->set('indexed', false);
        }
        $fields->touch('date_upd');
        $fields->save('product');
    }

    /**
     * @return array{0: int|null, 1: int|null} quantity and out_of_stock of `stock_initial`
     */
    private function wantedStock(Item $item): array
    {
        $stock = $item->object('stock_initial');
        if ($stock === null) {
            return [null, null];
        }
        $policy = $stock->int('out_of_stock');
        if ($policy !== null && $policy > 2) {
            throw ItemError::invalid('stock_initial.out_of_stock');
        }

        return [$stock->int('quantity', PHP_INT_MIN), $policy];
    }

    /** The stock of a create; no stock movement (its rows are written on another connection). */
    private function stock(int $id, ?int $quantity, ?int $policy): void
    {
        if ($quantity !== null) {
            StockAvailable::setQuantity($id, 0, $quantity, $this->shop->scope->shopId, false);
        }
        if ($policy !== null) {
            StockAvailable::setProductOutOfStock($id, $policy, $this->shop->scope->shopId);
        }
    }

    /**
     * `seo.redirect.auto`: a friendly URL that changed keeps leading to the product.
     *
     * @param  array<array-key, mixed>  $before  link_rewrite by language before the write
     * @param  array<string, Item>  $groups
     */
    private function keepOldLinks(Product $product, array $before, array $groups): void
    {
        $redirect = isset($groups['seo']) ? $groups['seo']->object('redirect') : null;
        if ($redirect === null || $redirect->bool('auto') !== true) {
            return;
        }
        foreach (array_keys($this->shop->scope->languages) as $langId) {
            $old = (string) ($before[$langId] ?? '');
            if ($old !== '' && $old !== (string) ($product->link_rewrite[$langId] ?? '')) {
                $this->shop->redirects->keep($this->link((int) $product->id, $old, $langId), $this->link((int) $product->id, null, $langId), 'product', (int) $product->id);
            }
        }
    }

    /**
     * The final friendly URL and address in the default language.
     *
     * @return array<string, string>
     */
    private function address(Product $product): array
    {
        $langId = $this->shop->scope->defaultLangId;

        return ['link_rewrite' => (string) ($product->link_rewrite[$langId] ?? ''), 'url' => $this->link((int) $product->id, null, $langId)];
    }

    private function link(int $id, ?string $slug, int $langId): string
    {
        return (string) $this->shop->context->link->getProductLink($id, $slug, null, null, $langId, $this->shop->scope->shopId);
    }
}
