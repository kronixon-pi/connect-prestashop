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
 * object model, never by execute(); the exceptions are setActive() and bulk(), which the protocol
 * defines as direct statements (README §6.2: prices/bulk, stock/bulk, ps_facetedsearch's tables).
 */
interface Database extends Sql
{
    /** A statement on a `kx_*` table. */
    public function execute(string $sql): void;

    /**
     * An UPDATE or INSERT on a catalog table of a bulk endpoint or on ps_facetedsearch's tables, inside
     * the endpoint's own transaction; the caller drops what PrestaShop cached of the rows. A statement
     * that waited longer than the lock wait or was a deadlock's victim is RowsLocked (after a deadlock
     * the server has rolled back the whole transaction); any other failure throws as well.
     *
     * @throws RowsLocked
     */
    public function bulk(string $sql): void;

    /**
     * `active` in $table for the object $id of $class, and what PrestaShop cached of it is dropped: an
     * object PendingWrites created hidden is shown in the statement before its mark goes, without the
     * hooks of an update, which run after the commit (PendingWrites::keep()). The two statements share
     * the item's transaction unless a hook has committed it earlier; then seal() hides the object again
     * when its mark cannot go.
     */
    public function setActive(string $class, string $table, string $primary, int $id, bool $active): void;

    /**
     * A SELECT of catalog rows the open transaction is about to check and write, with their row locks
     * (`FOR UPDATE`): a value another session committed while this one waited is what the read returns,
     * and a change another session tries after it waits until this transaction ends. The statement
     * reaches the rows by primary key, whose ORDER BY is the order they are locked in: one the server
     * plans as a scan locks every row it reads. A lock not granted within $waitSeconds (or a
     * deadlock) is RowsLocked; the transaction is then to be rolled back. $waitSeconds bounds every
     * lock wait of the transaction until it ends; then the session's own wait applies again.
     *
     * @return list<array<string, string|null>>
     *
     * @throws RowsLocked
     */
    public function lockRows(string $sql, int $waitSeconds): array;

    /** The text as a quoted SQL string. */
    public function quote(string $text): string;

    public function begin(): void;

    public function commit(): void;

    public function rollback(): void;

    /**
     * Opens the transaction of a bulk chunk, in which only the module's own statements run. The session
     * leaves autocommit, so a statement after a rollback the server made on its own (a deadlock's
     * victim) opens a new transaction instead of committing by itself; the first statement writes a
     * marker row in `kx_bulk_chunk`.
     */
    public function beginChunk(): void;

    /**
     * Commits the chunk if its transaction is still the one beginChunk() opened: the last statement
     * deletes the marker row, which a transaction the server rolled back no longer holds. Otherwise
     * ChunkRolledBack, and the caller rolls back. The session's autocommit and lock wait come back.
     *
     * @throws ChunkRolledBack
     */
    public function commitChunk(): void;

    /** Rolls the chunk back; the session's autocommit and lock wait come back. */
    public function rollbackChunk(): void;
}
