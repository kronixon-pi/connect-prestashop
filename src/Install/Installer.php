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
 * Tables and Configuration keys of the module. The keypair, the cron token and the zero-right
 * Employee/Profile are created by M1/M5 (pairing, cron), not here.
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
        'KX_STORE_ID',
        'KX_API_BASE',
        'KX_PAIRED_AT',
        'KX_CRON_TOKEN',
        'KX_OUTBOX_LAST_FLUSH',
        'KX_PROFILE_CACHE',
        'KX_IP_ALLOWLIST',
        'KX_BLOG_ENABLED',
        'KX_BLOG_PREFIX',
    ];

    /** @var Db */
    private $db;

    /** @var string */
    private $sqlDir;

    /** @var string */
    private $prefix;

    /** @var string */
    private $engine;

    public function __construct(Db $db, string $sqlDir, string $prefix, string $engine)
    {
        $this->db = $db;
        $this->sqlDir = rtrim($sqlDir, '/');
        $this->prefix = $prefix;
        $this->engine = $engine;
    }

    public function createTables(): bool
    {
        return $this->run('install.sql');
    }

    public function dropTables(): bool
    {
        return $this->run('uninstall.sql');
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

    private function run(string $file): bool
    {
        $sql = file_get_contents($this->sqlDir.'/'.$file);
        if ($sql === false) {
            return false;
        }
        foreach (SqlFile::statements($sql, $this->prefix, $this->engine) as $statement) {
            if (! $this->db->execute($statement)) {
                return false;
            }
        }

        return true;
    }
}
