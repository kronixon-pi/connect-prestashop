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

namespace Kronixon\Connect\Shop;

/**
 * What the module reports about the shop (GET profile, GET capabilities, GET health, the pair
 * request). The PrestaShop implementation reads core classes; tests use a fixed one.
 */
interface ShopInfo
{
    public function shopId(): int;

    public function shopName(): string;

    /** The shop's base URL with a trailing slash (the `store_url` the platform checks back). */
    public function shopUrl(): string;

    /** The module API URL (friendly form when the shop has friendly URLs). */
    public function apiUrl(): string;

    public function timezone(): string;

    public function multistore(): bool;

    public function multilang(): bool;

    /**
     * Image formats the shop generates (`capabilities.image_formats`, at least `jpg`).
     *
     * @return list<string>
     */
    public function imageFormats(): array;

    /**
     * The shop-specific members of GET profile (module/profile.json): version, php, mysql, shop,
     * languages, currencies, tax, stock, counts, modules, friendly_urls, maintenance_mode.
     *
     * @return array<string, mixed>
     */
    public function profile(): array;

    public function databaseOk(): bool;
}
