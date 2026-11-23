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

use Kronixon\Connect\Catalog\ContentHash;
use Kronixon\Connect\Catalog\Scope;

/**
 * Translated texts between the protocol (`{iso: text}`) and PrestaShop (`[id_lang => text]`). Only the
 * languages sent are there; a language the shop does not have is left out with a warning, or refused
 * when the request says `strict_languages`.
 */
final class Texts
{
    /** @var Scope */
    private $scope;

    /** @var Warnings */
    private $warnings;

    /** @var bool */
    private $strict;

    public function __construct(Scope $scope, Warnings $warnings, bool $strict = false)
    {
        $this->scope = $scope;
        $this->warnings = $warnings;
        $this->strict = $strict;
    }

    /**
     * The member's texts by language id; null when the member is absent.
     *
     * @return array<int, string>|null
     */
    public function of(Item $item, string $name): ?array
    {
        $map = $item->texts($name);
        if ($map === null) {
            return null;
        }
        $texts = [];
        foreach ($map as $iso => $text) {
            $id = $this->known((string) $iso, $item->at($name));
            if ($id !== null) {
                $texts[$id] = $text;
            }
        }

        return $texts;
    }

    /** The language of a member's text; null, with a warning, for one the shop does not have (refused when strict). */
    public function known(string $iso, string $member): ?int
    {
        $id = $this->languageId($iso);
        if ($id === null && $this->strict) {
            throw new ItemError('language_unknown', 'The shop has no language '.$iso, $member.'.'.$iso);
        }
        if ($id === null) {
            $this->warnings->add('language_unknown');
        }

        return $id;
    }

    /** `.iso` of a language of the shop, '' for another. */
    public function suffix(int $langId): string
    {
        return isset($this->scope->languages[$langId]) ? '.'.$this->scope->languages[$langId] : '';
    }

    /**
     * A one-line text (a name, a meta text): trimmed, line breaks as spaces, as PrestaShop keeps it.
     *
     * @return array<int, string>|null
     */
    public function line(Item $item, string $name): ?array
    {
        $texts = $this->of($item, $name);

        return $texts === null ? null : array_map([self::class, 'oneLine'], $texts);
    }

    /**
     * A required name: in the shop's default language when the entity is created, never empty.
     *
     * @return array<int, string>|null
     */
    public function name(Item $item, string $name, bool $creating): ?array
    {
        $texts = $this->line($item, $name);
        foreach ($texts ?? [] as $id => $text) {
            if ($text === '') {
                throw ItemError::invalid($item->at($name).'.'.$this->scope->languages[$id], 'is empty');
            }
        }
        if ($creating && ! isset($texts[$this->scope->defaultLangId])) {
            throw ItemError::invalid($item->at($name).'.'.($this->scope->languages[$this->scope->defaultLangId] ?? ''), 'is required');
        }

        return $texts;
    }

    /**
     * A meta text as PrestaShop accepts and keeps it (`Validate::isGenericName`, the column's length).
     *
     * @return array<int, string>|null
     */
    public function meta(Item $item, string $name, int $max): ?array
    {
        $texts = $this->line($item, $name);
        foreach ($texts ?? [] as $id => $text) {
            $clean = trim(str_replace(['<', '>', '=', '{', '}'], ' ', $text));
            $texts[$id] = mb_substr($clean, 0, $max, 'UTF-8');
            if ($texts[$id] !== $clean) {
                $this->warnings->add($name.'_cut');
            }
        }

        return $texts;
    }

    /**
     * The default language's text in every language of the shop that is not there (a create).
     *
     * @param  array<int, string>  $texts
     * @return array<int, string>
     */
    public function everyLanguage(array $texts): array
    {
        $default = $texts[$this->scope->defaultLangId] ?? '';

        return $texts + array_fill_keys(array_keys($this->scope->languages), $default);
    }

    public function languageId(string $iso): ?int
    {
        $id = array_search($iso, $this->scope->languages, true);

        return $id === false ? null : $id;
    }

    /** The form names are compared in (`match_by`): NFC, one space, trimmed, lower case. */
    public static function matchKey(string $text): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim(ContentHash::nfc($text))), 'UTF-8');
    }

    public static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s*[\r\n]+\s*/u', ' ', $text));
    }
}
