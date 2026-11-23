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

use FeatureValue;
use Kronixon\Connect\Catalog\AttributeValueReader;
use Kronixon\Connect\Catalog\Reader;
use ObjectModel;

/**
 * `POST attribute-values` (module/attribute-values.json): predefined values of features through
 * `FeatureValue` and values of attribute groups through `Attribute` / `ProductAttribute` (Compat).
 * `match_by` compares the default language among the parent's values (a feature's predefined ones).
 * No deletes or merges in v1.
 */
final class AttributeValueWriter extends ShopWriter
{
    /** kind => [entity type of the map and table, its parent column, member of the text] */
    private const KINDS = [
        AttributeWriter::FEATURE => ['feature_value', 'id_feature', 'value'],
        AttributeWriter::GROUP => ['attribute', 'id_attribute_group', 'name'],
    ];

    public function write(Item $item): array
    {
        $kind = $item->choice('kind', array_keys(self::KINDS));
        $parent = $item->id('parent_external_id');
        if ($kind === null || $parent === null) {
            throw ItemError::invalid($kind === null ? 'kind' : 'parent_external_id');
        }
        [$type, , $member] = self::KINDS[$kind];
        if (! $item->has($member)) {
            throw ItemError::invalid($member);
        }
        if (! $this->shop->holds($kind, $parent)) {
            throw ItemError::missing('parent_external_id', 'parent_not_found');
        }
        $id = $this->idOf($item, $kind, $parent, self::matches($item, $member) && $item->bool('custom') !== true);
        [$saved, $changed] = $this->save($item, $kind, $parent, $id);
        $this->shop->map->link($type, $saved, $item->kxId());

        return self::done($saved, $id === null, $changed) + ['kind' => $kind];
    }

    /** The parent's value the item names; $byName: also by its text in the default language. */
    public function idOf(Item $item, string $kind, int $parent, bool $byName): ?int
    {
        [$type, $parentColumn, $member] = self::KINDS[$kind];

        return $this->shop->map->resolve($item, $type, function (int $id) use ($type, $parentColumn, $parent): bool {
            return $this->shop->db->rows('SELECT 1 FROM '.$this->shop->db->table($type).' WHERE id_'.$type.' = '.$id.' AND '.$parentColumn.' = '.$parent) !== []
                && ($type !== 'attribute' || $this->shop->holds('attribute', $id));
        }, function () use ($item, $kind, $parent, $member, $byName): array {
            $text = $this->shop->inDefaultLanguage($this->shop->texts->name($item, $member, false));

            return $byName && $text !== '' ? $this->named($kind, $parent, $text) : [];
        });
    }

    /**
     * @return array{0: int, 1: bool} id and whether anything changed
     */
    public function save(Item $item, string $kind, int $parent, ?int $id): array
    {
        [$type, , $member] = self::KINDS[$kind];
        $object = $this->object($kind, $id);
        $fields = $this->fields($object, $item, $kind, $parent);
        $changed = $fields->changed();
        $fields->save('attribute value');
        if ($kind === AttributeWriter::GROUP || $item->bool('custom') !== true) {
            $this->shop->remember($type.':'.$parent, $this->shop->inDefaultLanguage($this->shop->texts->name($item, $member, false)), (int) $object->id);
        }

        return [(int) $object->id, $changed];
    }

    public function object(string $kind, ?int $id): ObjectModel
    {
        $attribute = $this->shop->compat->attributeClass();

        return $kind === AttributeWriter::FEATURE ? new FeatureValue($id) : new $attribute($id);
    }

    public function fields(ObjectModel $object, Item $item, string $kind, int $parent): Fields
    {
        [, $parentColumn, $member] = self::KINDS[$kind];
        $id = (int) $object->id === 0 ? null : (int) $object->id;
        $fields = $this->shop->fields($object);
        $custom = $kind === AttributeWriter::FEATURE && $item->bool('custom') === true;
        $fields->text($member, $this->shop->texts->name($item, $member, $id === null));
        if ($id === null) {
            $fields->set($parentColumn, $parent);
            $fields->set('custom', $kind === AttributeWriter::FEATURE ? $custom : null);
        }
        if ($kind === AttributeWriter::GROUP) {
            $color = $item->string('color');
            if ($color !== null && preg_match('/\A#[0-9a-fA-F]{6}\z/', $color) !== 1) {
                throw ItemError::invalid($item->at('color'));
            }
            $fields->set('color', $color ?? ($id === null ? '' : null));
        }
        if ($kind === AttributeWriter::GROUP || $this->shop->compat->hasFeatureValuePosition()) {
            $fields->set('position', $item->int('position'));
        }

        return $fields;
    }

    public function hashes(array $results): array
    {
        $byKey = [];
        foreach (array_keys(self::KINDS) as $kind) {
            $byKey += $this->hashesOf($results, $kind, ['kind' => $kind]);
        }

        return $byKey;
    }

    protected function reader(): Reader
    {
        return new AttributeValueReader($this->shop->db, $this->shop->scope);
    }

    /**
     * The values of a feature (predefined) or a group whose text is $text in the default language.
     *
     * @return list<int>
     */
    private function named(string $kind, int $parent, string $text): array
    {
        [$type, $parentColumn, $member] = self::KINDS[$kind];

        return $this->shop->named($type.':'.$parent, $text, function () use ($kind, $type, $parentColumn, $parent, $member): array {
            return $this->shop->db->rows('SELECT v.id_'.$type.' AS id, l.'.$member.' AS name FROM '.$this->shop->db->table($type).' v INNER JOIN '.$this->shop->db->table($type.'_lang')
                .' l ON l.id_'.$type.' = v.id_'.$type.' AND l.id_lang = '.$this->shop->scope->defaultLangId
                .($kind === AttributeWriter::GROUP ? ' INNER JOIN '.$this->shop->db->table('attribute_shop').' s ON s.id_attribute = v.id_attribute AND s.id_shop = '.$this->shop->scope->shopId : '')
                .' WHERE v.'.$parentColumn.' = '.$parent.($kind === AttributeWriter::FEATURE ? ' AND (v.custom IS NULL OR v.custom = 0)' : '').' ORDER BY v.id_'.$type);
        });
    }
}
