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

use Kronixon\Connect\Support\Uuid;

/**
 * One item (or a nested object) of a write request, read member by member with the types of the
 * schemas: an absent member is null, a member of the wrong type is `validation_failed` with its path.
 * Unknown members are ignored (README §2).
 */
final class Item
{
    /** @var array<array-key, mixed> */
    private $data;

    /** @var string */
    private $path;

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function __construct(array $data, string $path = '')
    {
        $this->data = $data;
        $this->path = $path;
    }

    public function key(): string
    {
        $key = $this->data['key'] ?? null;

        return is_string($key) ? $key : '';
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->data);
    }

    /** The member's path inside the item, for `field`. */
    public function at(string $name): string
    {
        return $this->path === '' ? $name : $this->path.'.'.$name;
    }

    public function string(string $name, int $max = 0): ?string
    {
        $value = $this->data[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ($max > 0 && mb_strlen($value, 'UTF-8') > $max)) {
            throw ItemError::invalid($this->at($name));
        }

        return $value;
    }

    /** An `external_id`; null when absent, null or `"0"`. */
    public function id(string $name): ?int
    {
        $value = $this->string($name);
        if ($value === null || $value === '0') {
            return null;
        }
        if (preg_match('/\A[1-9][0-9]{0,19}\z/', $value) !== 1) {
            throw ItemError::invalid($this->at($name));
        }

        // Above PHP_INT_MAX: an id no shop reaches.
        return strlen($value) < 19 ? (int) $value : PHP_INT_MAX;
    }

    public function kxId(): ?string
    {
        $value = $this->string('kx_id');
        if ($value !== null && ! Uuid::isValid($value)) {
            throw ItemError::invalid($this->at('kx_id'));
        }

        return $value === null ? null : strtolower($value);
    }

    public function bool(string $name): ?bool
    {
        $value = $this->data[$name] ?? null;
        if ($value !== null && ! is_bool($value)) {
            throw ItemError::invalid($this->at($name));
        }

        return $value;
    }

    public function int(string $name, int $min = 0): ?int
    {
        $value = $this->data[$name] ?? null;
        if ($value !== null && (! is_int($value) || $value < $min)) {
            throw ItemError::invalid($this->at($name));
        }

        return $value;
    }

    /** An integer of any sign (a quantity). */
    public function integer(string $name): ?int
    {
        $value = $this->data[$name] ?? null;
        if ($value !== null && ! is_int($value)) {
            throw ItemError::invalid($this->at($name));
        }

        return $value;
    }

    /** An amount (README §2): a decimal string. */
    public function money(string $name): ?string
    {
        $value = $this->string($name);
        if ($value !== null && preg_match('/\A-?[0-9]{1,15}(\.[0-9]{1,6})?\z/', $value) !== 1) {
            throw ItemError::invalid($this->at($name));
        }

        return $value;
    }

    /**
     * @param  list<string>  $allowed
     */
    public function choice(string $name, array $allowed): ?string
    {
        $value = $this->string($name);
        if ($value !== null && ! in_array($value, $allowed, true)) {
            throw ItemError::invalid($this->at($name));
        }

        return $value;
    }

    /**
     * A list of strings; null when absent.
     *
     * @return list<string>|null
     */
    public function strings(string $name): ?array
    {
        $values = $this->data[$name] ?? null;
        if ($values === null) {
            return null;
        }
        if (! is_array($values) || $values !== array_values($values) || array_filter($values, 'is_string') !== $values) {
            throw ItemError::invalid($this->at($name));
        }

        return $values;
    }

    /**
     * A translated text `{iso: text}`; null when absent.
     *
     * @return array<string, string>|null
     */
    public function texts(string $name): ?array
    {
        $map = $this->data[$name] ?? null;
        if ($map === null) {
            return null;
        }
        $texts = [];
        foreach (is_array($map) ? $map : [null] as $iso => $text) {
            if (! is_string($text) || preg_match('/\A[a-z]{2}\z/', (string) $iso) !== 1) {
                throw ItemError::invalid($this->at($name));
            }
            $texts[(string) $iso] = $text;
        }

        return $texts;
    }

    public function object(string $name): ?self
    {
        $value = $this->data[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_array($value) || ($value !== [] && isset($value[0]))) {
            throw ItemError::invalid($this->at($name));
        }

        return new self($value, $this->at($name));
    }

    /**
     * A list of objects; null when absent.
     *
     * @return list<self>|null
     */
    public function objects(string $name): ?array
    {
        $values = $this->data[$name] ?? null;
        if ($values === null) {
            return null;
        }
        if (! is_array($values) || $values !== array_values($values)) {
            throw ItemError::invalid($this->at($name));
        }
        $items = [];
        foreach ($values as $i => $value) {
            if (! is_array($value)) {
                throw ItemError::invalid($this->at($name).'.'.$i);
            }
            $items[] = new self($value, $this->at($name).'.'.$i);
        }

        return $items;
    }

    /**
     * The members of a `{iso: […]}` map (tags).
     *
     * @return array<string, list<string>>|null
     */
    public function lists(string $name): ?array
    {
        $map = $this->object($name);
        if ($map === null) {
            return null;
        }
        $lists = [];
        foreach (array_keys($map->data) as $iso) {
            $lists[(string) $iso] = $map->strings((string) $iso) ?? [];
        }

        return $lists;
    }
}
