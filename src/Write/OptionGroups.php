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

use AttributeGroup;

/**
 * The option groups and values one `POST variants` item names, found in the shop (by `external_id`,
 * `kx_id`, then the name in the default language) or created through the writers of `attributes` and
 * `attribute-values`. No `kx_id` is linked: the platform's options are per product, a shop's group
 * serves many. read() only looks and checks; create() writes what is missing.
 */
final class OptionGroups
{
    /** PrestaShop has no limit; more than three options is a product model the platform does not make. */
    private const MANY = 3;

    /** @var Shop */
    private $shop;

    /** @var AttributeWriter */
    private $groups;

    /** @var AttributeValueWriter */
    private $values;

    /** @var array<string, array{0: Item, 1: int|null}> group key => item, id */
    private $groupItems = [];

    /** @var array<string, array{0: Item, 1: int|null, 2: string}> `group:value` => item, id, group key */
    private $valueItems = [];

    public function __construct(Shop $shop)
    {
        $this->shop = $shop;
        $this->groups = new AttributeWriter($shop);
        $this->values = new AttributeValueWriter($shop);
    }

    /**
     * @param  list<Item>  $groups
     */
    public function read(array $groups): void
    {
        $this->groupItems = [];
        $this->valueItems = [];
        if (count($groups) > self::MANY) {
            $this->shop->warnings->add('many_options');
        }
        foreach ($groups as $group) {
            $key = $group->key();
            if ($key === '' || isset($this->groupItems[$key])) {
                throw ItemError::invalid($group->at('key'));
            }
            $id = $this->groups->idOf($group, AttributeWriter::GROUP, true);
            if ($id === null) {
                $this->groups->fields(new AttributeGroup(), $group, AttributeWriter::GROUP)->check();
            }
            $this->groupItems[$key] = [$group, $id];
            foreach ($group->objects('values') ?? [] as $value) {
                $this->readValue($value, $key, $id);
            }
        }
    }

    /** Creates the groups and values the shop does not have; true when there were any. */
    public function create(): bool
    {
        $created = false;
        foreach ($this->groupItems as $key => [$item, $id]) {
            if ($id === null) {
                $this->groupItems[$key][1] = $this->groups->save($item, AttributeWriter::GROUP, null)[0];
                $created = true;
            }
        }
        foreach ($this->valueItems as $ref => [$item, $id, $groupKey]) {
            if ($id === null) {
                $this->valueItems[$ref][1] = $this->values->save($item, AttributeWriter::GROUP, (int) $this->groupItems[$groupKey][1], null)[0];
                $created = true;
            }
        }

        return $created;
    }

    /**
     * The value references of a combination, sorted: known ones, one of a group at most.
     *
     * @return list<string>
     */
    public function references(Item $combination): array
    {
        $refs = [];
        foreach ($combination->strings('values') ?? [] as $ref) {
            $group = $this->valueItems[$ref][2] ?? null;
            if ($group === null || isset($refs[$group])) {
                throw ItemError::invalid($combination->at('values'));
            }
            $refs[$group] = $ref;
        }
        if ($refs === []) {
            throw ItemError::invalid($combination->at('values'));
        }
        sort($refs);

        return $refs;
    }

    /**
     * The attribute ids of references, ascending (after create()).
     *
     * @param  list<string>  $refs
     * @return list<int>
     */
    public function attributeIds(array $refs): array
    {
        $ids = array_map(function (string $ref): int {
            return (int) $this->valueItems[$ref][1];
        }, $refs);
        sort($ids);

        return $ids;
    }

    /**
     * `groups` and `values` of the result. A value key two groups share is told apart as `group:value`.
     *
     * @return array<string, object>
     */
    public function ids(): array
    {
        $groups = [];
        foreach ($this->groupItems as $key => [, $id]) {
            $groups[$key] = (string) $id;
        }
        $values = [];
        foreach ($this->valueItems as $ref => [$item, $id]) {
            $values[isset($values[$item->key()]) ? $ref : $item->key()] = (string) $id;
        }

        return ['groups' => (object) $groups, 'values' => (object) $values];
    }

    private function readValue(Item $value, string $groupKey, ?int $groupId): void
    {
        $ref = $groupKey.':'.$value->key();
        if ($value->key() === '' || isset($this->valueItems[$ref])) {
            throw ItemError::invalid($value->at('key'));
        }
        if ($groupId === null && $value->id('external_id') !== null) {
            throw ItemError::missing($value->at('external_id'));
        }
        $id = $groupId === null ? null : $this->values->idOf($value, AttributeWriter::GROUP, $groupId, true);
        if ($id === null) {
            // A group that is yet to be created has no id to check the value with.
            $this->values->fields($this->values->object(AttributeWriter::GROUP, null), $value, AttributeWriter::GROUP, $groupId ?? 1)->check();
        }
        $this->valueItems[$ref] = [$value, $id, $groupKey];
    }
}
