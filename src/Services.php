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

use Context;
use Db;
use Kronixon\Connect\Api\Endpoints;
use Kronixon\Connect\Api\Kernel;
use Kronixon\Connect\Api\Router;
use Kronixon\Connect\Auth\DbGuards;
use Kronixon\Connect\Auth\SecretStore;
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

    public static function kernel(Context $context): Kernel
    {
        $settings = self::settings();
        $guards = new DbGuards(Db::getInstance(), _DB_PREFIX_, _DB_NAME_);
        $rate = (int) $settings->get('KX_RATE_LIMIT');

        return new Kernel(
            self::endpoints($context)->register(new Router()),
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
            }
        );
    }
}
