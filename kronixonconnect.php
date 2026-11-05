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

require_once __DIR__.'/src/Autoloader.php';
Kronixon\Connect\Autoloader::register();

use Kronixon\Connect\Compat\UnsupportedVersion;
use Kronixon\Connect\Compat\Version;
use Kronixon\Connect\Install\Hooks;
use Kronixon\Connect\Install\Installer;
use Kronixon\Connect\Protocol;

/**
 * Kronixon Connect: the only link between this store and Kronixon PI (protocol v1).
 * M0 is the skeleton: tables, hooks (empty until M5) and the API front controller, which answers
 * every /v1 request with `403 store_unpaired` until pairing arrives (M1).
 */
class KronixonConnect extends Module
{
    public function __construct()
    {
        $this->name = Protocol::MODULE_NAME;
        $this->tab = 'administration';
        $this->version = Protocol::MODULE_VERSION;
        $this->author = 'Kronixon';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => Version::MIN, 'max' => '9.99.99'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Kronixon Connect');
        $this->description = $this->l('Connects this store to Kronixon PI over the signed Connect protocol v1.');
        $this->confirmUninstall = $this->l('The connection to Kronixon PI and its module data will be removed. Continue?');
    }

    public function install(): bool
    {
        try {
            $compat = Version::select(_PS_VERSION_);
        } catch (UnsupportedVersion $e) {
            $this->_errors[] = $this->l('Kronixon Connect supports PrestaShop 1.7.8 to 9.x.');

            return false;
        }
        $installer = $this->installer();

        return parent::install()
            && $installer->createTables()
            && $installer->writeDefaults()
            && $this->registerHook(Hooks::forCompat($compat));
    }

    public function uninstall(): bool
    {
        // M1 adds the best-effort POST /v1/stores/{id}/unpair before the secrets are deleted.
        $installer = $this->installer();

        return $installer->dropTables()
            && $installer->deleteConfiguration()
            && parent::uninstall();
    }

    public function getContent(): string
    {
        $compat = Version::isSupported(_PS_VERSION_) ? Version::select(_PS_VERSION_)->id() : '-';
        $this->context->smarty->assign([
            'kx_version' => $this->version,
            'kx_protocol' => Protocol::VERSION,
            'kx_ps_version' => _PS_VERSION_,
            'kx_php_version' => PHP_VERSION,
            'kx_compat' => $compat,
            'kx_api_url' => $this->context->link->getModuleLink($this->name, 'api', ['path' => 'v1/status'], true),
        ]);

        return $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    private function installer(): Installer
    {
        return new Installer(Db::getInstance(), __DIR__.'/sql', _DB_PREFIX_, _MYSQL_ENGINE_);
    }

    /*
     * Hook handlers: registered now so that an upgrade does not have to re-register them; they record
     * nothing until the outbox lands (M5). Each receives PrestaShop's hook parameters.
     */

    /** @param array<string, mixed> $params */
    public function hookActionProductAdd(array $params): void
    {
    }

    /** @param array<string, mixed> $params */
    public function hookActionProductUpdate(array $params): void
    {
    }

    /** @param array<string, mixed> $params */
    public function hookActionProductDelete(array $params): void
    {
    }

    /** @param array<string, mixed> $params */
    public function hookActionObjectProductUpdateAfter(array $params): void
    {
    }

    /** @param array<string, mixed> $params */
    public function hookActionUpdateQuantity(array $params): void
    {
    }

    /** @param array<string, mixed> $params */
    public function hookActionObjectCombinationAddAfter(array $params): void
    {
    }

    /** @param array<string, mixed> $params */
    public function hookActionObjectCombinationUpdateAfter(array $params): void
    {
    }

    /** @param array<string, mixed> $params */
    public function hookActionObjectCombinationDeleteAfter(array $params): void
    {
    }

    /** @param array<string, mixed> $params */
    public function hookActionObjectCategoryAddAfter(array $params): void
    {
    }

    /** @param array<string, mixed> $params */
    public function hookActionObjectCategoryUpdateAfter(array $params): void
    {
    }

    /** @param array<string, mixed> $params */
    public function hookActionObjectCategoryDeleteAfter(array $params): void
    {
    }

    /** @param array<string, mixed> $params */
    public function hookActionObjectImageAddAfter(array $params): void
    {
    }

    /** @param array<string, mixed> $params */
    public function hookActionObjectImageDeleteAfter(array $params): void
    {
    }

    /** @param array<string, mixed> $params */
    public function hookActionObjectManufacturerUpdateAfter(array $params): void
    {
    }

    /** @param array<string, mixed> $params */
    public function hookActionObjectFeatureValueAddAfter(array $params): void
    {
    }

    /**
     * PrestaShop 1.7.8 (class Attribute).
     *
     * @param  array<string, mixed>  $params
     */
    public function hookActionObjectAttributeAddAfter(array $params): void
    {
    }

    /**
     * PrestaShop 8.0+ (class ProductAttribute).
     *
     * @param  array<string, mixed>  $params
     */
    public function hookActionObjectProductAttributeAddAfter(array $params): void
    {
    }

    /** @param array<string, mixed> $params */
    public function hookActionFrontControllerSetMedia(array $params): void
    {
    }

    /** @param array<string, mixed> $params */
    public function hookActionAdminControllerSetMedia(array $params): void
    {
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function hookModuleRoutes(array $params): array
    {
        return [];
    }
}
