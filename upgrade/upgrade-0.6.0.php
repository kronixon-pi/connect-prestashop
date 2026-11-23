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
 * 0.6.0 records store events: `kx_outbox` gets its new shape (no version before wrote a row to it, so
 * the old table goes), the update hooks' Before hooks are registered and the module stands first on
 * every change hook.
 *
 * @param  KronixonConnect  $module
 */
function upgrade_module_0_6_0($module): bool
{
    $table = _DB_PREFIX_.'kx_outbox';
    $db = Db::getInstance();
    $shaped = $db->executeS('SHOW COLUMNS FROM `'.$table."` LIKE 'entity_key'");
    if (is_array($shaped) && $shaped === [] && ! $db->execute('DROP TABLE IF EXISTS `'.$table.'`')) {
        return false;
    }

    return $module->addMissing();
}
