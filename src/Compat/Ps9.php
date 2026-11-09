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
 * PrestaShop 9.0.x–9.2.x (PHP 8.1–8.5, Symfony 6.4): the new Admin API is not used; cache flushing
 * differs (`Tools::clearAllCache()`, M4).
 */
class Ps9 extends Ps81
{
    public function id(): string
    {
        return 'ps9';
    }

    public function hasFeatureValuePosition(): bool
    {
        return true;
    }
}
