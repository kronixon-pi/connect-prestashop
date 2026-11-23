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
use Kronixon\Connect\Crypto\SealedBox;
use Kronixon\Connect\Install\Hooks;
use Kronixon\Connect\Install\Installer;
use Kronixon\Connect\Install\ServiceAccount;
use Kronixon\Connect\Install\TabRoles;
use Kronixon\Connect\Outbox\Flusher;
use Kronixon\Connect\Outbox\OutboxStore;
use Kronixon\Connect\Outbox\ShopChanges;
use Kronixon\Connect\Platform\Pairing;
use Kronixon\Connect\Protocol;
use Kronixon\Connect\Redirect\Front;
use Kronixon\Connect\Services;
use Kronixon\Connect\Support\Runtime;
use Kronixon\Connect\Update\UpdateError;
use Kronixon\Connect\Write\PendingWrites;
use Kronixon\Connect\Write\ShopDatabase;

/**
 * Kronixon Connect: the only link between this store and Kronixon PI (protocol v1).
 * It pairs with the platform from the settings page, verifies and signs every API request and answer,
 * reads and writes the catalog, and serves the redirects of `kx_redirect`. The other hooks record
 * nothing until the outbox arrives.
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
        $this->tabs = [[
            'class_name' => 'AdminKronixonConnect',
            'parent_class_name' => 'AdminAdvancedParameters',
            'name' => 'Kronixon Connect',
            'visible' => true,
        ]];

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

        // All or nothing: a failed install leaves no module row, table, account or KX_* key behind.
        $failed = $this->installer()->install(
            function (): bool {
                return (bool) parent::install();
            },
            function (): bool {
                return (bool) parent::uninstall();
            },
            function () use ($compat): bool {
                $registered = (bool) $this->registerHook(Hooks::forCompat($compat));
                Hooks::stayFirst($this);

                return $registered;
            },
            static function (): bool {
                if (SealedBox::available()) {
                    Services::secrets()->ensureKeyPair();
                }

                return true;
            },
            [ServiceAccount::class, 'create'],
            [ServiceAccount::class, 'remove']
        );
        if ($failed !== null) {
            $this->_errors[] = sprintf($this->l('Kronixon Connect could not be installed (step: %s). Nothing was left behind.'), $failed);
        }

        return $failed === null;
    }

    /** Tables and hooks a newer version has and an older one did not (upgrade/): all of them, what is there stays. */
    public function addMissing(): bool
    {
        $ok = $this->installer()->createTables() && (bool) $this->registerHook(Hooks::forCompat(Version::select(_PS_VERSION_)));
        Hooks::stayFirst($this);

        return $ok;
    }

    public function uninstall(): bool
    {
        // Best effort, short: the platform hears about it, the secrets go whatever it answers.
        try {
            if (Services::secrets()->storeId() !== null) {
                Services::pairing($this->context)->unpair('uninstall', 5);
            }
        } catch (Throwable $e) {
            // an unreachable platform never blocks an uninstall
        }
        $installer = $this->installer();
        $accountRemoved = ServiceAccount::remove();
        // PrestaShop deletes the tab's roles with the tab but not their `access` rows: read the ids now.
        $tabRoles = TabRoles::forShop();
        $roleIds = $tabRoles->roleIds(array_column($this->tabs, 'class_name'));

        return $installer->dropTables()
            && $installer->deleteConfiguration()
            && parent::uninstall()
            && $tabRoles->deleteAccess($roleIds)
            && $accountRemoved;
    }

    public function getContent(): string
    {
        $here = $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]);
        // The first request after new files were swapped in finishes the update with their code (Update\Updater),
        // then starts over: after a rollback the classes of this request are not those of the files.
        $updater = Services::updater($this->context);
        if ($updater->offer()['finishing'] !== null) {
            Runtime::keepRunning(300);
        }
        if ($updater->resume() !== null) {
            Tools::redirectAdmin($here);
        }
        $pairing = Services::pairing($this->context);
        [$notice, $noticeIsError] = $this->handleForm($pairing);
        $secrets = Services::secrets();
        $state = $pairing->state();
        $paired = $secrets->isPaired();
        $date = function (?string $unix): string {
            return $unix === null ? '' : $this->displayTime((int) $unix);
        };
        $this->context->smarty->assign([
            'kx_action' => $here,
            'kx_paired' => $paired,
            'kx_store_id' => (string) $secrets->storeId(),
            'kx_tenant' => (string) $secrets->setting('KX_TENANT_NAME'),
            'kx_paired_at' => $date($secrets->setting('KX_PAIRED_AT')),
            'kx_last_request' => $date($secrets->setting('KX_LAST_REQUEST_AT')),
            'kx_platform_url' => $pairing->platformUrl(),
            'kx_sodium' => SealedBox::available(),
            'kx_error' => ! $paired && ($state['state'] ?? '') === 'error' ? $this->errorMessage($state) : '',
            'kx_warning' => $paired && isset($state['warning']) ? $this->errorMessage(['code' => $state['warning']]) : '',
            'kx_notice' => $notice,
            'kx_notice_error' => $noticeIsError,
            'kx_version' => $this->version,
            'kx_protocol' => Protocol::VERSION,
            'kx_ps_version' => _PS_VERSION_,
            'kx_php_version' => PHP_VERSION,
            'kx_compat' => Version::isSupported(_PS_VERSION_) ? Version::select(_PS_VERSION_)->id() : '-',
        ]);
        if ($paired) {
            $this->assignOutbox($date);
        }
        $this->assignUpdate($paired, $date);

        return $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    /**
     * The module update panel (Spec §10 item 2): the release waiting for a click, or why none can be installed here,
     * and the outcome of the last attempt.
     *
     * @param  callable(?string): string  $date
     */
    private function assignUpdate(bool $paired, callable $date): void
    {
        $offer = Services::updater($this->context)->offer();
        $release = $offer['release'];
        $result = $offer['result'];
        $this->context->smarty->assign([
            'kx_update_paired' => $paired,
            'kx_update_checked' => $offer['checked_at'] === null ? '' : $date((string) $offer['checked_at']),
            'kx_update_check_error' => $offer['error'] === null ? '' : sprintf($this->l('Kronixon PI could not be asked for updates (%s).'), $offer['error']),
            'kx_update_version' => $release !== null ? $release->version : '',
            'kx_update_available' => $release !== null ? sprintf($this->l('Version %s is available.'), $release->version) : '',
            'kx_update_mandatory' => $release !== null && $release->mandatory,
            'kx_update_changelog' => $release !== null ? (string) $release->changelogUrl : '',
            'kx_update_confirm' => $release !== null ? sprintf($this->l('Update the module to version %s? If the upgrade fails, the current version is restored.'), $release->version) : '',
            'kx_update_installable' => $offer['installable'],
            'kx_update_url' => (string) $offer['url'],
            'kx_update_incompatible' => $offer['incompatible'] === null ? '' : sprintf($this->l('A newer version exists, but not for this shop (%s).'), $offer['incompatible']),
            'kx_update_token' => $this->updateToken(),
            'kx_update_result' => $result === null ? '' : $date((string) ($result['at'] ?? '')).' '.$this->updateMessage($result),
            'kx_update_result_error' => $result !== null && ($result['outcome'] ?? '') !== 'updated',
            'kx_update_finishing' => $offer['finishing'] === null ? '' : sprintf($this->l('The files of version %s are in place; the update finishes when this page is loaded again in a few seconds.'), $offer['finishing']),
        ]);
    }

    /**
     * The update forms' own token: the employee's, keyed with the shop's cookie keys. PrestaShop's admin token cannot
     * serve: on 9.x it is a Symfony CSRF token, different every time it is asked for. Empty without an employee.
     */
    private function updateToken(): string
    {
        $key = (defined('_COOKIE_KEY_') ? (string) constant('_COOKIE_KEY_') : '').(defined('_NEW_COOKIE_KEY_') ? (string) constant('_NEW_COOKIE_KEY_') : '');
        $employee = isset($this->context->employee) ? (int) $this->context->employee->id : 0;

        return $key === '' || $employee <= 0 ? '' : hash_hmac('sha256', 'kronixonconnect-update|'.$employee, $key);
    }

    /**
     * The administrator's message for an update outcome (Update\Updater, UpdateError kinds).
     *
     * @param  array<string, mixed>  $result
     */
    private function updateMessage(array $result): string
    {
        $from = (string) ($result['from'] ?? '');
        $to = (string) ($result['to'] ?? '');
        $code = (string) ($result['code'] ?? '');
        if (($result['outcome'] ?? '') === 'updated') {
            return sprintf($this->l('The module was updated from %1$s to %2$s.'), $from, $to);
        }
        switch ($result['kind'] ?? '') {
            case UpdateError::REFUSED:
                return sprintf($this->l('Version %1$s failed a check (%2$s) and was not installed.'), $to, $code);
            case UpdateError::DOWNLOAD:
                return sprintf($this->l('Version %1$s could not be downloaded (%2$s).'), $to, $code);
            case UpdateError::SERVER:
                return sprintf($this->l('This server does not let the module replace its own files (%s). Download the zip and upload it in the Module Manager.'), $code);
            case UpdateError::BUSY:
                return $this->l('Another update is running. Try again in a minute.');
            default:
                return $code === 'upgrade_failed'
                    ? sprintf($this->l('The update to %1$s failed (%2$s); the previous version was restored.'), $to, $code)
                    : sprintf($this->l('The update to %1$s failed and the previous version could not be restored (%2$s). Upload the zip in the Module Manager.'), $to, $code);
        }
    }

    /**
     * The store events panel: the outbox, the last delivery and error, the cron URL.
     *
     * @param  callable(?string): string  $date
     */
    private function assignOutbox(callable $date): void
    {
        $settings = Services::settings();
        try {
            $counts = (new OutboxStore(new ShopDatabase(Db::getInstance(), _DB_PREFIX_)))->counts();
        } catch (Throwable $e) {
            $counts = ['pending' => 0, 'failed' => 0];
        }
        $error = explode(' ', (string) $settings->get(Flusher::LAST_ERROR), 2);
        $retry = (int) $settings->get(Flusher::RETRY_AT);
        $this->context->smarty->assign([
            'kx_outbox_pending' => $counts['pending'],
            'kx_outbox_failed' => $counts['failed'],
            'kx_outbox_last_sent' => $date($settings->get(Flusher::LAST_SENT)),
            'kx_outbox_error' => (int) $error[0] > (int) $settings->get(Flusher::LAST_SENT) ? $date($error[0]).' '.($error[1] ?? '') : '',
            'kx_outbox_retry' => $retry > time() ? $date((string) $retry) : '',
            'kx_cron_url' => $this->context->link->getModuleLink($this->name, 'cron', ['token' => Services::cronToken()]),
            'kx_delivery' => Services::delivery()->mode($settings, time()),
        ]);
    }

    /**
     * The settings page's forms (the token of the AdminModules URL guards them).
     *
     * @return array{0: string, 1: bool} the message and whether it is an error
     */
    private function handleForm(Pairing $pairing): array
    {
        // The update runs only on the administrator's POST that carries the page's own token as well (Spec §10 item 2).
        $token = $this->updateToken();
        $posted = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $token !== ''
            && hash_equals($token, (string) Tools::getValue('kx_update_token'));
        if ($posted && Tools::isSubmit('submitKxUpdateCheck')) {
            $check = Services::updater($this->context)->check();

            return isset($check['error'])
                ? [sprintf($this->l('Kronixon PI could not be asked for updates (%s).'), (string) $check['error']), true]
                : [$this->l('Kronixon PI was asked for updates.'), false];
        }
        if ($posted && Tools::isSubmit('submitKxUpdate')) {
            Runtime::keepRunning(300);
            try {
                Services::updater($this->context)->install((string) Tools::getValue('kx_update_version'));
            } catch (UpdateError $error) {
                // Kept in KX_UPDATE_RESULT and shown in the update panel.
                return ['', false];
            }
            // The new files are in place: the next request (with their code) runs the upgrade (getContent()).
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]));
        }
        if (Tools::isSubmit('submitKxPlatformUrl')) {
            return $pairing->setPlatformUrl((string) Tools::getValue('kx_platform_url'))
                ? [$this->l('The platform URL was saved.'), false]
                : [$this->l('The platform URL must be an https:// address.'), true];
        }
        if (Tools::isSubmit('submitKxPair')) {
            $outcome = $pairing->pair((string) Tools::getValue('kx_code'));

            return [($outcome['state'] ?? '') === 'paired' ? $this->l('This shop is now paired with Kronixon PI.') : '', false];
        }
        if (Tools::isSubmit('submitKxUnpair')) {
            $pairing->unpair('user');

            return [$this->l('This shop is no longer paired with Kronixon PI.'), false];
        }
        if (Tools::isSubmit('submitKxRotateCron')) {
            Services::rotateCronToken();

            return [$this->l('The cron URL has a new token. Update the cron of your hosting; the old URL no longer works.'), false];
        }
        if (Tools::isSubmit('submitKxPing')) {
            $error = $pairing->ping();

            return $error === null ? [$this->l('Kronixon PI answered the signed test request.'), false] : [$this->errorMessage(['code' => $error]), true];
        }

        return ['', false];
    }

    /**
     * The administrator's message for a pairing outcome (the platform's error key, README §7).
     *
     * @param  array<string, mixed>  $state
     */
    private function errorMessage(array $state): string
    {
        $code = (string) ($state['code'] ?? '');
        $reason = (string) ($state['reason'] ?? '');
        $messages = [
            'pair_code_invalid' => $this->l('The pairing code is not valid. Check it and try again.'),
            'pair_code_expired' => $this->l('The pairing code has expired or was already used. Create a new one in Kronixon PI.'),
            'store_already_paired' => $this->l('This shop is connected to another Kronixon PI workspace, or the code belongs to another store.'),
            'store_url_insecure' => $this->l('Kronixon PI accepts only shops served over HTTPS.'),
            'validation_failed' => $this->l('Kronixon PI refused the pairing request.'),
            'module_too_old' => $this->l('This module version is too old for Kronixon PI. Update the module and try again.'),
            'rate_limited' => $this->l('Too many pairing attempts.'),
            'maintenance' => $this->l('Kronixon PI cannot pair stores right now.'),
            'protocol_version_unsupported' => $this->l('Kronixon PI does not support this protocol version.'),
            'network_error' => $this->l('Kronixon PI could not be reached. Check the platform URL and the outgoing connections of this server.'),
            'platform_key_mismatch' => $this->l('The platform key does not match the key built into this module.'),
            'sodium_missing' => $this->l('The PHP extension sodium is required for pairing. Ask your hosting provider to enable it.'),
            'platform_url_invalid' => $this->l('The platform URL must be an https:// address.'),
            'clock_skew' => $this->l('The clock of this server differs from Kronixon PI by more than 5 minutes; signed requests fail until it is corrected.'),
            'timestamp_out_of_window' => $this->l('The clock of this server differs from Kronixon PI by more than 5 minutes; signed requests fail until it is corrected.'),
            'store_unpaired' => $this->l('Kronixon PI did not accept the signed request of this shop yet.'),
        ];
        $reasons = [
            'store_url_mismatch' => $this->l('The code was issued for a store with another address than this shop.'),
            'out_of_scope' => $this->l('The address of this shop belongs to a store you may not manage in Kronixon PI.'),
            'verification_backoff' => $this->l('A recent check of this shop failed, so pairing it into this workspace is paused.'),
        ];
        $message = $messages[$code] ?? sprintf($this->l('The request to Kronixon PI failed (%s).'), $code);
        if (isset($reasons[$reason])) {
            $message .= ' '.$reasons[$reason];
        }
        if (isset($state['min_module_version'])) {
            $message .= ' '.sprintf($this->l('Required version: %s or newer.'), (string) $state['min_module_version']);
        }
        if (! empty($state['errors']) && is_array($state['errors'])) {
            $message .= ' '.sprintf($this->l('Fields: %s.'), implode(', ', $state['errors']));
        }
        if (isset($state['retry_until'])) {
            $message .= ' '.sprintf($this->l('Try again after %s.'), $this->displayTime((int) $state['retry_until']));
        }

        return $message;
    }

    /** A time in the employee's language format (Tools::displayDate() changed its signature in 8.0). */
    private function displayTime(int $unix): string
    {
        $format = (string) $this->context->language->date_format_full;

        return date($format !== '' ? $format : 'Y-m-d H:i:s', $unix);
    }

    private function installer(): Installer
    {
        return Installer::forShop(__DIR__.'/sql');
    }

    /*
     * Hook handlers. The change hooks of Spec §7.2 record store events in the outbox (Outbox\Recorder:
     * one INSERT, no network, nothing during the platform's own requests); they never throw. Each
     * receives PrestaShop's hook parameters.
     */

    /**
     * An object that a write of this request is creating is stored (PendingWrites); anything else the
     * shop stores is none of its business.
     *
     * @param  array<string, mixed>  $params
     */
    public function hookActionObjectAddAfter(array $params): void
    {
        PendingWrites::added($params['object'] ?? null);
    }

    /** @param array<string, mixed> $params */
    public function hookActionProductAdd(array $params): void
    {
        $this->recordChanges(static function (ShopChanges $changes) use ($params): array {
            return $changes->product($params, true);
        });
    }

    /** @param array<string, mixed> $params */
    public function hookActionProductUpdate(array $params): void
    {
        $this->recordChanges(static function (ShopChanges $changes) use ($params): array {
            return $changes->product(['id_product' => $params['id_product'] ?? null, 'product' => $params['product'] ?? null], false);
        });
    }

    /** @param array<string, mixed> $params */
    public function hookActionProductDelete(array $params): void
    {
        $this->recordChanges(static function (ShopChanges $changes) use ($params): array {
            return $changes->productDeleted($params);
        });
    }

    /** @param array<string, mixed> $params */
    public function hookActionObjectProductUpdateBefore(array $params): void
    {
        $this->recordChanges(static function (ShopChanges $changes) use ($params): array {
            $changes->beforeUpdate($params, 'product');

            return [];
        });
    }

    /** @param array<string, mixed> $params */
    public function hookActionObjectProductUpdateAfter(array $params): void
    {
        $this->recordChanges(static function (ShopChanges $changes) use ($params): array {
            return $changes->product($params, false);
        });
    }

    /** @param array<string, mixed> $params */
    public function hookActionUpdateQuantity(array $params): void
    {
        $this->recordChanges(static function (ShopChanges $changes) use ($params): array {
            return $changes->quantity($params);
        });
    }

    /** @param array<string, mixed> $params */
    public function hookActionObjectCombinationAddAfter(array $params): void
    {
        $this->recordChanges(static function (ShopChanges $changes) use ($params): array {
            return $changes->combination($params, 'created');
        });
    }

    /** @param array<string, mixed> $params */
    public function hookActionObjectCombinationUpdateBefore(array $params): void
    {
        $this->recordChanges(static function (ShopChanges $changes) use ($params): array {
            $changes->beforeUpdate($params, 'product_attribute');

            return [];
        });
    }

    /** @param array<string, mixed> $params */
    public function hookActionObjectCombinationUpdateAfter(array $params): void
    {
        $this->recordChanges(static function (ShopChanges $changes) use ($params): array {
            return $changes->combination($params, 'updated');
        });
    }

    /** @param array<string, mixed> $params */
    public function hookActionObjectCombinationDeleteAfter(array $params): void
    {
        $this->recordChanges(static function (ShopChanges $changes) use ($params): array {
            return $changes->combination($params, 'deleted');
        });
    }

    /** @param array<string, mixed> $params */
    public function hookActionObjectCategoryAddAfter(array $params): void
    {
        $this->recordChanges(static function (ShopChanges $changes) use ($params): array {
            return $changes->category($params, 'created');
        });
    }

    /**
     * A category that is active again answers with its own page (RedirectWriter).
     *
     * @param  array<string, mixed>  $params
     */
    public function hookActionObjectCategoryUpdateAfter(array $params): void
    {
        $this->recordChanges(static function (ShopChanges $changes) use ($params): array {
            return $changes->category($params, 'updated');
        });
        try {
            $category = $params['object'] ?? null;
            if ($category instanceof Category && (bool) $category->active && Services::redirects($this->context)->any()) {
                Services::front($this->context)->releaseCategory((int) $category->id);
            }
        } catch (Throwable $e) {
            // saving a category never fails because of a redirect
        }
    }

    /** @param array<string, mixed> $params */
    public function hookActionObjectCategoryDeleteAfter(array $params): void
    {
        $this->recordChanges(static function (ShopChanges $changes) use ($params): array {
            return $changes->category($params, 'deleted');
        });
    }

    /** @param array<string, mixed> $params */
    public function hookActionObjectImageAddAfter(array $params): void
    {
        $this->recordChanges(static function (ShopChanges $changes) use ($params): array {
            return $changes->image($params, 'created');
        });
    }

    /** @param array<string, mixed> $params */
    public function hookActionObjectImageDeleteAfter(array $params): void
    {
        $this->recordChanges(static function (ShopChanges $changes) use ($params): array {
            return $changes->image($params, 'deleted');
        });
    }

    /** @param array<string, mixed> $params */
    public function hookActionObjectManufacturerUpdateAfter(array $params): void
    {
        $this->recordChanges(static function (ShopChanges $changes) use ($params): array {
            return $changes->brand($params);
        });
    }

    /** @param array<string, mixed> $params */
    public function hookActionObjectFeatureValueAddAfter(array $params): void
    {
        $this->recordChanges(static function (ShopChanges $changes) use ($params): array {
            return $changes->attributeValue($params, 'feature');
        });
    }

    /**
     * PrestaShop 1.7.8 (class Attribute).
     *
     * @param  array<string, mixed>  $params
     */
    public function hookActionObjectAttributeAddAfter(array $params): void
    {
        $this->recordChanges(static function (ShopChanges $changes) use ($params): array {
            return $changes->attributeValue($params, 'attribute_group');
        });
    }

    /**
     * PrestaShop 8.0+ (class ProductAttribute).
     *
     * @param  array<string, mixed>  $params
     */
    public function hookActionObjectProductAttributeAddAfter(array $params): void
    {
        $this->recordChanges(static function (ShopChanges $changes) use ($params): array {
            return $changes->attributeValue($params, 'attribute_group');
        });
    }

    /**
     * @param  callable(ShopChanges): list<Kronixon\Connect\Outbox\Change>  $find
     */
    private function recordChanges(callable $find): void
    {
        try {
            $outbox = Services::outbox();
            if ($outbox !== null) {
                [$recorder, $changes] = $outbox;
                $recorder->capture(static function () use ($find, $changes): array {
                    return $find($changes);
                });
            }
        } catch (Throwable $e) {
            // a change of the shop never fails because of its event
        }
    }

    /**
     * Runs on every storefront request: cached settings only; at most once a minute it leaves a flush of
     * the outbox for after the answer (Services::flushOnVisit()).
     *
     * @param  array<string, mixed>  $params
     */
    public function hookActionFrontControllerSetMedia(array $params): void
    {
        try {
            Services::flushOnVisit($this->context);
        } catch (Throwable $e) {
            // a page view never fails because of the outbox
        }
    }

    /**
     * Runs on every back-office request, as on the storefront.
     *
     * @param  array<string, mixed>  $params
     */
    public function hookActionAdminControllerSetMedia(array $params): void
    {
        try {
            Services::flushOnVisit($this->context);
        } catch (Throwable $e) {
            // a page view never fails because of the outbox
        }
    }

    /**
     * Runs on every request that builds the router: one cached setting, and one indexed lookup on the
     * storefront of a shop that has redirects (`kx_redirect`). It adds no routes.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function hookModuleRoutes(array $params): array
    {
        try {
            if (Front::concerns($_SERVER) && Services::redirects($this->context)->any()) {
                Services::front($this->context)->serve((string) $_SERVER['REQUEST_URI']);
            }
        } catch (Throwable $e) {
            // the storefront works without redirects
        }

        return [];
    }
}
