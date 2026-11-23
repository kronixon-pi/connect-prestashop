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
use Kronixon\Connect\Protocol;

/**
 * IdempotencyStore on the table `kx_idempotency`. Times are compared with FROM_UNIXTIME, as in DbGuards.
 * The body is kept base64-encoded: the connection's character set cannot change the bytes a replay sends.
 */
final class DbIdempotency implements IdempotencyStore
{
    /** @var Db */
    private $db;

    /** @var string */
    private $table;

    public function __construct(Db $db, string $prefix)
    {
        $this->db = $db;
        $this->table = '`'.$prefix.'kx_idempotency`';
    }

    public function find(string $key, int $now): ?array
    {
        $rows = $this->db->executeS('SELECT request_sha256, status_code, response_json FROM '.$this->table
            ." WHERE idempotency_key = '".$this->db->escape($key)."' AND created_at >= FROM_UNIXTIME(".($now - Protocol::IDEMPOTENCY_TTL_S).')', true, false);
        if (! is_array($rows) || $rows === []) {
            return null;
        }
        $body = base64_decode((string) $rows[0]['response_json'], true);

        return $body === false ? null : ['sha256' => (string) $rows[0]['request_sha256'], 'status' => (int) $rows[0]['status_code'], 'body' => $body];
    }

    public function save(string $key, string $sha256, int $status, string $body, int $now): void
    {
        $this->db->execute('DELETE FROM '.$this->table.' WHERE created_at < FROM_UNIXTIME('.($now - Protocol::IDEMPOTENCY_TTL_S).') LIMIT 1000', false);
        $this->db->execute('REPLACE INTO '.$this->table.' (idempotency_key, request_sha256, response_json, status_code, created_at) VALUES (\''
            .$this->db->escape($key)."', '".$this->db->escape($sha256)."', '".base64_encode($body)."', ".$status.', FROM_UNIXTIME('.$now.'))', false);
    }
}
