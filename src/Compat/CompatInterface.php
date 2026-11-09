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

namespace Kronixon\Connect\Compat;

/**
 * Everything that differs between PrestaShop versions lives behind this interface (Spec §1
 * principle 5, §9). M0 holds the facts the skeleton and the protocol documents need; the
 * operations (cache flush, facetedsearch, image formats at runtime) join with their endpoints.
 */
interface CompatInterface
{
    /** Profile id: ps178, ps80, ps81 or ps9. */
    public function id(): string;

    /** The ObjectModel class of an attribute-group value: `Attribute` (1.7.8) or `ProductAttribute` (8.0+). */
    public function attributeClass(): string;

    /**
     * Hooks whose names depend on the version (registered next to the common ones).
     *
     * @return list<string>
     */
    public function versionHooks(): array;

    /**
     * Product redirect types the shop accepts, in protocol notation (`seo/redirects`, `capabilities.features`).
     *
     * @return list<string>
     */
    public function redirectTypes(): array;

    /** Whether the shop has the PS_IMAGE_FORMAT setting (webp/avif), PrestaShop 8.1+. */
    public function hasImageFormatSetting(): bool;

    /** Whether `product_shop.unit_price` exists (PrestaShop 8.0+); 1.7.8 has only `unit_price_ratio`. */
    public function hasUnitPriceColumn(): bool;

    /** Whether `feature_value.position` exists (PrestaShop 9.0+). */
    public function hasFeatureValuePosition(): bool;
}
