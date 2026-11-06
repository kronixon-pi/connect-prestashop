<?php

declare(strict_types=1);
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
if (! defined('_PS_VERSION_')) {
    exit;
}

/**
 * The Back Office menu entry "Advanced Parameters > Kronixon Connect": opens the module's settings
 * page (status, pairing, unpairing), the one place where the module is configured.
 */
class AdminKronixonConnectController extends ModuleAdminController
{
    /**
     * @return void
     */
    public function init(): void
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, [], ['configure' => 'kronixonconnect']));
    }
}
