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
 * 0.4.0 writes the catalog: the table `kx_pending` and the hook that fills it, and `kx_redirect.path_from`
 * (empty before 0.4.0) compared byte for byte, as install.sql creates it.
 *
 * @param  KronixonConnect  $module
 */
function upgrade_module_0_4_0($module): bool
{
    return $module->addMissing()
        && Db::getInstance()->execute('ALTER TABLE `'._DB_PREFIX_.'kx_redirect` MODIFY `path_from` VARBINARY(764) NOT NULL');
}
