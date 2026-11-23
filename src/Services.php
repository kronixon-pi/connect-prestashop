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
use Context;
use Db;
use Employee;
use Kronixon\Connect\Api\Budget;
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
use Kronixon\Connect\Shop\PrestaShopInfo;
use Kronixon\Connect\Support\Log;
use Kronixon\Connect\Support\Settings;
use Kronixon\Connect\Support\ShopSettings;
use Kronixon\Connect\Support\SystemClock;
use Kronixon\Connect\Write\Batch;
use Kronixon\Connect\Write\Download;
use Kronixon\Connect\Write\Item;
use Kronixon\Connect\Write\PendingWrites;
use Kronixon\Connect\Write\Shop;
use Kronixon\Connect\Write\ShopDatabase;
use Kronixon\Connect\Write\Warnings;
use Kronixon\Connect\Write\WriteEndpoints;

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

                return new Shop($context, PrestaShopScope::of($context, $compat), $compat, $db, new PrestaShopImageUrls($context->link), new PendingWrites($db, (int) $context->shop->id, self::log()), $warnings, $options->bool('strict_languages') === true);
            },
            WriteEndpoints::writers(
                new Download(self::settings()->get(Pairing::ALLOW_HTTP) === '1'),
                (new PrestaShopInfo($context, $compat))->imageFormats()
            )
        );
    }

    public static function kernel(Context $context): Kernel
    {
        $settings = self::settings();
        $guards = new DbGuards(Db::getInstance(), _DB_PREFIX_, _DB_NAME_);
        $rate = (int) $settings->get('KX_RATE_LIMIT');

        return new Kernel(
            self::writes($context)->register(self::catalog($context)->register(self::endpoints($context)->register(new Router()))),
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

    /**
     * Before a read: at most PendingWrites::PER_READ objects a dead write left go (Kernel, under the write
     * lock), on the master connection and with the hooks of a write; nothing but one lookup when there is
     * no mark.
     *
     * @return callable(Budget): void
     */
    private static function recoverOnRead(Context $context): callable
    {
        return static function (Budget $budget) use ($context): void {
            $pending = new PendingWrites(new ShopDatabase(Db::getInstance(), _DB_PREFIX_), (int) $context->shop->id, self::log());
            if ($pending->any()) {
                self::backOfficeHooks($context);
                $pending->recover($budget, PendingWrites::PER_READ);
            }
        };
    }

    /** Hooks as in the back office (writes()). */
    private static function backOfficeHooks(Context $context): void
    {
        $context->employee = new Employee();
        Cache::clean('hook_module_exec_list_*');
    }
}
