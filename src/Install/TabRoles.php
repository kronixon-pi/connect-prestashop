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

use Db;
use InvalidArgumentException;

/**
 * The authorization roles PrestaShop creates for each of the module's back-office tabs
 * (`ROLE_MOD_TAB_<CLASS>_CREATE|READ|UPDATE|DELETE`). Removing the tab on uninstall deletes the
 * roles but not the `access` rows that grant them to profiles, so every uninstall would leave rows
 * pointing to roles that no longer exist. uninstall() reads the role ids before the tab goes and
 * deletes their `access` rows afterwards.
 */
final class TabRoles
{
    private const ACTIONS = ['CREATE', 'READ', 'UPDATE', 'DELETE'];

    /** @var callable(string): (array<int, array<string, mixed>>|false) */
    private $select;

    /** @var callable(string): bool */
    private $execute;

    /** @var string */
    private $prefix;

    /**
     * @param  callable(string): (array<int, array<string, mixed>>|false)  $select
     * @param  callable(string): bool  $execute
     */
    public function __construct(callable $select, callable $execute, string $prefix)
    {
        $this->select = $select;
        $this->execute = $execute;
        $this->prefix = $prefix;
    }

    public static function forShop(): self
    {
        return new self(static function (string $sql) {
            return Db::getInstance()->executeS($sql);
        }, static function (string $sql): bool {
            return (bool) Db::getInstance()->execute($sql);
        }, _DB_PREFIX_);
    }

    /**
     * The role ids of the given tab class names.
     *
     * @param  list<string>  $classNames
     * @return list<int>
     */
    public function roleIds(array $classNames): array
    {
        $slugs = [];
        foreach ($classNames as $className) {
            if (preg_match('/\A[A-Za-z0-9_]+\z/', $className) !== 1) {
                throw new InvalidArgumentException('Not a tab class name: '.$className);
            }
            foreach (self::ACTIONS as $action) {
                $slugs[] = "'ROLE_MOD_TAB_".strtoupper($className).'_'.$action."'";
            }
        }
        if ($slugs === []) {
            return [];
        }
        $rows = ($this->select)('SELECT `id_authorization_role` FROM `'.$this->prefix.'authorization_role` WHERE `slug` IN ('.implode(', ', $slugs).')');
        $ids = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $id = (int) ($row['id_authorization_role'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * Deletes the `access` rows of the given role ids (none: nothing to do).
     *
     * @param  list<int>  $roleIds
     */
    public function deleteAccess(array $roleIds): bool
    {
        if ($roleIds === []) {
            return true;
        }

        return ($this->execute)('DELETE FROM `'.$this->prefix.'access` WHERE `id_authorization_role` IN ('.implode(', ', array_map('intval', $roleIds)).')');
    }
}
