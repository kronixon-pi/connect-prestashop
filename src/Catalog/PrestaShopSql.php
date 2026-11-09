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
        $text = [];
        foreach ($rows as $row) {
            // PrestaShop 9 (PDO with native types) returns integers; 1.7.8 (mysqli) text. Readers see text.
            $text[] = array_map(static function ($value): ?string {
                return $value === null ? null : (is_bool($value) ? (string) (int) $value : (string) $value);
            }, $row);
        }

        return $text;
    }

    public function table(string $name): string
    {
        return '`'.$this->prefix.$name.'`';
    }
}
