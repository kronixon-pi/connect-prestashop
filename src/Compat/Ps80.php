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
 * PrestaShop 8.0.x: the attribute value class is `ProductAttribute` (its ObjectModel hooks follow the
 * class name), `410` redirects exist. 8.0 is not in the test matrix (plan C22): this profile is
 * covered by unit tests only.
 */
class Ps80 extends Ps178
{
    public function id(): string
    {
        return 'ps80';
    }

    public function attributeClass(): string
    {
        return 'ProductAttribute';
    }

    public function versionHooks(): array
    {
        return ['actionObjectProductAttributeAddAfter'];
    }

    public function redirectTypes(): array
    {
        return ['404', '410', '301-product', '302-product', '301-category', '302-category'];
    }
}
