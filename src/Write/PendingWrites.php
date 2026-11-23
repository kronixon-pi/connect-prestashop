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

use Kronixon\Connect\Api\Budget;
use Kronixon\Connect\Support\Log;
use ObjectModel;
use Throwable;

/**
 * What the item being written has created, and `kx_pending`, which remembers it while the item is not
 * complete: a half-written object is found and removed whatever happened to the request.
 *
 * A module hooked to a save may commit the item's transaction (ps_facetedsearch truncates a table, and
 * MySQL commits before that) and another one may fail after it, inside PrestaShop's add(). The mark is
 * therefore written right after the object's own rows and before any hook of its kind, in the same
 * transaction (actionObjectAddAfter, where this module stands first): whoever commits the object commits
 * its mark, and a rollback takes both. seal() removes the marks in the transaction that completes the
 * item, so a mark that is there at the start of a batch names an object whose item never ended: the
 * request died, or the clean-up failed. recover() removes it, under the write lock. Until that succeeds
 * no item finds, adopts or creates again what is left (EntityMap).
 */
final class PendingWrites
{
    /**
     * Where others refer to an entity whose delete would take them along or leave them without it:
     * table => [table, column].
     */
    private const USED_BY = [
        'category' => ['category', 'id_parent'],
        'manufacturer' => ['product', 'id_manufacturer'],
        'feature' => ['feature_value', 'id_feature'],
        'feature_value' => ['feature_product', 'id_feature_value'],
        'attribute_group' => ['attribute', 'id_attribute_group'],
        'attribute' => ['product_attribute_combination', 'id_attribute'],
    ];

    /** Tables with a `date_add`: an object created at another time than its mark is somebody else's. */
    private const DATED = ['product', 'category', 'manufacturer'];

    /** Seconds between the object's `date_add` and its mark at most. */
    private const SAME_MOMENT = 120;

    /** @var self|null the one whose add() is running, for the hook */
    private static $listening;

    /** @var Database */
    private $db;

    /** @var int */
    private $shopId;

    /** @var Log */
    private $log;

    /** @var ObjectModel|null */
    private $adding;

    /** @var string */
    private $itemKey = '';

    /** @var string|null */
    private $kxId;

    /** @var list<ObjectModel> what the item created, or began to */
    private $created = [];

    /** @var array<string, array{0: string, 1: int}> `table:id` => table, id of the marks of the item */
    private $marked = [];

    /** @var array<string, string> `table:id` of what could not be removed => the kx_id of its item */
    private $left = [];

    public function __construct(Database $db, int $shopId, Log $log)
    {
        $this->db = $db;
        $this->shopId = $shopId;
        $this->log = $log;
    }

    /** What is created from here on belongs to this item. */
    public function item(Item $item): void
    {
        $this->itemKey = $item->key();
        $this->kxId = $item->kxId();
        $this->created = [];
        $this->marked = [];
    }

    /** PrestaShop's add(), with the object known whatever add() does. */
    public function add(ObjectModel $object): bool
    {
        $this->created[] = $object;
        $this->adding = $object;
        self::$listening = $this;
        try {
            return (bool) $object->add();
        } finally {
            self::$listening = null;
            $this->adding = null;
            $this->mark($object);
        }
    }

    /**
     * The module's handler of actionObjectAddAfter: the rows of $object are stored, no hook of its own
     * kind has run. Nothing for objects this request does not write.
     *
     * @param  mixed  $object
     */
    public static function added($object): void
    {
        if (self::$listening !== null && $object instanceof ObjectModel && $object === self::$listening->adding) {
            self::$listening->mark($object);
        }
    }

    /** The item is complete: the last statement of its transaction. */
    public function seal(): void
    {
        $objects = [];
        foreach ($this->marked as [$table, $id]) {
            $objects[] = '('.$this->object($table, $id).')';
        }
        if ($objects !== []) {
            $this->db->execute('DELETE FROM '.$this->db->table('kx_pending').' WHERE id_shop = '.$this->shopId.' AND ('.implode(' OR ', $objects).')');
        }
    }

    /** The item is committed: what it created stays. */
    public function keep(): void
    {
        $this->created = [];
        $this->marked = [];
    }

    /**
     * After a rollback: what the item created and somebody's hook committed goes, the last one first. One
     * that cannot be removed does not stop the others.
     *
     * @return bool false when something is left
     */
    public function undo(): bool
    {
        $created = array_reverse($this->created);
        $this->keep();
        $clean = true;
        foreach ($created as $object) {
            try {
                $clean = ($this->idOf($object) === 0 || $this->remove($object)) && $clean;
            } catch (Throwable $error) {
                $clean = false;
                $this->failed($object, $error);
            }
        }

        return $clean;
    }

    /** Before the first item: what an earlier request left half-written goes. */
    public function recover(Budget $budget): void
    {
        $this->left = [];
        foreach ($this->db->rows('SELECT class, entity, id_object, kx_id, removing, created_at FROM '.$this->db->table('kx_pending')
            .' WHERE id_shop = '.$this->shopId.' ORDER BY id_kx_pending DESC') as $row) {
            $gone = false;
            if (! $budget->exhausted()) {
                try {
                    $gone = $this->removeMarked($row);
                } catch (Throwable $error) {
                    $this->log->warning('write', 'A half-written object could not be removed', ['entity' => $row['entity'], 'id' => (int) $row['id_object'], 'exception' => get_class($error)]);
                }
            }
            if (! $gone) {
                $this->left[$row['entity'].':'.(int) $row['id_object']] = (string) $row['kx_id'];
            }
        }
    }

    /** Whether entity $id of $table is what a failed item left. */
    public function isLeft(string $table, int $id): bool
    {
        return isset($this->left[$table.':'.$id]);
    }

    /** Whether an earlier item with this kx_id left something. */
    public function claims(string $kxId): bool
    {
        return in_array(strtolower($kxId), $this->left, true);
    }

    private function mark(ObjectModel $object): void
    {
        $id = $this->idOf($object);
        $table = $this->tableOf($object);
        if ($id === 0 || isset($this->marked[$table.':'.$id])) {
            return;
        }
        $this->insert(get_class($object), $table, $id, (string) $this->kxId, 0, date('Y-m-d H:i:s'));
        $this->marked[$table.':'.$id] = [$table, $id];
    }

    /** Whether the object is gone, with its mark. */
    private function remove(ObjectModel $object): bool
    {
        [$table, $id] = [$this->tableOf($object), $this->idOf($object)];
        if (! $this->there($object, $id)) {
            $this->forget($table, $id);

            return true;
        }
        // The mark may have been rolled back with the item; from here on it also says that a removal began.
        $this->forget($table, $id, false);
        $this->insert(get_class($object), $table, $id, (string) $this->kxId, 1, date('Y-m-d H:i:s'));
        $object->delete();
        if ($this->there($object, $id)) {
            $this->failed($object, null);

            return false;
        }
        $this->forget($table, $id);

        return true;
    }

    /**
     * @param  array<string, string|null>  $row  of kx_pending
     */
    private function removeMarked(array $row): bool
    {
        [$class, $table, $id] = [(string) $row['class'], (string) $row['entity'], (int) $row['id_object']];
        if (! is_subclass_of($class, ObjectModel::class)) {
            return false;
        }
        $object = new $class($id);
        if (! $this->there($object, $id)) {
            if ((int) $row['removing'] === 1) {
                // The removal was cut short after the object's own row: once more, for what hangs on it.
                $object->id = $id;
                try {
                    $object->delete();
                } catch (Throwable $error) {
                    $this->failed($object, $error);
                    unset($this->left[$table.':'.$id]);
                }
            }
            $this->forget($table, $id);

            return true;
        }
        if (! $this->sameMoment($table, $object, $id, (string) $row['created_at'])) {
            $this->forget($table, $id, false);

            return true;
        }
        if ($this->used($table, $id)) {
            // By what this item itself left, or by somebody who works with the object since: then it stays.
            if ($this->left === []) {
                $this->forget($table, $id, false);
            }

            return $this->left === [];
        }
        [$this->itemKey, $this->kxId] = ['', $row['kx_id'] === '' ? null : $row['kx_id']];

        return $this->remove($object);
    }

    private function sameMoment(string $table, ObjectModel $object, int $id, string $markedAt): bool
    {
        if (! in_array($table, self::DATED, true)) {
            return true;
        }
        $rows = $this->db->rows('SELECT date_add FROM '.$this->db->table($table).' WHERE '.$this->primaryOf($object).' = '.$id);

        return abs((int) strtotime((string) ($rows[0]['date_add'] ?? '')) - (int) strtotime($markedAt)) <= self::SAME_MOMENT;
    }

    private function used(string $table, int $id): bool
    {
        if (! isset(self::USED_BY[$table])) {
            return false;
        }
        [$by, $column] = self::USED_BY[$table];

        return $this->db->rows('SELECT 1 FROM '.$this->db->table($by).' WHERE '.$column.' = '.$id.' LIMIT 1') !== [];
    }

    private function failed(ObjectModel $object, ?Throwable $error): void
    {
        [$table, $id] = [$this->tableOf($object), $this->idOf($object)];
        $this->left[$table.':'.$id] = (string) $this->kxId;
        $this->log->warning('write', 'A half-written object could not be removed', ['entity' => $table, 'id' => $id, 'exception' => $error === null ? null : get_class($error)]);
    }

    private function insert(string $class, string $table, int $id, string $kxId, int $removing, string $at): void
    {
        $this->db->execute('INSERT INTO '.$this->db->table('kx_pending').' (id_shop, class, entity, id_object, kx_id, item_key, removing, created_at) VALUES ('
            .$this->shopId.', '.$this->db->quote($class).', '.$this->db->quote($table).', '.$id.', '.$this->db->quote($kxId).', '.$this->db->quote($this->itemKey).', '.$removing.', '.$this->db->quote($at).')');
    }

    /** The mark goes; with $link also what kx_entity_map says of the removed object. */
    private function forget(string $table, int $id, bool $link = true): void
    {
        $this->db->execute('DELETE FROM '.$this->db->table('kx_pending').' WHERE id_shop = '.$this->shopId.' AND '.$this->object($table, $id));
        unset($this->left[$table.':'.$id]);
        if ($link) {
            (new EntityMap($this->db))->forgetEntity($table, $id);
        }
    }

    private function object(string $table, int $id): string
    {
        return 'entity = '.$this->db->quote($table).' AND id_object = '.$id;
    }

    private function there(ObjectModel $object, int $id): bool
    {
        return $this->db->rows('SELECT 1 FROM '.$this->db->table($this->tableOf($object)).' WHERE '.$this->primaryOf($object).' = '.$id) !== [];
    }

    private function tableOf(ObjectModel $object): string
    {
        return (string) ObjectModel::getDefinition($object)['table'];
    }

    private function primaryOf(ObjectModel $object): string
    {
        return (string) ObjectModel::getDefinition($object)['primary'];
    }

    /** PrestaShop unsets the id of an object it is about to create. */
    private function idOf(ObjectModel $object): int
    {
        return (int) ($object->id ?? 0);
    }
}
