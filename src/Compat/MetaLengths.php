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

namespace Kronixon\Connect\Compat;

use InvalidArgumentException;

/**
 * Lengths of the SEO meta fields per entity, the same on every supported PrestaShop version.
 *
 * They are the database columns of `install/data/db_structure.sql`, not the ObjectModel sizes:
 * `Product::$definition` declares `meta_title` 255, but `ps_product_lang.meta_title` is
 * `varchar(128)` on 1.7.8.11, 8.1.7 and 9.1.5, and PrestaShop saves a longer title silently cut to
 * 128 characters (its `sql_mode` is empty). The other `_lang` tables are 255/512.
 */
final class MetaLengths
{
    /** @var array<string, array{meta_title: int, meta_description: int}> entity => lengths */
    public const BY_ENTITY = [
        'product' => ['meta_title' => 128, 'meta_description' => 512],
        'category' => ['meta_title' => 255, 'meta_description' => 512],
        'brand' => ['meta_title' => 255, 'meta_description' => 512],
        'supplier' => ['meta_title' => 255, 'meta_description' => 512],
        'cms' => ['meta_title' => 255, 'meta_description' => 512],
    ];

    private function __construct()
    {
    }

    /**
     * @return array{meta_title: int, meta_description: int}
     */
    public static function of(string $entity): array
    {
        if (! isset(self::BY_ENTITY[$entity])) {
            throw new InvalidArgumentException('Unknown entity for meta lengths.');
        }

        return self::BY_ENTITY[$entity];
    }
}
