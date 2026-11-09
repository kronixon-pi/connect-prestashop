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

/**
 * Amounts as protocol strings (README §2): a dot and 6 decimals on the wire (`"99.990000"`), never a
 * float on the way. PrestaShop's DECIMAL(20,6) columns already arrive as such text; the string
 * arithmetic keeps PHP 7.4 and 8.5 (and bcmath or not) byte-identical.
 */
final class Decimal
{
    private const SCALE = 6;

    private function __construct()
    {
    }

    /** A database decimal as a protocol amount with 6 decimals; text that is not a number is 0. */
    public static function money(?string $value): string
    {
        $parts = self::parts($value);
        if ($parts === null) {
            return '0.000000';
        }
        [$negative, $int, $fraction] = $parts;
        $text = $int.'.'.str_pad($fraction, self::SCALE, '0');

        return $negative && trim($int.$fraction, '0') !== '' ? '-'.$text : $text;
    }

    /**
     * The canonical form the platform hashes (its `CanonicalPayload::decimal()`): no trailing zeros, at
     * most 6 decimals (cut, never rounded), `"-0"` is `"0"`.
     */
    public static function canonical(string $value): string
    {
        $money = self::money($value);
        $trimmed = rtrim(rtrim($money, '0'), '.');

        return $trimmed === '-0' || $trimmed === '' ? '0' : $trimmed;
    }

    /** $a + $b as a protocol amount (amounts below 9 223 372 036 854 in absolute value). */
    public static function add(string $a, string $b): string
    {
        $sum = self::micros(self::money($a)) + self::micros(self::money($b));
        $negative = $sum < 0;
        $abs = (string) abs($sum);
        $abs = str_pad($abs, self::SCALE + 1, '0', STR_PAD_LEFT);
        $text = substr($abs, 0, -self::SCALE).'.'.substr($abs, -self::SCALE);

        return ($negative ? '-' : '').$text;
    }

    /** A quotient the database cannot give exactly (1.7.8's unit price), rounded half up to 6 decimals. */
    public static function divide(string $a, string $b): string
    {
        $divisor = (float) self::money($b);
        if ($divisor <= 0.0) {
            return '0.000000';
        }

        return self::money(sprintf('%.6F', (float) self::money($a) / $divisor));
    }

    private static function micros(string $money): int
    {
        $negative = $money[0] === '-';
        [$int, $fraction] = explode('.', ltrim($money, '-'));
        $micros = (int) $int * 1000000 + (int) $fraction;

        return $negative ? -$micros : $micros;
    }

    /**
     * @return array{0: bool, 1: string, 2: string}|null sign, integer digits without leading zeros, at most 6 decimals
     */
    private static function parts(?string $value): ?array
    {
        if ($value === null || preg_match('/\A\s*(-|\+)?([0-9]*)(?:\.([0-9]*))?\s*\z/', $value, $m) !== 1 || ($m[2] === '' && ($m[3] ?? '') === '')) {
            return null;
        }
        $int = ltrim($m[2], '0');

        return [$m[1] === '-', $int === '' ? '0' : $int, substr($m[3] ?? '', 0, self::SCALE)];
    }
}
