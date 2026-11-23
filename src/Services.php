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

namespace Kronixon\Connect;

use Cache;
use Configuration;
use Context;
use Db;
use Dispatcher;
use Employee;
use Hook;
use Kronixon\Connect\Api\Endpoints;
use Kronixon\Connect\Api\Kernel;
use Kronixon\Connect\Api\Router;
use Kronixon\Connect\Auth\DbGuards;
use Kronixon\Connect\Auth\DbIdempotency;
use Kronixon\Connect\Auth\SecretStore;
use Kronixon\Connect\Catalog\CatalogEndpoints;
use Kronixon\Connect\Catalog\PrestaShopImageUrls;
use Kronixon\Connect\Catalog\PrestaShopScope;
use Kronixon\Connect\Catalog\PrestaShopSql;
use Kronixon\Connect\Catalog\Scope;
use Kronixon\Connect\Compat\Version;
use Kronixon\Connect\Crypto\ShopCipher;
use Kronixon\Connect\Platform\CurlTransport;
use Kronixon\Connect\Platform\Pairing;
use Kronixon\Connect\Platform\PlatformClient;
use Kronixon\Connect\Redirect\Front;
use Kronixon\Connect\Redirect\Pages;
use Kronixon\Connect\Redirect\RedirectStore;
use Kronixon\Connect\Shop\PrestaShopInfo;
use Kronixon\Connect\Support\Log;
use Kronixon\Connect\Support\Settings;
use Kronixon\Connect\Support\ShopSettings;
use Kronixon\Connect\Support\SystemClock;
use Kronixon\Connect\Write\Batch;
use Kronixon\Connect\Write\BulkEndpoints;
use Kronixon\Connect\Write\Download;
use Kronixon\Connect\Write\Facets;
use Kronixon\Connect\Write\Item;
use Kronixon\Connect\Write\Maintenance;
use Kronixon\Connect\Write\PendingWrites;
use Kronixon\Connect\Write\PriceBulk;
use Kronixon\Connect\Write\ReadRecovery;
use Kronixon\Connect\Write\Shop;
use Kronixon\Connect\Write\ShopDatabase;
use Kronixon\Connect\Write\StockBulk;
use Kronixon\Connect\Write\Warnings;
use Kronixon\Connect\Write\WriteEndpoints;
use Media;
use Module;
use PrestaShopAutoload;
use Product;
use ReflectionProperty;
use Search;
use StockAvailable;
use Tools;

/**
 * Wires the module's services with their PrestaShop implementations (the front controller, the
 * settings page, install and uninstall). Tests build the same objects with fakes.
 */
final class Services
{
    private function __construct()
    {
    }

    public static function settings(): Settings
    {
        return new ShopSettings();
    }

    public static function secrets(): SecretStore
    {
        return new SecretStore(self::settings(), new ShopCipher());
    }

    public static function log(): Log
    {
        return Log::forShop(Db::getInstance(), _DB_PREFIX_);
    }

    public static function budgetSeconds(): int
    {
        $budget = (int) self::settings()->get('KX_TIME_BUDGET');

        return $budget > 0 ? $budget : Protocol::TIME_BUDGET_S;
    }

    public static function endpoints(Context $context): Endpoints
    {
        $compat = Version::select(_PS_VERSION_);

        return new Endpoints(self::secrets(), new PrestaShopInfo($context, $compat), $compat, new SystemClock(), self::log(), self::budgetSeconds());
    }

    public static function pairing(Context $context): Pairing
    {
        $secrets = self::secrets();
        $compat = Version::select(_PS_VERSION_);

        return new Pairing(
            $secrets,
            new PlatformClient(new CurlTransport(), $secrets, new SystemClock()),
            self::endpoints($context),
            new PrestaShopInfo($context, $compat),
            self::settings(),
            new SystemClock(),
            self::log()
        );
    }

    /** The `GET catalog/*` readers on the shop's database (its read replica when it has one). */
    public static function catalog(Context $context): CatalogEndpoints
    {
        return new CatalogEndpoints(
            static function () use ($context): Scope {
                return PrestaShopScope::of($context, Version::select(_PS_VERSION_));
            },
            CatalogEndpoints::readers(new PrestaShopSql(Db::getInstance(_PS_USE_SQL_SLAVE_), _DB_PREFIX_), new PrestaShopImageUrls($context->link)),
            new SystemClock()
        );
    }

    /**
     * The `POST` endpoints that write the catalog. Everything is read and written on the master connection.
     *
     * Hooks run as in the back office, for every active module of the shop: an Employee that is not
     * loaded. With the service account (no rights) PrestaShop before 9.1 would run no module's hook,
     * because it asks whether the employee may view the module; without any employee it would leave out
     * modules by the visitor's device and customer group. The list it made for the request's first hook
     * is dropped.
     */
    public static function writes(Context $context): WriteEndpoints
    {
        $compat = Version::select(_PS_VERSION_);
        $db = new ShopDatabase(Db::getInstance(), _DB_PREFIX_);

        return new WriteEndpoints(
            new Batch($db, self::log()),
            static function (Warnings $warnings, Item $options) use ($context, $compat, $db): Shop {
                self::backOfficeHooks($context);

                $scope = PrestaShopScope::of($context, $compat);

                return new Shop($context, $scope, $compat, $db, new PrestaShopImageUrls($context->link), self::redirects($context), self::pages($scope), new PendingWrites($db, (int) $context->shop->id, self::log()), $warnings, $options->bool('strict_languages') === true, self::facets($db, $scope));
            },
            WriteEndpoints::writers(self::download(), (new PrestaShopInfo($context, $compat))->imageFormats())
        );
    }

    /**
     * Files (logos, images) come only from the platform the module is paired with: the host of its
     * Connect URL, of the pairing's API base and the pairing's `download_hosts`.
     */
    public static function download(): Download
    {
        $settings = self::settings();
        $hosts = [];
        foreach ([$settings->get(Pairing::PLATFORM_URL) ?: Protocol::DEFAULT_PLATFORM_URL, $settings->get(SecretStore::API_BASE)] as $url) {
            $parts = parse_url((string) $url);
            if (is_array($parts) && isset($parts['host']) && $parts['host'] !== '') {
                $hosts[] = $parts['host'].':'.($parts['port'] ?? (($parts['scheme'] ?? '') === 'http' ? 80 : 443));
            }
        }
        foreach (explode(',', (string) $settings->get(SecretStore::DOWNLOAD_HOSTS)) as $host) {
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return new Download($settings->get(Pairing::ALLOW_HTTP) === '1', $hosts);
    }

    /**
     * `prices/bulk`, `stock/bulk` and `maintenance/*`, on the master connection, with the hooks of the
     * back office (writes()).
     */
    public static function bulk(Context $context): BulkEndpoints
    {
        $db = new ShopDatabase(Db::getInstance(), _DB_PREFIX_);
        $shop = static function () use ($context, $db): array {
            self::backOfficeHooks($context);
            $scope = PrestaShopScope::of($context, Version::select(_PS_VERSION_));

            return [$scope, new PendingWrites($db, $scope->shopId, self::log()), $scope->shopId === (int) Configuration::get('PS_SHOP_DEFAULT')];
        };
        $hook = static function (string $name, array $params): void {
            Hook::exec($name, $params);
        };

        return new BulkEndpoints(
            static function () use ($db, $shop, $hook): PriceBulk {
                [$scope, $pending, $default] = $shop();

                return new PriceBulk($db, $scope, $pending, self::log(), $default, $hook, self::facets($db, $scope), static function (): void {
                    Product::flushPriceCache();
                    Cache::clean('Product::getPriceStatic*');
                    Cache::clean('Product::priceCalculation*');
                    Cache::clean('objectmodel_Product_*');
                    Cache::clean('objectmodel_Combination_*');
                });
            },
            static function () use ($db, $shop, $hook): StockBulk {
                [$scope, $pending, $default] = $shop();

                return new StockBulk($db, $scope, $pending, self::log(), $default, $hook, static function (int $product, int $variant, int $quantity, int $shopId): void {
                    StockAvailable::setQuantity($product, $variant, $quantity, $shopId);
                }, static function (int $product, int $policy, int $shopId, int $variant): void {
                    StockAvailable::setProductOutOfStock($product, $policy, $shopId, $variant);
                }, static function (): void {
                    Cache::clean('StockAvailable::*');
                    Cache::clean('objectmodel_Product_*');
                });
            },
            static function () use ($db, $shop): Maintenance {
                [$scope] = $shop();

                return new Maintenance($db, $scope, self::facets($db, $scope), static function (int $id): void {
                    Search::indexation(false, $id);
                }, static function (string $scope): void {
                    self::flush($scope);
                });
            }
        );
    }

    /** ps_facetedsearch when it is installed and active in the paired shop. */
    public static function facets(ShopDatabase $db, Scope $scope): Facets
    {
        $module = Module::isEnabled('ps_facetedsearch') ? Module::getInstanceByName('ps_facetedsearch') : false;

        return new Facets(is_object($module) ? $module : null, $db, $scope);
    }

    /** The caches of a `maintenance/cache-flush` scope, through PrestaShop's own APIs (Spec §6.29). */
    private static function flush(string $scope): void
    {
        if ($scope === 'prices' || $scope === 'all') {
            Product::flushPriceCache();
            Cache::clean('Product::getPriceStatic*');
            Cache::clean('Product::priceCalculation*');
        }
        if ($scope === 'products' || $scope === 'all') {
            Cache::clean('Product::*');
            Cache::clean('Category::*');
            Cache::clean('objectmodel_*');
        }
        if ($scope === 'smarty') {
            Tools::clearSmartyCache();
        }
        if ($scope === 'all') {
            // Smarty and Symfony's cache of the environment (removed at the end of the request).
            Tools::clearAllCache();
            Media::clearCache();
            // The class index of PrestaShop's own autoloader (Tools::generateIndex() up to 8.x); 9.x has none.
            if (class_exists('PrestaShopAutoload', false)) {
                PrestaShopAutoload::getInstance()->generateIndex();
            }
            Hook::exec('actionClearCache');
        }
    }

    public static function redirects(Context $context): RedirectStore
    {
        return new RedirectStore(new ShopDatabase(Db::getInstance(), _DB_PREFIX_), self::settings(), (int) $context->shop->id);
    }

    public static function front(Context $context): Front
    {
        $scope = PrestaShopScope::of($context, Version::select(_PS_VERSION_));

        return new Front(self::redirects($context), $context, $scope, self::pages($scope));
    }

    /** The dispatcher's routes; 1.7.8 and 8.0 have no method that answers them. */
    private static function pages(Scope $scope): Pages
    {
        return new Pages(new ShopDatabase(Db::getInstance(), _DB_PREFIX_), $scope, static function () use ($scope): array {
            $dispatcher = Dispatcher::getInstance();
            if (method_exists($dispatcher, 'getRoutes')) {
                return $dispatcher->getRoutes()[$scope->shopId] ?? [];
            }
            $routes = new ReflectionProperty($dispatcher, 'routes');
            if (PHP_VERSION_ID < 80100) {
                $routes->setAccessible(true);
            }

            return $routes->getValue($dispatcher)[$scope->shopId] ?? [];
        }, __PS_BASE_URI__, _PS_ROOT_DIR_);
    }

    public static function kernel(Context $context): Kernel
    {
        $settings = self::settings();
        $guards = new DbGuards(Db::getInstance(), _DB_PREFIX_, _DB_NAME_);
        $rate = (int) $settings->get('KX_RATE_LIMIT');

        return new Kernel(
            self::bulk($context)->register(self::writes($context)->register(self::catalog($context)->register(self::endpoints($context)->register(new Router())))),
            self::secrets(),
            new SystemClock(),
            $guards,
            $guards,
            self::log(),
            self::budgetSeconds(),
            $rate > 0 ? $rate : Protocol::RATE_LIMIT_PER_MINUTE,
            static function (int $now) use ($settings): void {
                // At most one write a minute: the settings page shows when the platform last called.
                if ($now - (int) $settings->get(Endpoints::LAST_REQUEST_AT) >= 60) {
                    $settings->set(Endpoints::LAST_REQUEST_AT, (string) $now);
                }
            },
            new DbIdempotency(Db::getInstance(), _DB_PREFIX_),
            self::recoverOnRead($context)
        );
    }

    /** Before a read (ReadRecovery), on the master connection. */
    private static function recoverOnRead(Context $context): ReadRecovery
    {
        return new ReadRecovery(
            new PendingWrites(new ShopDatabase(Db::getInstance(), _DB_PREFIX_), (int) $context->shop->id, self::log()),
            static function () use ($context): void {
                self::backOfficeHooks($context);
            }
        );
    }

    /** Hooks as in the back office (writes()). */
    private static function backOfficeHooks(Context $context): void
    {
        // Not loaded (id 0), so no module is left out; a stock movement it causes names it (9.x needs an int id).
        $employee = new Employee();
        $employee->id = 0;
        $employee->firstname = 'Kronixon';
        $employee->lastname = 'Connect';
        $context->employee = $employee;
        Cache::clean('hook_module_exec_list_*');
    }
}
