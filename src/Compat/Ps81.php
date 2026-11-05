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
 * PrestaShop 8.1.x and 8.2.x: PS_IMAGE_FORMAT (webp/avif), the Symfony product page — the legacy
 * `Product` ObjectModel and its hooks keep working.
 */
class Ps81 extends Ps80
{
    public function id(): string
    {
        return 'ps81';
    }

    public function hasImageFormatSetting(): bool
    {
        return true;
    }
}
