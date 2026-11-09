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

use Db;
use RuntimeException;

/**
 * Sql on the shop's database (the read replica when PrestaShop has one), without the query cache.
 */
final class PrestaShopSql implements Sql
{
    /** @var Db */
    private $db;

    /** @var string */
    private $prefix;

    public function __construct(Db $db, string $prefix)
    {
        $this->db = $db;
        $this->prefix = $prefix;
    }

    public function rows(string $sql): array
    {
        $rows = $this->db->executeS($sql, true, false);
        if (! is_array($rows)) {
            throw new RuntimeException('A catalog query failed.');
        }

        return array_values($rows);
    }

    public function table(string $name): string
    {
        return '`'.$this->prefix.$name.'`';
    }
}
