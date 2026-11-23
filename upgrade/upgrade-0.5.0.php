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

if (! defined('_PS_VERSION_')) {
    exit;
}

/**
 * 0.5.0 writes in bulk: the table `kx_bulk_chunk`, whose marker row tells a bulk chunk that its
 * transaction is still its own before COMMIT.
 *
 * @param  KronixonConnect  $module
 */
function upgrade_module_0_5_0($module): bool
{
    return $module->addMissing();
}
