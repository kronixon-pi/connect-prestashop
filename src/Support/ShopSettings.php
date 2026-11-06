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

namespace Kronixon\Connect\Support;

use Configuration;

/**
 * Settings in PrestaShop's `Configuration` table as global values (one pairing per installation).
 */
final class ShopSettings implements Settings
{
    public function get(string $key): ?string
    {
        $value = Configuration::getGlobalValue($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function set(string $key, string $value): bool
    {
        return (bool) Configuration::updateGlobalValue($key, $value);
    }

    public function delete(string $key): bool
    {
        return (bool) Configuration::deleteByName($key);
    }
}
