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

use Product;

/**
 * What the shop answers for a product that is not active: `Product::$redirect_type` and
 * `$id_type_redirected` (POST products `seo.redirect`, POST seo/redirects).
 */
final class ProductRedirect
{
    private function __construct()
    {
    }

    /**
     * @param  bool  $unchangedToo  false: a redirect the product already has is not checked again, so a write
     *                              that repeats it does not fail when its target was deactivated since
     */
    public static function set(Shop $shop, Fields $fields, Product $product, Item $item, string $typeMember, string $targetMember, bool $unchangedToo): void
    {
        $type = (string) $item->string($typeMember);
        if ($type === '410' && ! in_array('410', $shop->compat->redirectTypes(), true)) {
            $shop->warnings->add('redirect_410_unsupported');
            $type = '404';
        }
        if (! in_array($type, array_merge($shop->compat->redirectTypes(), $shop->compat->displayedRedirectTypes()), true)) {
            throw ItemError::invalid($item->at($typeMember));
        }
        $leads = preg_match('/-(product|category)\z/', $type, $m) === 1;
        $target = $leads ? $item->id($targetMember) ?? 0 : 0;
        $fields->sentAs(['redirect_type' => $item->at($typeMember), 'id_type_redirected' => $item->at($targetMember)]);
        $fields->set('redirect_type', $type);
        $fields->set('id_type_redirected', $target);
        if (! $leads || ! ($unchangedToo || $fields->changed(['redirect_type', 'id_type_redirected']))) {
            return;
        }
        $checks = new RedirectTarget($shop);
        if ($target !== 0 || $m[1] === 'product') {
            $checks->check('product', (int) $product->id, $m[1], $target, $item->at($targetMember));
        } elseif ($checks->leadsTo(['category', (int) $product->id_category_default], ['product', (int) $product->id])) {
            // "0" is the product's own default category.
            throw new ItemError('redirect_loop', 'The redirects would lead back', $item->at($targetMember));
        }
    }
}
