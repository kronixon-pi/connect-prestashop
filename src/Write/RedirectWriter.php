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
use Kronixon\Connect\Catalog\ProductReader;
use Kronixon\Connect\Catalog\Reader;
use Kronixon\Connect\Redirect\RedirectStore;
use Product;

/**
 * `POST seo/redirects` (module/seo-redirects.json): a product's own redirect through `Product`; a
 * category's addresses and old slugs through `kx_redirect`, which the module serves. As PrestaShop does
 * for a product, a category is redirected only while it is inactive; no page the shop shows (Pages) is
 * redirected away, and a redirect leads to a page the shop shows (RedirectTarget).
 */
final class RedirectWriter extends ShopWriter
{
    private const TYPES = ['301-product', '302-product', '301-category', '302-category', '404', '410', 'slug'];

    public function write(Item $item): array
    {
        $entity = $item->choice('entity', ['product', 'category']);
        $type = $item->choice('type', self::TYPES);
        if ($entity === null || $type === null) {
            throw ItemError::invalid($entity === null ? 'entity' : 'type');
        }
        if ($type === 'slug') {
            return $this->slug($item, $entity);
        }
        $id = $item->id('external_id');
        if ($id === null || ! $this->shop->holds($entity, $id)) {
            throw ItemError::missing('external_id');
        }

        return $entity === 'product' ? $this->product($item, $id) : $this->category($item, $id, $type);
    }

    public function hashes(array $results): array
    {
        return $this->hashesOf(array_values(array_filter($results, static function (array $result): bool {
            return isset($result['kind']);
        })));
    }

    protected function reader(): Reader
    {
        return new ProductReader($this->shop->db, $this->shop->scope, $this->shop->urls);
    }

    /**
     * @return array<string, mixed>
     */
    private function product(Item $item, int $id): array
    {
        $product = new Product($id, false, null, $this->shop->scope->shopId);
        $fields = $this->shop->fields($product);
        ProductRedirect::set($this->shop, $fields, $product, $item, 'type', 'target_id', true);
        $changed = $fields->changed();
        $fields->save('product');

        return self::done($id, false, $changed) + ['kind' => 'product'];
    }

    /**
     * @return array<string, mixed>
     */
    private function category(Item $item, int $id, string $type): array
    {
        if ((bool) (new Category($id, null, $this->shop->scope->shopId))->active) {
            throw new ItemError('category_active', 'Deactivate the category first', 'external_id');
        }
        if ($type === '410' && ! in_array('410', $this->shop->compat->redirectTypes(), true)) {
            $this->shop->warnings->add('redirect_410_unsupported');
            $type = '404';
        }
        $paths = $this->paths('category', $id);
        if ($type === '404') {  // what PrestaShop answers for an inactive category
            return self::done($id, false, $this->shop->redirects->remove($paths));
        }
        $target = $type === '410' ? '' : substr($type, 4);
        $targetId = $target === '' ? 0 : (int) $item->id('target_id');
        if ($target !== '') {
            (new RedirectTarget($this->shop))->check('category', $id, $target, $targetId, 'target_id');
        }
        $actions = [];
        foreach ($paths as $path) {
            $actions[] = $this->shop->redirects->put($path, $target, $targetId, $type === '410' ? 410 : (int) substr($type, 0, 3));
        }

        return self::done($id, array_diff($actions, ['created']) === [], array_diff($actions, ['unchanged']) !== []);
    }

    /**
     * @return array<string, mixed>
     */
    private function slug(Item $item, string $entity): array
    {
        $target = (int) $item->id('target_id');
        (new RedirectTarget($this->shop))->check($entity, 0, $entity, $target, 'target_id');
        $path = RedirectStore::path((string) $item->string('path_from', 191));
        if ($path === null || ! $this->shop->pages->inside($path)) {
            throw ItemError::invalid('path_from');
        }
        if (in_array($path, $this->paths($entity, $target), true)) {
            throw new ItemError('redirect_loop', 'path_from is the page itself', 'path_from');
        }
        $page = $this->shop->pages->at($path);
        // PrestaShop answers any friendly URL part with the target's id with the target's page.
        if ($page !== null && $page !== [$entity, $target]) {
            throw new ItemError('slug_taken', 'The shop has a page at path_from', 'path_from');
        }

        return ['external_id' => (string) $target, 'action' => $this->shop->redirects->put($path, $entity, $target, 301)];
    }

    /**
     * The entity's paths in every language of the shop.
     *
     * @return list<string>
     */
    private function paths(string $entity, int $id): array
    {
        if (! Configuration::get('PS_REWRITING_SETTINGS')) {
            throw new ItemError('friendly_urls_off', 'Redirects by path need friendly URLs', 'type');
        }

        return $this->shop->pathsOf($entity, $id);
    }
}
