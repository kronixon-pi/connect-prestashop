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
use Kronixon\Connect\Platform\Pairing;
use Kronixon\Connect\Protocol;
use Kronixon\Connect\Services;

/**
 * Kronixon Connect: the only link between this store and Kronixon PI (protocol v1).
 * 0.2.0 pairs with the platform from the settings page, verifies and signs every API request and
 * answer, and serves capabilities, profile, health, rotate and unpair. The hooks record nothing
 * until the outbox arrives.
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
                return (bool) $this->registerHook(Hooks::forCompat($compat));
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
        $pairing = Services::pairing($this->context);
        [$notice, $noticeIsError] = $this->handleForm($pairing);
        $secrets = Services::secrets();
        $state = $pairing->state();
        $paired = $secrets->isPaired();
        $date = function (?string $unix): string {
            return $unix === null ? '' : $this->displayTime((int) $unix);
        };
        $this->context->smarty->assign([
            'kx_action' => $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]),
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

        return $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    /**
     * The settings page's forms (the token of the AdminModules URL guards them).
     *
     * @return array{0: string, 1: bool} the message and whether it is an error
     */
    private function handleForm(Pairing $pairing): array
    {
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

    /**
     * Runs on every storefront request: must stay O(1) — M5.
     *
     * @param  array<string, mixed>  $params
     */
    public function hookActionFrontControllerSetMedia(array $params): void
    {
    }

    /**
     * Runs on every back-office request: must stay O(1) — M5.
     *
     * @param  array<string, mixed>  $params
     */
    public function hookActionAdminControllerSetMedia(array $params): void
    {
    }

    /**
     * Runs on every request that builds the router: must stay O(1) — M5.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function hookModuleRoutes(array $params): array
    {
        return [];
    }
}
