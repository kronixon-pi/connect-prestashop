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

namespace Kronixon\Connect\Support;

/**
 * The module's global key/value settings (PrestaShop `Configuration`, shop-independent values).
 * Only keys listed in Installer::CONFIG_KEYS are written, so uninstall removes every one.
 */
interface Settings
{
    /**
     * The stored value, null when the key is absent or empty. In the shop this is the copy PrestaShop loaded when the
     * request started: another process's later write is not seen.
     */
    public function get(string $key): ?string;

    /**
     * The value in the database now, past any copy the request holds: for decisions taken under a lock about a value
     * another request may have changed since this one started.
     */
    public function fresh(string $key): ?string;

    public function set(string $key, string $value): bool;

    public function delete(string $key): bool;
}
