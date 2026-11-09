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

use Link;

/**
 * Image URLs through the shop's own Link, so friendly URLs, the media servers and an
 * `overrideImageLink` module (PrestaShop 8+) shape them as the storefront does.
 */
final class PrestaShopImageUrls implements ImageUrls
{
    /** @var Link */
    private $link;

    public function __construct(Link $link)
    {
        $this->link = $link;
    }

    public function url(int $imageId, string $slug, ?string $type): string
    {
        $url = (string) $this->link->getImageLink($slug !== '' ? $slug : 'image', (string) $imageId, $type);

        return self::absolute($url);
    }

    public function brandLogo(int $brandId): ?string
    {
        if (! is_file(_PS_MANU_IMG_DIR_.$brandId.'.jpg')) {
            return null;
        }

        return self::absolute((string) $this->link->getManufacturerImageLink($brandId));
    }

    private static function absolute(string $url): string
    {
        return strpos($url, '://') === false ? 'http://'.ltrim($url, '/') : $url;
    }
}
