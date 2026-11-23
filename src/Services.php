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
use Kronixon\Connect\Outbox\CatalogEnricher;
use Kronixon\Connect\Outbox\Change;
use Kronixon\Connect\Outbox\Delivery;
use Kronixon\Connect\Outbox\EventEndpoints;
use Kronixon\Connect\Outbox\Events;
use Kronixon\Connect\Outbox\Flusher;
use Kronixon\Connect\Outbox\Heartbeat;
use Kronixon\Connect\Outbox\Origin;
use Kronixon\Connect\Outbox\OutboxStore;
use Kronixon\Connect\Outbox\Recorder;
use Kronixon\Connect\Outbox\ShopChanges;
use Kronixon\Connect\Platform\CurlTransport;
use Kronixon\Connect\Platform\Pairing;
use Kronixon\Connect\Platform\PlatformClient;
use Kronixon\Connect\Platform\PlatformError;
use Kronixon\Connect\Redirect\Front;
use Kronixon\Connect\Redirect\Pages;
use Kronixon\Connect\Redirect\RedirectStore;
use Kronixon\Connect\Shop\PrestaShopInfo;
use Kronixon\Connect\Support\Log;
use Kronixon\Connect\Support\Runtime;
use Kronixon\Connect\Support\Settings;
use Kronixon\Connect\Support\ShopSettings;
use Kronixon\Connect\Support\SystemClock;
use Kronixon\Connect\Update\LocalFiles;
use Kronixon\Connect\Update\Release;
use Kronixon\Connect\Update\ReleaseKeys;
use Kronixon\Connect\Update\Swap;
use Kronixon\Connect\Update\UpdateError;
use Kronixon\Connect\Update\Updater;
use Kronixon\Connect\Write\Batch;
use Kronixon\Connect\Write\BulkEndpoints;
use Kronixon\Connect\Write\Download;
use Kronixon\Connect\Write\Facets;
use Kronixon\Connect\Write\Item;
use Kronixon\Connect\Write\ItemError;
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
use stdClass;
use StockAvailable;
use Throwable;
use Tools;

/**
 * Wires the module's services with their PrestaShop implementations (the front controller, the
 * settings page, install and uninstall). Tests build the same objects with fakes.
 */
final class Services
{
    public const CRON_TOKEN = 'KX_CRON_TOKEN';

    /** @var array{0: Recorder, 1: ShopChanges}|null */
    private static $outbox;

    /** @var bool */
    private static $visitFlush = false;

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

        return new Download($settings->get(Pairing::ALLOW_HTTP) === '1', $hosts, null, $settings->get(SecretStore::DOWNLOAD_HOSTS_PAIRED) === '1');
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

                return new StockBulk($db, $scope, $pending, self::log(), $default, $hook, static function (int $id): StockAvailable {
                    return new StockAvailable($id);
                }, static function (array $products): void {
                    Cache::clean('StockAvailable::*');
                    Cache::clean('objectmodel_StockAvailable_*');
                    Cache::clean('objectmodel_Product_*');
                    // StockAvailable::postSave() before 9.0 drops a product's color list when one of its
                    // combinations is out of stock and unavailable combinations are hidden.
                    if ($products !== [] && version_compare(_PS_VERSION_, '9.0.0', '<') && ! Configuration::get('PS_DISP_UNAVAILABLE_ATTR')) {
                        foreach ($products as $id) {
                            Tools::clearColorListCache((int) $id);
                        }
                    }
                }, version_compare(_PS_VERSION_, '8.1.0', '>='));
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

    /**
     * The hooks' side of the outbox, on the request's own connection, once per request; null while the
     * shop is not paired (one cached setting: an unpaired shop pays nothing more).
     *
     * @return array{0: Recorder, 1: ShopChanges}|null
     */
    public static function outbox(): ?array
    {
        if (self::secrets()->storeId() === null) {
            return null;
        }
        if (self::$outbox === null) {
            $db = new ShopDatabase(Db::getInstance(), _DB_PREFIX_);
            self::$outbox = [new Recorder($db, self::settings(), new SystemClock(), self::log()), new ShopChanges($db)];
        }

        return self::$outbox;
    }

    /**
     * One flush of the outbox (Outbox\Flusher) on a connection of its own, so no transaction of the
     * request it runs after is part of it, under a server-wide lock that the server also releases when
     * the process dies. Null when the shop is not paired or another flush runs.
     *
     * @return array{sent: int, failed: int, batches: int, state: string}|null
     */
    public static function flushOutbox(Context $context, int $budgetSeconds): ?array
    {
        $secrets = self::secrets();
        $storeId = $secrets->storeId();
        $pairing = self::pairing($context);
        $base = $pairing->normaliseUrl($pairing->platformUrl());
        if ($storeId === null || $base === null) {
            return null;
        }
        // Db::__destruct() closes this connection when the call ends. Not disconnect(): the destructor
        // then reads the unset `$link`, and PHP logs a warning (a notice on 7.4) on every flush.
        $class = Db::getClass();
        $own = new $class(_DB_SERVER_, _DB_USER_, _DB_PASSWD_, _DB_NAME_, true);
        $lock = "'kx_outbox_".substr(sha1(_DB_NAME_.'.'._DB_PREFIX_), 0, 24)."'";
        if ((int) $own->getValue('SELECT GET_LOCK('.$lock.', 0)', false) !== 1) {
            return null;
        }
        try {
            // A row a checkout holds is waited for briefly; the batch is tried again later.
            $own->execute('SET SESSION innodb_lock_wait_timeout = 5', false);
            $db = new ShopDatabase($own, _DB_PREFIX_);
            $store = new OutboxStore($db);
            $compat = Version::select(_PS_VERSION_);
            $clock = new SystemClock();
            $client = new PlatformClient(new CurlTransport(), $secrets, $clock);
            // Once a day, under the flush lock (one process asks) and before the heartbeat that reports
            // it: is there a newer release (Update\Updater)?
            try {
                self::updater($context)->checkIfDue();
            } catch (Throwable $error) {
                // the flush runs without it
            }
            $heartbeat = self::heartbeat($context, $db, $store, $clock);

            return (new Flusher(
                $store,
                new Events(new CatalogEnricher($db, PrestaShopScope::of($context, $compat), new PrestaShopImageUrls($context->link))),
                static function (array $body, int $timeout) use ($client, $base, $storeId): array {
                    return $client->signed($base, 'POST', '/v1/stores/'.$storeId.'/events', $body, $timeout);
                },
                self::settings(),
                $clock,
                $heartbeat,
                self::log()
            ))->run(new Budget($clock, $budgetSeconds));
        } finally {
            $own->getValue('SELECT RELEASE_LOCK('.$lock.')', false);
        }
    }

    /** How the outbox reaches the platform on this server (Outbox\Delivery). */
    public static function delivery(): Delivery
    {
        return new Delivery([Runtime::class, 'canCall'], 'register_shutdown_function');
    }

    /**
     * On a page view (a GET): a flush after the answer, only when the server can send the answer first
     * (Outbox\Delivery::onPageView()), at most once a minute. A page view never waits for the platform.
     * Everything it reads here is cached by PrestaShop for the request: a page view costs no query.
     * A back-office page that Symfony sends (every one on 9.x) does not flush: Response::send() has
     * already called fastcgi_finish_request(), so the module's call returns false and the rows wait for
     * the next storefront view, the cron or a pull.
     */
    public static function flushOnVisit(Context $context): void
    {
        if (self::$visitFlush || Origin::isPlatform() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            return;
        }
        self::$visitFlush = self::delivery()->onPageView(self::settings(), time(), static function () use ($context): void {
            try {
                ignore_user_abort(true);
                self::flushOutbox($context, 5);
            } catch (Throwable $error) {
                // a page view never fails because of the outbox
            }
        });
    }

    /** The cron URL's token (KX_CRON_TOKEN), made the first time it is asked for. */
    public static function cronToken(): string
    {
        $token = self::settings()->get(self::CRON_TOKEN);

        return $token === null || strlen($token) < 32 ? self::rotateCronToken() : $token;
    }

    /** A new cron URL token; the old URL answers 403 from now on. */
    public static function rotateCronToken(): string
    {
        $token = bin2hex(random_bytes(24));
        self::settings()->set(self::CRON_TOKEN, $token);

        return $token;
    }

    /**
     * GET events and POST events/ack, on the master connection. A pull records the daily heartbeat when
     * it is due, so the platform hears how this shop delivers even when nothing flushes.
     */
    public static function events(Context $context): EventEndpoints
    {
        return new EventEndpoints(static function () use ($context): array {
            $db = new ShopDatabase(Db::getInstance(), _DB_PREFIX_);
            $store = new OutboxStore($db);
            try {
                self::heartbeat($context, $db, $store, new SystemClock())->recordIfDue();
            } catch (Throwable $error) {
                // the pull answers without it
            }

            return [$store, new Events(new CatalogEnricher($db, PrestaShopScope::of($context, Version::select(_PS_VERSION_)), new PrestaShopImageUrls($context->link)))];
        }, new SystemClock());
    }

    /** The daily module.heartbeat of this shop (Outbox\Heartbeat). */
    private static function heartbeat(Context $context, ShopDatabase $db, OutboxStore $store, SystemClock $clock): Heartbeat
    {
        return new Heartbeat(static function () use ($context): array {
            return self::endpoints($context)->capabilities();
        }, static function () use ($db): array {
            $counts = [];
            foreach (['products' => 'product', 'variants' => 'product_attribute', 'categories' => 'category', 'images' => 'image', 'brands' => 'manufacturer'] as $name => $table) {
                $counts[$name] = (int) ($db->rows('SELECT COUNT(*) AS n FROM '.$db->table($table))[0]['n'] ?? 0);
            }

            return $counts;
        }, static function () use ($db): array {
            $since = ' WHERE level = \'warning\' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)';
            $last = [];
            foreach ($db->rows('SELECT UNIX_TIMESTAMP(created_at) AS at, channel, message FROM '.$db->table('kx_log').$since.' ORDER BY id_kx_log DESC LIMIT 5') as $row) {
                $last[] = ['at' => gmdate('Y-m-d\TH:i:s\Z', (int) $row['at']), 'code' => (string) $row['channel'], 'message' => (string) $row['message']];
            }

            return ['count' => (int) ($db->rows('SELECT COUNT(*) AS n FROM '.$db->table('kx_log').$since)[0]['n'] ?? 0), 'last' => $last];
        }, $store, self::settings(), $clock, _PS_VERSION_, self::delivery(), static function () use ($context): array {
            return self::updater($context)->heartbeat();
        });
    }

    /**
     * The self-update (Update\Updater): the platform's signed `GET /v1/module/latest`, the zip from its hosts only
     * (download()), the module folder replaced next to itself (Swap), PrestaShop's own upgrade, the events through the
     * outbox, one update at a time (a lock the server releases when the process dies).
     */
    public static function updater(Context $context): Updater
    {
        $secrets = self::secrets();
        $clock = new SystemClock();
        $pairing = self::pairing($context);
        $lock = "'kx_update_".substr(sha1(_DB_NAME_.'.'._DB_PREFIX_), 0, 24)."'";

        return new Updater(
            self::settings(),
            ReleaseKeys::builtIn(),
            new Swap(new LocalFiles(), _PS_MODULE_DIR_),
            static function (array $query) use ($secrets, $clock, $pairing): array {
                $base = $pairing->normaliseUrl($pairing->platformUrl());
                if ($base === null) {
                    throw new PlatformError(0, 'platform_url_invalid');
                }

                return (new PlatformClient(new CurlTransport(), $secrets, $clock))->signed($base, 'GET', '/v1/module/latest', null, 10, $query);
            },
            static function (Release $release): string {
                try {
                    $path = self::download()->fetch($release->url, $release->sha256, 'release', _PS_CACHE_DIR_);
                } catch (ItemError $error) {
                    throw new UpdateError(UpdateError::DOWNLOAD, $error->itemCode());
                }
                $zip = file_get_contents($path);
                unlink($path);
                if (! is_string($zip)) {
                    throw new UpdateError(UpdateError::DOWNLOAD, 'download_failed');
                }

                return $zip;
            },
            [self::class, 'upgradeModule'],
            static function (string $version, bool $active): void {
                Module::upgradeModuleVersion(Protocol::MODULE_NAME, $version);
                $module = Module::getInstanceByName(Protocol::MODULE_NAME);
                if ($active && $module instanceof Module) {
                    $module->enable();
                }
            },
            static function (string $type, array $payload) use ($secrets): void {
                if ($secrets->storeId() !== null) {
                    (new OutboxStore(new ShopDatabase(Db::getInstance(), _DB_PREFIX_)))->insert(new Change($type, null, $payload), Recorder::time(microtime(true)));
                }
            },
            static function () use ($lock): bool {
                return (int) Db::getInstance()->getValue('SELECT GET_LOCK('.$lock.', 0)', false) === 1;
            },
            static function () use ($lock): void {
                Db::getInstance()->getValue('SELECT RELEASE_LOCK('.$lock.')', false);
            },
            $clock,
            self::log(),
            ['version' => Protocol::MODULE_VERSION, 'ps' => _PS_VERSION_, 'php' => PHP_VERSION, 'api_host' => Protocol::DEFAULT_PLATFORM_URL, 'active' => Module::isEnabled(Protocol::MODULE_NAME)]
        );
    }

    /**
     * PrestaShop's own upgrade of the module (`upgrade/upgrade-<version>.php` through Module::initUpgradeModule() and
     * runUpgradeModule(), as its module manager runs it on 1.7.8 to 9.x), with the code of the new files: it runs in
     * the first request after the swap. Null when the module row holds $to afterwards, else what stopped it.
     */
    public static function upgradeModule(string $from, string $to): ?string
    {
        $registered = static function (): string {
            return (string) Db::getInstance()->getValue('SELECT version FROM '._DB_PREFIX_.'module WHERE name = \''.Protocol::MODULE_NAME.'\'', false);
        };
        $module = Module::getInstanceByName(Protocol::MODULE_NAME);
        if (! $module instanceof Module || $module->version !== $to) {
            return 'the module files are not version '.$to;
        }
        $installed = $registered();
        if (version_compare($installed, $to, '<')) {
            $probe = new stdClass();
            $probe->name = Protocol::MODULE_NAME;
            $probe->version = $to;
            $probe->installed = true;
            $probe->database_version = $installed;
            if (Module::initUpgradeModule($probe)) {
                $upgrade = $module->runUpgradeModule();
                if (! empty($upgrade['version_fail']) || ! empty($upgrade['duplicate']) || ! empty($upgrade['number_upgrade_left'])) {
                    $errors = $module->getErrors();

                    return sprintf('upgrade-%s.php failed%s', (string) ($upgrade['version_fail'] ?: '?'), $errors === [] ? '' : ': '.implode(' ', $errors));
                }
            }
            Module::upgradeModuleVersion(Protocol::MODULE_NAME, $to);
        }

        return $registered() === $to ? null : 'the module row holds version '.$registered();
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
            self::events($context)->register(self::bulk($context)->register(self::writes($context)->register(self::catalog($context)->register(self::endpoints($context)->register(new Router()))))),
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
