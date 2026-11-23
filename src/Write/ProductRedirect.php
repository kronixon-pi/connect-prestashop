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

/**
 * What the shop answers for a product that is not active: `Product::$redirect_type` and
 * `$id_type_redirected` (POST products `seo.redirect`, POST seo/redirects).
 */
final class ProductRedirect
{
    private function __construct()
    {
    }

    public static function set(Shop $shop, Fields $fields, Item $item, string $typeMember, string $targetMember): void
    {
        $type = (string) $item->string($typeMember);
        if ($type === '410' && ! in_array('410', $shop->compat->redirectTypes(), true)) {
            $shop->warnings->add('redirect_410_unsupported');
            $type = '404';
        }
        if (! in_array($type, array_merge($shop->compat->redirectTypes(), $shop->compat->displayedRedirectTypes()), true)) {
            throw ItemError::invalid($item->at($typeMember));
        }
        $target = 0;
        if (preg_match('/-(product|category)\z/', $type, $m) === 1) {
            $target = $item->id($targetMember) ?? 0;
            // "0" is the product's own default category; a product needs a target.
            if (($target === 0 && $m[1] === 'product') || ($target !== 0 && ! $shop->holds($m[1], $target))) {
                throw ItemError::missing($item->at($targetMember), 'redirect_target_not_found');
            }
        }
        $fields->set('redirect_type', $type);
        $fields->set('id_type_redirected', $target);
    }
}
