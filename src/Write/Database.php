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

namespace Kronixon\Connect\Write;

use Kronixon\Connect\Catalog\Sql;

/**
 * The database as the writers use it: the lookups of Sql on the master, the transaction around one
 * item and statements on the module's own `kx_*` tables. Catalog tables are written by PrestaShop's
 * object model only, never by execute(); activate() is the one exception.
 */
interface Database extends Sql
{
    /** A statement on a `kx_*` table. */
    public function execute(string $sql): void;

    /**
     * `active = 1` in $table for the object $id of $class, and what PrestaShop cached of it is dropped:
     * an object PendingWrites created hidden is shown in the statement before its mark goes, in the
     * same transaction, without the hooks of an update (which may commit).
     */
    public function activate(string $class, string $table, string $primary, int $id): void;

    /** The text as a quoted SQL string. */
    public function quote(string $text): string;

    public function begin(): void;

    public function commit(): void;

    public function rollback(): void;
}
