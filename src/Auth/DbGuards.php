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

namespace Kronixon\Connect\Auth;

use Db;
use InvalidArgumentException;
use Kronixon\Connect\Protocol;
use Kronixon\Connect\Support\Uuid;

/**
 * The nonce store on the table `kx_nonce` and the write lock on a MySQL named lock (`GET_LOCK`,
 * released by RELEASE_LOCK or when the connection ends, so a crashed request never keeps it).
 * Times are compared with FROM_UNIXTIME on both sides, so the session time zone does not matter.
 */
final class DbGuards implements NonceStore, WriteLock
{
    /** @var Db */
    private $db;

    /** @var string */
    private $table;

    /** @var string */
    private $lockName;

    public function __construct(Db $db, string $prefix, string $database)
    {
        $this->db = $db;
        $this->table = '`'.$prefix.'kx_nonce`';
        // MySQL lock names are server-wide: one per shop database and prefix, at most 64 characters.
        $this->lockName = 'kx_write_'.substr(sha1($database.'.'.$prefix), 0, 32);
    }

    public function claim(string $nonce, int $now): bool
    {
        if (! Uuid::isValid($nonce)) {
            throw new InvalidArgumentException('A nonce is a UUID.');
        }
        $this->db->execute('DELETE FROM '.$this->table.' WHERE `seen_at` < FROM_UNIXTIME('.($now - Protocol::NONCE_TTL_S).')');
        $inserted = $this->db->execute('INSERT IGNORE INTO '.$this->table.' (`nonce`, `seen_at`) VALUES (\''.strtolower($nonce).'\', FROM_UNIXTIME('.$now.'))');

        return $inserted && $this->db->Affected_Rows() === 1;
    }

    public function countSince(int $since): int
    {
        return (int) $this->db->getValue('SELECT COUNT(*) FROM '.$this->table.' WHERE `seen_at` >= FROM_UNIXTIME('.$since.')', false);
    }

    public function acquire(): bool
    {
        return (string) $this->db->getValue('SELECT GET_LOCK(\''.$this->lockName.'\', 0)', false) === '1';
    }

    public function release(): void
    {
        $this->db->getValue('SELECT RELEASE_LOCK(\''.$this->lockName.'\')', false);
    }
}
