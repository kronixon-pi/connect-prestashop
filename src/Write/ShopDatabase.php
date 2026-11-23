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

use Cache;
use Db;
use Kronixon\Connect\Catalog\PrestaShopSql;
use RuntimeException;
use Throwable;

/**
 * Database on the shop's master connection, the one PrestaShop's object model writes through, so the
 * transaction covers an item's every save and the lookups read what it has just written.
 */
final class ShopDatabase implements Database
{
    /** @var Db */
    private $db;

    /** @var PrestaShopSql */
    private $sql;

    /** @var int|null the session's innodb_lock_wait_timeout before lockRows() changed it */
    private $serverWait;

    /** @var int|null the session's autocommit before beginChunk() turned it off */
    private $autocommit;

    /** @var int|null the open chunk's row in `kx_bulk_chunk` */
    private $marker;

    public function __construct(Db $db, string $prefix)
    {
        $this->db = $db;
        $this->sql = new PrestaShopSql($db, $prefix);
    }

    public function rows(string $sql): array
    {
        return $this->sql->rows($sql);
    }

    public function table(string $name): string
    {
        return $this->sql->table($name);
    }

    public function execute(string $sql): void
    {
        if (! $this->db->execute($sql, false)) {
            throw new RuntimeException('A statement on a module table failed.');
        }
    }

    public function bulk(string $sql): void
    {
        $this->statement($sql, 'A bulk statement failed.');
    }

    public function setActive(string $class, string $table, string $primary, int $id, bool $active): void
    {
        if (! $this->db->execute('UPDATE '.$this->table($table).' SET active = '.(int) $active.' WHERE '.$primary.' = '.$id, false)) {
            throw new RuntimeException('The active flag of an object could not be set.');
        }
        Cache::clean('objectmodel_'.$class.'_'.$id.'_*');
    }

    public function lockRows(string $sql, int $waitSeconds): array
    {
        // The session's wait for a row lock (InnoDB's default is 50 s, longer than a request's budget);
        // it also bounds the waits of the chunk's later statements, and the server's value comes back
        // when the transaction ends (restoreWait()).
        if ($this->serverWait === null) {
            $this->serverWait = $this->number('SELECT @@SESSION.innodb_lock_wait_timeout');
        }
        $this->statement('SET SESSION innodb_lock_wait_timeout = '.max(1, $waitSeconds), 'The lock wait could not be set.');
        try {
            return $this->rows($sql.' FOR UPDATE');
        } catch (Throwable $error) {
            // 1205 lock wait timeout, 1213 deadlock (_PS_DEBUG_SQL_ throws, otherwise executeS() is false).
            $code = (int) $this->db->getNumberError();
            if (in_array($code, [1205, 1213], true)) {
                throw new RowsLocked('The rows of a bulk chunk are locked by another session.', $code === 1213, $error);
            }
            throw $error;
        }
    }

    public function quote(string $text): string
    {
        return "'".$this->db->escape($text, true)."'";
    }

    public function begin(): void
    {
        $this->db->execute('START TRANSACTION', false);
    }

    public function commit(): void
    {
        $this->execute('COMMIT');
        $this->restoreWait();
    }

    /** Also drops what PrestaShop cached of the objects that no longer exist. */
    public function rollback(): void
    {
        $this->db->execute('ROLLBACK', false);
        $this->restoreWait();
        Cache::clean('*');
    }

    public function beginChunk(): void
    {
        if ($this->autocommit === null) {
            $this->autocommit = $this->number('SELECT @@SESSION.autocommit');
        }
        $this->statement('SET autocommit = 0', 'Autocommit could not be turned off.');
        $this->statement('INSERT INTO '.$this->table('kx_bulk_chunk').' (id_kx_bulk_chunk) VALUES (NULL)', 'The marker of a bulk chunk could not be written.');
        $this->marker = (int) $this->db->Insert_ID();
        if ($this->marker < 1) {
            throw new RuntimeException('The marker of a bulk chunk has no id.');
        }
    }

    public function commitChunk(): void
    {
        $this->statement('DELETE FROM '.$this->table('kx_bulk_chunk').' WHERE id_kx_bulk_chunk = '.(int) $this->marker, 'The marker of a bulk chunk could not be deleted.');
        if ((int) $this->db->Affected_Rows() !== 1) {
            // Its row went with a rollback the server made: what ran since is in another transaction.
            throw new ChunkRolledBack('The server rolled back a bulk chunk while it ran.');
        }
        $this->statement('COMMIT', 'A bulk chunk could not be committed.');
        $this->endChunk();
    }

    public function rollbackChunk(): void
    {
        $this->db->execute('ROLLBACK', false);
        $this->endChunk();
        Cache::clean('*');
    }

    /** Autocommit and the lock wait as before the chunk; nothing is open (ROLLBACK or COMMIT ran). */
    private function endChunk(): void
    {
        $this->marker = null;
        if ($this->autocommit !== null) {
            $this->db->execute('SET autocommit = '.($this->autocommit === 0 ? 0 : 1), false);
            $this->autocommit = null;
        }
        $this->restoreWait();
    }

    /** The session's lock wait as before lockRows(): hooks and logs after a chunk wait as the server lets them. */
    private function restoreWait(): void
    {
        if ($this->serverWait !== null) {
            $this->db->execute('SET SESSION innodb_lock_wait_timeout = '.max(1, $this->serverWait), false);
            $this->serverWait = null;
        }
    }

    /**
     * A statement whose failure throws: RowsLocked for a lock wait in vain or a deadlock (after which
     * the server has rolled back the whole transaction), RuntimeException otherwise.
     */
    private function statement(string $sql, string $message): void
    {
        $previous = null;
        try {
            $ok = (bool) $this->db->execute($sql, false);
        } catch (Throwable $error) {
            // _PS_DEBUG_SQL_ throws; otherwise execute() is false.
            $ok = false;
            $previous = $error;
        }
        if ($ok) {
            return;
        }
        $code = (int) $this->db->getNumberError();
        if (in_array($code, [1205, 1213], true)) {
            throw new RowsLocked('A statement of a bulk chunk waited for a row lock in vain.', $code === 1213, $previous);
        }
        throw new RuntimeException($message, 0, $previous);
    }

    /** A session variable; a failed read throws. */
    private function number(string $sql): int
    {
        $value = $this->db->getValue($sql, false);
        if ($value === false || $value === null) {
            throw new RuntimeException('A session variable could not be read.');
        }

        return (int) $value;
    }
}
