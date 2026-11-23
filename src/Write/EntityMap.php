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

/**
 * Which shop entity an item names (README §6: `external_id` → `kx_id` → `match_by` → create) and the
 * `kx_entity_map` that remembers the platform's UUID of each entity.
 */
final class EntityMap
{
    /** Entity types that are not named as their table. */
    private const TABLES = ['brand' => 'manufacturer', 'variant' => 'product_attribute'];

    /** @var Database */
    private $db;

    /** @var PendingWrites|null */
    private $pending;

    public function __construct(Database $db, ?PendingWrites $pending = null)
    {
        $this->db = $db;
        $this->pending = $pending;
    }

    /**
     * The id the item names, null when it is to be created.
     *
     * A given `external_id` that does not exist is an error, never a silent create (a shop restored
     * from a backup would get duplicates); a remembered `kx_id` whose entity is gone is forgotten. What a
     * failed item left (PendingWrites) is neither written nor created a second time: `cleanup_failed`.
     *
     * @param  callable(int): bool  $exists  whether the entity is there, in the paired shop
     * @param  callable(): list<int>  $match  the entities `match_by` finds
     */
    public function resolve(Item $item, string $type, callable $exists, callable $match): ?int
    {
        $id = $item->id('external_id');
        if ($id !== null) {
            $this->refuseLeft($type, $id, $item->at('external_id'));
            if (! $exists($id)) {
                throw ItemError::missing($item->at('external_id'));
            }

            return $id;
        }
        $kxId = $item->kxId();
        if ($kxId !== null) {
            if ($this->pending !== null && $this->pending->claims($kxId)) {
                throw ItemError::leftOver($item->at('kx_id'));
            }
            $id = $this->find($type, $kxId);
            if ($id !== null && $exists($id)) {
                return $id;
            }
            if ($id !== null) {
                $this->forget($kxId);
            }
        }
        $candidates = $match();
        foreach ($candidates as $candidate) {
            $this->refuseLeft($type, $candidate, $item->at('match_by'));
        }
        if (count($candidates) > 1) {
            throw new ItemError('match_ambiguous', 'More than one entity matches', $item->at('match_by'), [
                'candidates' => array_map('strval', array_slice($candidates, 0, 20)),
            ]);
        }

        return $candidates[0] ?? null;
    }

    public function find(string $type, string $kxId): ?int
    {
        $rows = $this->db->rows('SELECT external_id FROM '.$this->db->table('kx_entity_map')
            .' WHERE kx_id = '.$this->db->quote(strtolower($kxId)).' AND entity_type = '.$this->db->quote($type));

        return $rows === [] ? null : (int) $rows[0]['external_id'];
    }

    /** Remembers that $kxId is entity $id; an older link of either is replaced (its flags stay with the entity). */
    public function link(string $type, int $id, ?string $kxId): void
    {
        if ($kxId === null) {
            return;
        }
        $kxId = strtolower($kxId);
        $table = $this->db->table('kx_entity_map');
        $entity = 'entity_type = '.$this->db->quote($type).' AND external_id = '.$this->db->quote((string) $id);
        $where = 'kx_id = '.$this->db->quote($kxId).' OR ('.$entity.')';
        $flags = 0;
        $linked = $this->db->rows('SELECT entity_type, external_id, kx_id, flags FROM '.$table.' WHERE '.$where);
        foreach ($linked as $row) {
            if ($row['entity_type'] === $type && (int) $row['external_id'] === $id) {
                if ($row['kx_id'] === $kxId) {
                    return;
                }
                $flags = (int) $row['flags'];
            }
        }
        if ($linked !== []) {
            $this->db->execute('DELETE FROM '.$table.' WHERE '.$where);
        }
        $now = $this->db->quote(date('Y-m-d H:i:s'));
        $this->db->execute('INSERT INTO '.$table.' (entity_type, external_id, kx_id, flags, date_add, date_upd) VALUES ('
            .$this->db->quote($type).', '.$this->db->quote((string) $id).', '.$this->db->quote($kxId).', '.$flags.', '.$now.', '.$now.')');
    }

    /** An entity of $table that was removed. */
    public function forgetEntity(string $table, int $id): void
    {
        $type = array_search($table, self::TABLES, true);
        $this->db->execute('DELETE FROM '.$this->db->table('kx_entity_map').' WHERE entity_type = '.$this->db->quote($type === false ? $table : $type)
            .' AND external_id = '.$this->db->quote((string) $id));
    }

    private function refuseLeft(string $type, int $id, string $field): void
    {
        if ($this->pending !== null && $this->pending->isLeft(self::TABLES[$type] ?? $type, $id)) {
            throw ItemError::leftOver($field);
        }
    }

    private function forget(string $kxId): void
    {
        $this->db->execute('DELETE FROM '.$this->db->table('kx_entity_map').' WHERE kx_id = '.$this->db->quote(strtolower($kxId)));
    }
}
