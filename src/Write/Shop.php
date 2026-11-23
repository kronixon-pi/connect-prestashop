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

use Context;
use Kronixon\Connect\Catalog\ImageUrls;
use Kronixon\Connect\Catalog\Scope;
use Kronixon\Connect\Compat\CompatInterface;
use ObjectModel;
use Tools;
use Validate;

/**
 * What every writer of one request shares: the paired shop (Scope, Context), its database, the entity
 * map, the texts and the warnings of the item being written. Every lookup is limited to the paired
 * shop's own entities (`*_shop`), so an id of another shop is not found.
 */
final class Shop
{
    /** @var Context */
    public $context;

    /** @var Scope */
    public $scope;

    /** @var CompatInterface */
    public $compat;

    /** @var Database */
    public $db;

    /** @var ImageUrls */
    public $urls;

    /** @var EntityMap */
    public $map;

    /** @var Warnings */
    public $warnings;

    /** @var Texts */
    public $texts;

    /** @var PendingWrites */
    public $pending;

    /** @var array<string, array<string, list<int>>> match_by lists read in this request */
    private $names = [];

    public function __construct(Context $context, Scope $scope, CompatInterface $compat, Database $db, ImageUrls $urls, PendingWrites $pending, Warnings $warnings, bool $strictLanguages)
    {
        $this->context = $context;
        $this->scope = $scope;
        $this->compat = $compat;
        $this->db = $db;
        $this->urls = $urls;
        $this->pending = $pending;
        $this->map = new EntityMap($db, $pending);
        $this->warnings = $warnings;
        $this->texts = new Texts($scope, $warnings, $strictLanguages);
    }

    /** Whether entity $id of `<table>_shop` belongs to the paired shop, as a whole: never what a failed item left. */
    public function holds(string $table, int $id): bool
    {
        return ! $this->pending->isLeft($table, $id)
            && $this->db->rows('SELECT 1 FROM '.$this->db->table($table.'_shop').' WHERE id_'.$table.' = '.$id.' AND id_shop = '.$this->scope->shopId) !== [];
    }

    /**
     * The entities of a list whose name is $name (Texts::matchKey()). The list is read once a request
     * ($rows: `id` and `name`); remember() adds what the request creates.
     *
     * @param  callable(): list<array<string, string|null>>  $rows
     * @return list<int>
     */
    public function named(string $list, string $name, callable $rows): array
    {
        if (! isset($this->names[$list])) {
            $this->names[$list] = [];
            foreach ($rows() as $row) {
                $this->names[$list][Texts::matchKey((string) $row['name'])][] = (int) $row['id'];
            }
        }

        return array_values(array_unique($this->names[$list][Texts::matchKey($name)] ?? []));
    }

    /** An entity this request created or renamed, for the items that follow; unread lists need nothing. */
    public function remember(string $list, string $name, int $id): void
    {
        if (isset($this->names[$list])) {
            $this->names[$list][Texts::matchKey($name)][] = $id;
        }
    }

    public function fields(ObjectModel $object): Fields
    {
        return new Fields($object, $this->texts, static function (string $html): string {
            return (string) Tools::purifyHTML($html);
        }, [$this->pending, 'add']);
    }

    /**
     * After a rollback: what the item created goes, and the lists are read again.
     *
     * @return bool false when something is left
     */
    public function undo(): bool
    {
        $this->names = [];

        return $this->pending->undo();
    }

    /**
     * Friendly URL parts PrestaShop accepts (`Validate::isLinkRewrite`): as sent when it does, else made
     * from the sent text with a warning; an empty one is left out.
     *
     * @param  array<int, string>|null  $texts
     * @return array<int, string>|null
     */
    public function slugs(?array $texts): ?array
    {
        $slugs = [];
        foreach ($texts ?? [] as $langId => $text) {
            if (trim($text) === '') {
                continue;
            }
            $slugs[$langId] = $text;
            if (! Validate::isLinkRewrite($text) || mb_strlen($text, 'UTF-8') > 128) {
                $slugs[$langId] = $this->slug($text);
                $this->warnings->add('link_rewrite_changed');
            }
        }

        return $slugs === [] ? null : $slugs;
    }

    /** A friendly URL part made of a name; never empty. */
    public function slug(string $name): string
    {
        $slug = trim(mb_substr((string) Tools::str2url($name), 0, 128, 'UTF-8'), '-');

        return $slug === '' ? 'item' : $slug;
    }

    /**
     * The text in the shop's default language, else in the first language sent.
     *
     * @param  array<int, string>|null  $texts
     */
    public function inDefaultLanguage(?array $texts): string
    {
        return $texts === null || $texts === [] ? '' : ($texts[$this->scope->defaultLangId] ?? reset($texts));
    }
}
