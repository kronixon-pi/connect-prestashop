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

namespace Kronixon\Connect\Catalog;

use InvalidArgumentException;
use Normalizer;
use stdClass;

/**
 * `content_hash` of a catalog item (README.md, "Catalog reads"): sha256 of the canonical JSON of the item without the
 * members that change without the content changing (dates, quantity, image URLs, the hash itself).
 *
 * Canonical as the platform's sync engine hashes a payload: maps sorted by key in byte order at every
 * depth, lists in their order (the readers sort every list whose order means nothing), strings in
 * Unicode NFC, integers, booleans and null as they are, an empty map `{}`, no floats; amounts as
 * decimal strings without trailing zeros (`"99.990000"` → `"99.99"`); JSON without escaped slashes or
 * Unicode. The same data gives the same hash on PHP 7.4–8.5 and PrestaShop 1.7.8–9.x
 * (tests/Unit/Catalog/content-hash-vectors.json).
 */
final class ContentHash
{
    /** Members whose values are amounts (README §2), at any depth. */
    public const AMOUNTS = ['price', 'wholesale_price', 'unit_price', 'price_impact', 'weight_impact',
        'weight', 'width', 'height', 'depth', 'price_te', 'reduction', ];

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    private function __construct()
    {
    }

    /**
     * @param  array<string, mixed>  $item  the item as it goes on the wire, without the excluded members
     */
    public static function of(array $item): string
    {
        return hash('sha256', self::json($item));
    }

    /**
     * The canonical JSON text the hash is computed over.
     *
     * @param  array<string, mixed>  $item
     */
    public static function json(array $item): string
    {
        $json = json_encode(self::canonical($item), self::JSON_FLAGS);
        if (! is_string($json)) {
            throw new InvalidArgumentException('The item cannot be encoded as JSON.');
        }

        return $json;
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    public static function canonical($value, ?string $key = null)
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
            if ($value === []) {
                return new stdClass();
            }
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $item) {
                $result[$k] = self::canonical($item, is_string($k) ? $k : $key);
            }
            if (! self::isList($result)) {
                ksort($result, SORT_STRING);
            }

            return $result;
        }
        if (is_float($value)) {
            throw new InvalidArgumentException('A float in a hashed item; amounts are strings.');
        }
        if (is_string($value)) {
            return $key !== null && in_array($key, self::AMOUNTS, true) ? Decimal::canonical($value) : self::nfc($value);
        }

        return $value;
    }

    /** NFC when intl is there (PrestaShop requires it); the text as it is otherwise. */
    public static function nfc(string $value): string
    {
        if (! class_exists(Normalizer::class)) {
            return $value;
        }
        $normal = Normalizer::normalize($value, Normalizer::FORM_C);

        return is_string($normal) ? $normal : $value;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function isList(array $value): bool
    {
        $i = 0;
        foreach ($value as $k => $unused) {
            if ($k !== $i++) {
                return false;
            }
        }

        return true;
    }
}
