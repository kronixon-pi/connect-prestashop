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

namespace Kronixon\Connect\Install;

use Configuration;
use Db;
use Kronixon\Connect\Protocol;
use Kronixon\Connect\Support\Uuid;

/**
 * Tables, Configuration keys, the X25519 key pair and the service account of the module (the cron
 * token arrives with the outbox).
 */
final class Installer
{
    /** Every Configuration key the module owns (Spec §3.3, §4.4, §5.7, §6.2, §7.3, §8); removed on uninstall. */
    public const CONFIG_KEYS = [
        'KX_INSTANCE_ID',
        'KX_TIME_BUDGET',
        'KX_RATE_LIMIT',
        'KX_SECRET',
        'KX_SECRET_PREV',
        'KX_SECRET_PREV_UNTIL',
        'KX_PRIVATE_KEY',
        'KX_PUBLIC_KEY',
        'KX_STORE_ID',
        'KX_API_BASE',
        'KX_PAIRED_AT',
        'KX_PLATFORM_KEY',
        'KX_TENANT_NAME',
        'KX_PLATFORM_URL',
        'KX_PLATFORM_ALLOW_HTTP',
        'KX_PAIR_STATE',
        'KX_LAST_REQUEST_AT',
        'KX_PROFILE_ID',
        'KX_EMPLOYEE_ID',
        'KX_CRON_TOKEN',
        'KX_OUTBOX_LAST_FLUSH',
        'KX_PROFILE_CACHE',
        'KX_IP_ALLOWLIST',
        'KX_BLOG_ENABLED',
        'KX_BLOG_PREFIX',
    ];

    /** @var callable(string): bool */
    private $execute;

    /** @var string */
    private $sqlDir;

    /** @var string */
    private $prefix;

    /** @var string */
    private $engine;

    /**
     * @param  callable(string): bool  $execute  runs one DDL statement (Db::execute in the shop)
     */
    public function __construct(callable $execute, string $sqlDir, string $prefix, string $engine)
    {
        $this->execute = $execute;
        $this->sqlDir = rtrim($sqlDir, '/');
        $this->prefix = $prefix;
        $this->engine = $engine;
    }

    public static function forShop(string $sqlDir): self
    {
        return new self(static function (string $sql): bool {
            return (bool) Db::getInstance()->execute($sql);
        }, $sqlDir, _DB_PREFIX_, _MYSQL_ENGINE_);
    }

    /**
     * The install order: tables, the module row (Module::install), defaults and the key pair, the
     * service account, hooks. A failing step undoes itself and every earlier one (Steps), so a failed
     * install leaves no module row, no table, no account and no KX_* key behind.
     *
     * @param  callable(): bool  $addModule  Module::install() (the module row, tabs)
     * @param  callable(): bool  $removeModule  Module::uninstall() (also drops the hook registrations)
     * @param  callable(): bool  $registerHooks
     * @param  callable(): bool  $createKeys  the X25519 key pair (true without ext-sodium: pairing then explains)
     * @param  callable(): bool  $createAccount  ServiceAccount::create()
     * @param  callable(): bool  $removeAccount  ServiceAccount::remove()
     * @return string|null the step that failed (tables, module, configuration, service_account, hooks)
     */
    public function install(callable $addModule, callable $removeModule, callable $registerHooks, callable $createKeys, callable $createAccount, callable $removeAccount): ?string
    {
        return Steps::run([
            'tables' => [[$this, 'createTables'], [$this, 'dropTables']],
            'module' => [$addModule, $removeModule],
            'configuration' => [function () use ($createKeys): bool {
                return $this->writeDefaults() && $createKeys();
            }, [$this, 'deleteConfiguration']],
            'service_account' => [$createAccount, $removeAccount],
            'hooks' => [$registerHooks, static function (): bool {
                return true;
            }],
        ]);
    }

    /** Stops at the first failing statement (install() then drops what was created). */
    public function createTables(): bool
    {
        return $this->run('install.sql', true);
    }

    /** Runs every DROP TABLE IF EXISTS even after a failure, so it also cleans a half-created set. */
    public function dropTables(): bool
    {
        return $this->run('uninstall.sql', false);
    }

    public function writeDefaults(): bool
    {
        $ok = true;
        if ((string) Configuration::getGlobalValue('KX_INSTANCE_ID') === '') {
            $ok = Configuration::updateGlobalValue('KX_INSTANCE_ID', Uuid::v4());
        }

        return $ok
            && Configuration::updateGlobalValue('KX_TIME_BUDGET', (string) Protocol::TIME_BUDGET_S)
            && Configuration::updateGlobalValue('KX_RATE_LIMIT', '120');
    }

    public function deleteConfiguration(): bool
    {
        $ok = true;
        foreach (self::CONFIG_KEYS as $key) {
            $ok = Configuration::deleteByName($key) && $ok;
        }

        return $ok;
    }

    private function run(string $file, bool $stopOnError): bool
    {
        $sql = file_get_contents($this->sqlDir.'/'.$file);
        if ($sql === false) {
            return false;
        }
        $ok = true;
        foreach (SqlFile::statements($sql, $this->prefix, $this->engine) as $statement) {
            if (! ($this->execute)($statement)) {
                $ok = false;
                if ($stopOnError) {
                    return false;
                }
            }
        }

        return $ok;
    }
}
