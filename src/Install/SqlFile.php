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

use InvalidArgumentException;

/**
 * Turns sql/*.sql into executable statements: drops `--` comment lines, splits on `;` at a line end,
 * replaces PREFIX_ and ENGINE_TYPE. The files hold DDL only (no string literals with `;`).
 */
final class SqlFile
{
    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function statements(string $sql, string $prefix, string $engine): array
    {
        if (preg_match('/\A[A-Za-z0-9_]*\z/', $prefix) !== 1 || preg_match('/\A[A-Za-z]+\z/', $engine) !== 1) {
            throw new InvalidArgumentException('Unexpected table prefix or engine.');
        }
        $lines = [];
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            if (preg_match('/\A\s*--/', $line) === 1) {
                continue;
            }
            $lines[] = $line;
        }
        $statements = [];
        foreach (preg_split('/;\s*(?:\R|\z)/', implode("\n", $lines)) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            $statements[] = str_replace(['PREFIX_', 'ENGINE_TYPE'], [$prefix, $engine], $statement);
        }

        return $statements;
    }
}
