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
use Feature;
use Kronixon\Connect\Catalog\AttributeReader;
use Kronixon\Connect\Catalog\Reader;
use ObjectModel;

/**
 * `POST attributes` (module/attributes.json): features and attribute groups without their values, through
 * `Feature` and `AttributeGroup`. `facets` need the `facets` capability, which this version does not
 * have: the item is written without them and says so in a warning.
 */
final class AttributeWriter extends ShopWriter
{
    public const FEATURE = 'feature';

    public const GROUP = 'attribute_group';

    /** `attribute_group_lang.public_name`; the name has 128. */
    private const PUBLIC_NAME = 64;

    public function write(Item $item): array
    {
        $kind = $item->choice('kind', [self::FEATURE, self::GROUP]);
        if ($kind === null || ! $item->has('name')) {
            throw ItemError::invalid($kind === null ? 'kind' : 'name');
        }
        if ($item->has('facets')) {
            $this->shop->warnings->add('facets_unsupported');
        }
        $id = $this->idOf($item, $kind, self::matches($item, 'name'));
        [$saved, $changed] = $this->save($item, $kind, $id);
        $this->shop->map->link($kind, $saved, $item->kxId());

        return self::done($saved, $id === null, $changed) + ['kind' => $kind];
    }

    /** The feature or group the item names; $byName: also by its name in the default language. */
    public function idOf(Item $item, string $kind, bool $byName): ?int
    {
        return $this->shop->map->resolve($item, $kind, function (int $id) use ($kind): bool {
            return $this->shop->holds($kind, $id);
        }, function () use ($item, $kind, $byName): array {
            $name = $this->shop->inDefaultLanguage($this->shop->texts->name($item, 'name', false));

            return $byName && $name !== '' ? $this->shop->named($kind, $name, $this->names($kind)) : [];
        });
    }

    /**
     * @return array{0: int, 1: bool} id and whether anything changed
     */
    public function save(Item $item, string $kind, ?int $id): array
    {
        $object = $kind === self::FEATURE ? new Feature($id) : new AttributeGroup($id);
        $fields = $this->fields($object, $item, $kind);
        $changed = $fields->changed();
        $fields->save($kind === self::FEATURE ? 'feature' : 'attribute group');
        $this->shop->remember($kind, $this->shop->inDefaultLanguage($this->shop->texts->name($item, 'name', false)), (int) $object->id);

        return [(int) $object->id, $changed];
    }

    /**
     * @param  Feature|AttributeGroup  $object
     */
    public function fields(ObjectModel $object, Item $item, string $kind): Fields
    {
        $id = (int) $object->id === 0 ? null : (int) $object->id;
        $fields = $this->shop->fields($object)->sentAs(['name' => $item->at('name'), 'public_name' => $item->at('public_name')]);
        $name = $this->shop->texts->name($item, 'name', $id === null);
        $fields->text('name', $name);
        if ($kind === self::GROUP) {
            $public = $this->shop->texts->name($item, 'public_name', false) ?? ($id === null ? $name : null);
            $fields->text('public_name', $public === null ? null : $this->publicNames($public + ($id === null ? (array) $name : [])));
            $fields->set('group_type', $item->choice('group_type', ['select', 'color', 'radio']) ?? ($id === null ? 'select' : null));
            if ($fields->changed(['group_type'])) {
                $fields->touch('is_color_group');
            }
        }
        $fields->set('position', $item->int('position'));

        return $fields;
    }

    public function hashes(array $results): array
    {
        $byKey = [];
        foreach ([self::FEATURE, self::GROUP] as $kind) {
            $byKey += $this->hashesOf($results, $kind, [], $kind);
        }

        return $byKey;
    }

    protected function reader(): Reader
    {
        return new AttributeReader($this->shop->db, $this->shop->scope);
    }

    /**
     * The names of the shop's features or groups in the default language.
     *
     * @return callable(): list<array<string, string|null>>
     */
    private function names(string $kind): callable
    {
        return function () use ($kind): array {
            return $this->shop->db->rows('SELECT s.id_'.$kind.' AS id, l.name FROM '.$this->shop->db->table($kind.'_shop').' s INNER JOIN '.$this->shop->db->table($kind.'_lang')
                .' l ON l.id_'.$kind.' = s.id_'.$kind.' AND l.id_lang = '.$this->shop->scope->defaultLangId.' WHERE s.id_shop = '.$this->shop->scope->shopId.' ORDER BY s.id_'.$kind);
        };
    }

    /**
     * @param  array<int, string>  $texts
     * @return array<int, string>
     */
    private function publicNames(array $texts): array
    {
        foreach ($texts as $langId => $text) {
            if (mb_strlen($text, 'UTF-8') > self::PUBLIC_NAME) {
                $texts[$langId] = rtrim(mb_substr($text, 0, self::PUBLIC_NAME, 'UTF-8'));
                $this->shop->warnings->add('public_name_cut');
            }
        }

        return $texts;
    }
}
