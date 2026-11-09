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
 * The public URL of a product image as the shop serves it (friendly URLs or `img/p/…`); no query.
 */
interface ImageUrls
{
    /**
     * @param  string  $slug  the product's link_rewrite in the default language
     * @param  string|null  $type  an image type (`large_default`), null for the original image
     */
    public function url(int $imageId, string $slug, ?string $type): string;

    /** The URL of a brand's (manufacturer's) logo, null when it has none. */
    public function brandLogo(int $brandId): ?string;
}
