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

namespace Kronixon\Connect\Catalog;

/**
 * Read-only SQL for the catalog readers. Every value a reader puts into a statement is an integer it
 * cast itself, a date it formatted itself or one of its own constants; the statements are plain
 * SELECTs that MySQL/MariaDB and SQLite (the unit tests) run alike, and every list order the answer
 * depends on is sorted in PHP, not by a collation.
 */
interface Sql
{
    /**
     * @return list<array<string, string|null>> every value as the database driver returns text
     */
    public function rows(string $sql): array;

    /** The table name with the shop's prefix, quoted: `ps_product`. */
    public function table(string $name): string;
}
