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

namespace Kronixon\Connect\Outbox;

use Kronixon\Connect\Support\Uuid;
use Kronixon\Connect\Write\Database;

/**
 * The statements on `kx_outbox`. Hooks only insert (Recorder). Everything else changes rows by primary
 * key in single autocommit statements and never holds a lock while it waits for another, so it cannot be
 * part of a deadlock with a checkout.
 *
 * A row is `pending` until a batch claims it (`sending`, its batch_id), then `sent`, `failed`, or
 * `pending` again when the batch did not reach the platform.
 */
final class OutboxStore
{
    /** Pending rows the table keeps at most before it merges them (Spec §7.5). */
    public const CAP = 50000;

    /** Attempts of a row before it is `failed` (Spec §7.3). */
    public const MAX_ATTEMPTS = 10;

    /** Rows per statement that lists ids. */
    private const CHUNK = 500;

    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function insert(Change $change, string $occurredAt): void
    {
        $this->db->execute('INSERT INTO '.$this->table().' (event_id, type, external_id, entity_key, payload, occurred_at) VALUES '.$this->values($change, $occurredAt));
    }

    /** The VALUES tuple of one change (Recorder writes several in one INSERT). */
    public function values(Change $change, string $occurredAt): string
    {
        return '('.$this->db->quote(Uuid::v4()).', '.$this->db->quote($change->type).', '
            .($change->externalId === null ? 'NULL' : $this->db->quote($change->externalId)).', '
            .$this->db->quote(substr($change->key, 0, 191)).', '
            .($change->payload === [] ? 'NULL' : $this->db->quote((string) json_encode($change->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))).', '
            .$this->db->quote($occurredAt).')';
    }

    public function table(): string
    {
        return $this->db->table('kx_outbox');
    }

    /**
     * Pending rows, oldest first.
     *
     * @return list<array<string, string|null>>
     */
    public function pending(int $limit): array
    {
        return $this->select("status = 'pending'", $limit);
    }

    /**
     * Rows a batch claimed and never finished (its process died): they go again in that batch.
     *
     * @return list<array<string, string|null>>
     */
    public function unfinished(int $limit): array
    {
        return $this->select("status = 'sending'", $limit);
    }

    /**
     * Claims pending rows for a batch; the rows it did get (another process may have taken or
     * acknowledged some meanwhile).
     *
     * @param  list<int>  $ids
     * @return list<array<string, string|null>>
     */
    public function claim(array $ids, string $batchId): array
    {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $this->db->execute('UPDATE '.$this->table()." SET status = 'sending', batch_id = ".$this->db->quote($batchId).', attempts = attempts + 1'
                .' WHERE id_outbox IN ('.self::in($chunk).") AND status = 'pending'");
        }

        return $this->select('batch_id = '.$this->db->quote($batchId)." AND status = 'sending'", count($ids));
    }

    /**
     * @param  list<int>  $ids
     */
    public function sent(array $ids, string $at): void
    {
        $this->update($ids, "status = 'sent', sent_at = ".$this->db->quote($at).', last_error = NULL', "status <> 'sent'");
    }

    /**
     * @param  list<int>  $ids
     */
    public function failed(array $ids, string $code): void
    {
        $this->update($ids, "status = 'failed', batch_id = NULL, last_error = ".$this->db->quote(substr($code, 0, 255)), "status = 'sending'");
    }

    /**
     * A batch that did not arrive: its rows are pending again, or failed after MAX_ATTEMPTS.
     *
     * @param  list<int>  $ids
     */
    public function release(array $ids, string $code): void
    {
        $this->update($ids, "status = 'failed', batch_id = NULL, last_error = ".$this->db->quote(substr($code, 0, 255)), "status = 'sending' AND attempts >= ".self::MAX_ATTEMPTS);
        $this->update($ids, "status = 'pending', batch_id = NULL, last_error = ".$this->db->quote(substr($code, 0, 255)), "status = 'sending'");
    }

    /**
     * @return array{pending: int, failed: int}
     */
    public function counts(): array
    {
        $counts = ['pending' => 0, 'failed' => 0];
        foreach ($this->db->rows('SELECT status, COUNT(*) AS n FROM '.$this->table()." WHERE status IN ('pending', 'sending', 'failed') GROUP BY status") as $row) {
            $status = $row['status'] === 'failed' ? 'failed' : 'pending';
            $counts[$status] += (int) $row['n'];
        }

        return $counts;
    }

    /**
     * GET events: pending and failed rows after $afterId, oldest first (Spec §6.34).
     *
     * @return list<array<string, string|null>>
     */
    public function pull(int $afterId, int $limit): array
    {
        return $this->select("status IN ('pending', 'failed') AND id_outbox > ".$afterId, $limit);
    }

    /**
     * POST events/ack: rows with these event ids are sent; the number that were not yet.
     *
     * @param  list<string>  $eventIds
     */
    public function ack(array $eventIds, string $at): int
    {
        $quoted = array_map([$this->db, 'quote'], array_map('strtolower', $eventIds));
        $ids = [];
        foreach ($this->db->rows('SELECT id_outbox FROM '.$this->table().' WHERE event_id IN ('.implode(', ', $quoted).") AND status <> 'sent'") as $row) {
            $ids[] = (int) $row['id_outbox'];
        }
        $this->sent($ids, $at);

        return count($ids);
    }

    /**
     * Above CAP pending rows: older rows of an entity that has a newer one go (they are one event
     * anyway). When the entities alone are more than CAP, every pending row goes and the caller sends
     * `module.outbox_overflow`.
     *
     * @return array{merged: int, since: string}|null what was dropped when everything went
     */
    public function bound(int $cap = self::CAP): ?array
    {
        if ($this->pendingCount() <= $cap) {
            return null;
        }
        $seen = [];
        $older = [];
        $since = null;
        $before = PHP_INT_MAX;
        do {
            $rows = $this->db->rows('SELECT id_outbox, entity_key, occurred_at FROM '.$this->table()
                ." WHERE status = 'pending' AND id_outbox < ".$before.' ORDER BY id_outbox DESC LIMIT 5000');
            foreach ($rows as $row) {
                $id = (int) $row['id_outbox'];
                $before = $id;
                $since = (string) $row['occurred_at'];
                if (isset($seen[(string) $row['entity_key']])) {
                    $older[] = $id;
                } else {
                    $seen[(string) $row['entity_key']] = true;
                }
                if (count($older) >= self::CHUNK) {
                    $this->delete($older);
                    $older = [];
                }
            }
        } while (count($rows) === 5000);
        $this->delete($older);
        if (count($seen) <= $cap) {
            return null;
        }
        $merged = 0;
        do {
            $ids = array_map(static function (array $row): int {
                return (int) $row['id_outbox'];
            }, $this->db->rows('SELECT id_outbox FROM '.$this->table()." WHERE status = 'pending' ORDER BY id_outbox LIMIT ".self::CHUNK));
            $this->delete($ids);
            $merged += count($ids);
        } while ($ids !== []);

        return ['merged' => $merged, 'since' => (string) $since];
    }

    /** Sent rows older than $sentBefore and failed ones older than $failedBefore go, at most 5 000. */
    public function prune(string $sentBefore, string $failedBefore): void
    {
        foreach (['sent' => $sentBefore, 'failed' => $failedBefore] as $status => $before) {
            $ids = array_map(static function (array $row): int {
                return (int) $row['id_outbox'];
            }, $this->db->rows('SELECT id_outbox FROM '.$this->table()." WHERE status = '".$status."' AND occurred_at < ".$this->db->quote($before).' ORDER BY id_outbox LIMIT 5000'));
            $this->delete($ids);
        }
    }

    public function pendingCount(): int
    {
        $rows = $this->db->rows('SELECT COUNT(*) AS n FROM '.$this->table()." WHERE status = 'pending'");

        return (int) ($rows[0]['n'] ?? 0);
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function select(string $where, int $limit): array
    {
        return $this->db->rows('SELECT id_outbox, event_id, type, external_id, entity_key, payload, occurred_at, status, batch_id, attempts FROM '
            .$this->table().' WHERE '.$where.' ORDER BY id_outbox LIMIT '.max(1, $limit));
    }

    /**
     * @param  list<int>  $ids
     */
    private function update(array $ids, string $set, string $where): void
    {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $this->db->execute('UPDATE '.$this->table().' SET '.$set.' WHERE id_outbox IN ('.self::in($chunk).') AND '.$where);
        }
    }

    /**
     * @param  list<int>  $ids
     */
    private function delete(array $ids): void
    {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $this->db->execute('DELETE FROM '.$this->table().' WHERE id_outbox IN ('.self::in($chunk).')');
        }
    }

    /**
     * @param  list<int>  $ids
     */
    private static function in(array $ids): string
    {
        return implode(',', array_map('intval', $ids));
    }
}
