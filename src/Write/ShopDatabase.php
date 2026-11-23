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
        if (! $this->db->execute($sql, false)) {
            throw new RuntimeException('A bulk statement failed.');
        }
    }

    public function setActive(string $class, string $table, string $primary, int $id, bool $active): void
    {
        if (! $this->db->execute('UPDATE '.$this->table($table).' SET active = '.(int) $active.' WHERE '.$primary.' = '.$id, false)) {
            throw new RuntimeException('The active flag of an object could not be set.');
        }
        Cache::clean('objectmodel_'.$class.'_'.$id.'_*');
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
    }

    /** Also drops what PrestaShop cached of the objects that no longer exist. */
    public function rollback(): void
    {
        $this->db->execute('ROLLBACK', false);
        Cache::clean('*');
    }
}
