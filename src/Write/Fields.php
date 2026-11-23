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
use Kronixon\Connect\Catalog\Decimal;
use ObjectModel;

/**
 * Sets an ObjectModel's fields and remembers which of them, in which language, really changed: an
 * update writes only those (`setFieldsToUpdate`), so a language or a field that was not sent keeps what
 * the shop holds, and nothing changed means no save, no hook and no new `date_upd`.
 */
final class Fields
{
    /** @var ObjectModel */
    private $object;

    /** @var Texts */
    private $texts;

    /** @var callable(string): string how the shop keeps an HTML text */
    private $html;

    /** @var array<string, true|array<int, true>> */
    private $changed = [];

    /** @var callable(ObjectModel): void told of every object a save created */
    private $created;

    /**
     * @param  callable(string): string  $html
     * @param  callable(ObjectModel): void  $created
     */
    public function __construct(ObjectModel $object, Texts $texts, callable $html, callable $created)
    {
        $this->object = $object;
        $this->texts = $texts;
        $this->html = $html;
        $this->created = $created;
    }

    public function creating(): bool
    {
        return (int) $this->object->id === 0;
    }

    /**
     * A flag, an id, a number or a code; null leaves the field alone.
     *
     * @param  bool|int|string|null  $value
     */
    public function set(string $field, $value): void
    {
        $same = is_string($value) ? (string) $this->object->{$field} === $value : (int) $this->object->{$field} === (int) $value;
        if ($value === null || ($same && ! $this->creating())) {
            return;
        }
        $this->object->{$field} = is_bool($value) ? (int) $value : $value;
        $this->changed[$field] = true;
    }

    /** A protocol amount; null leaves the field alone. */
    public function amount(string $field, ?string $value): void
    {
        if ($value === null || (! $this->creating() && Decimal::canonical(sprintf('%.6F', (float) $this->object->{$field})) === Decimal::canonical($value))) {
            return;
        }
        $this->object->{$field} = (float) $value;
        $this->changed[$field] = true;
    }

    /**
     * A translated text by language id; null leaves the field alone. A create fills the other
     * languages of the shop with the default language's text.
     *
     * @param  array<int, string>|null  $texts
     */
    public function text(string $field, ?array $texts, bool $html = false): void
    {
        if ($texts === null) {
            return;
        }
        $current = is_array($this->object->{$field}) ? $this->object->{$field} : [];
        if ($this->creating()) {
            $this->object->{$field} = $this->texts->everyLanguage($texts) + $current;

            return;
        }
        foreach ($texts as $langId => $text) {
            $kept = ContentHash::nfc($html ? ($this->html)($text) : $text);
            if (ContentHash::nfc((string) ($current[$langId] ?? '')) !== $kept) {
                $current[$langId] = $text;
                $this->changed[$field] = (isset($this->changed[$field]) && is_array($this->changed[$field]) ? $this->changed[$field] : []) + [$langId => true];
            }
        }
        $this->object->{$field} = $current;
    }

    /**
     * Whether any of the fields (or any field at all) changed.
     *
     * @param  list<string>  $fields
     */
    public function changed(array $fields = []): bool
    {
        return $fields === [] ? $this->changed !== [] : array_intersect($fields, array_keys($this->changed)) !== [];
    }

    /** Writes a field with the next save although its value did not change (a field PrestaShop derives). */
    public function touch(string $field): void
    {
        $this->changed[$field] = true;
    }

    /** PrestaShop's validation of what would be saved, before anything is written. */
    public function check(): void
    {
        if (! $this->creating()) {
            $this->object->setFieldsToUpdate($this->changed);
        }
        foreach ([$this->object->validateFields(false, true), $this->object->validateFieldsLang(false, true)] as $valid) {
            if ($valid !== true) {
                throw ItemError::refused((string) $valid);
            }
        }
    }

    /** Creates the object, or updates its changed fields; nothing when none changed. */
    public function save(string $what): void
    {
        if ($this->changed === [] && ! $this->creating()) {
            return;
        }
        $this->check();
        if ($this->creating()) {
            $saved = $this->object->add();
            ($this->created)($this->object);
            if (! $saved) {
                throw ItemError::notSaved($what);
            }
            $this->changed = [];

            return;
        }
        if (! $this->object->update()) {
            throw ItemError::notSaved($what);
        }
    }
}
