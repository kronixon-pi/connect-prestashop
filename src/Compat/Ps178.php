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
 * PrestaShop 1.7.8.x (PHP 7.1–7.4): class `Attribute`, no `410` redirect, jpg only.
 * Meta sizes are 255/512 as in 8.x/9.x (checked in 1.7.8.11 classes/Product.php; Spec §6.14's
 * "1.7.8: 128/255" is not what the core declares).
 */
class Ps178 implements CompatInterface
{
    public function id(): string
    {
        return 'ps178';
    }

    public function attributeClass(): string
    {
        return 'Attribute';
    }

    public function versionHooks(): array
    {
        return ['actionObjectAttributeAddAfter'];
    }

    public function redirectTypes(): array
    {
        return ['404', '301-product', '302-product', '301-category', '302-category'];
    }

    public function metaLengths(): array
    {
        return ['meta_title' => 255, 'meta_description' => 512];
    }

    public function hasImageFormatSetting(): bool
    {
        return false;
    }
}
