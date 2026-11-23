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

/**
 * The sha256 of the file a stored picture was converted from, inside the picture itself (a JPEG comment, a
 * PNG text chunk; PrestaShop keeps either under `.jpg`): it is gone as soon as the shop replaces it.
 */
final class ImageNote
{
    private const LABEL = 'kx-sha256';

    private const PNG = "\x89PNG\r\n\x1A\n";

    private function __construct()
    {
    }

    public static function read(string $file): ?string
    {
        $bytes = (string) file_get_contents($file, false, null, 0, 65536);
        foreach (array_merge(self::segments($bytes), self::chunks($bytes)) as [$type, , $data]) {
            if (in_array($type, ["\xFE", 'tEXt'], true) && preg_match('/\A'.self::LABEL.'\x00([0-9a-f]{64})\z/', $data, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /** True also for a file that is neither: it just has no note. */
    public static function write(string $file, string $sha256): bool
    {
        $bytes = (string) file_get_contents($file);
        $note = self::LABEL."\x00".$sha256;
        $segments = self::segments($bytes);
        $chunks = self::chunks($bytes);
        if (isset($chunks[0]) && $chunks[0][0] === 'IHDR') {
            [$at, $note] = [$chunks[0][1], pack('N', strlen($note)).'tEXt'.$note.pack('N', crc32('tEXt'.$note))];
        } elseif (strncmp($bytes, "\xFF\xD8", 2) === 0) {
            // JFIF's own segment must come first.
            [$at, $note] = [isset($segments[0]) && $segments[0][0] === "\xE0" ? $segments[0][1] : 2, "\xFF\xFE".pack('n', strlen($note) + 2).$note];
        } else {
            return true;
        }

        return file_put_contents($file, substr($bytes, 0, $at).$note.substr($bytes, $at), LOCK_EX) !== false;
    }

    /**
     * Of a JPEG, up to the picture.
     *
     * @return list<array{0: string, 1: int, 2: string}> type, offset of its end, data
     */
    private static function segments(string $bytes): array
    {
        $segments = [];
        $at = strncmp($bytes, "\xFF\xD8", 2) === 0 ? 2 : strlen($bytes);
        while ($at + 4 <= strlen($bytes) && $bytes[$at] === "\xFF" && $bytes[$at + 1] !== "\xDA") {
            $length = ord($bytes[$at + 2]) * 256 + ord($bytes[$at + 3]);
            if ($length < 2) {
                break;
            }
            $segments[] = [$bytes[$at + 1], $at + 2 + $length, substr($bytes, $at + 4, $length - 2)];
            $at += 2 + $length;
        }

        return $segments;
    }

    /**
     * Of a PNG, up to the picture.
     *
     * @return list<array{0: string, 1: int, 2: string}> type, offset of its end, data
     */
    private static function chunks(string $bytes): array
    {
        $chunks = [];
        $at = strncmp($bytes, self::PNG, 8) === 0 ? 8 : strlen($bytes);
        while ($at + 12 <= strlen($bytes) && substr($bytes, $at + 4, 4) !== 'IDAT') {
            $length = (int) (unpack('N', substr($bytes, $at, 4)) ?: [1 => 0])[1];
            $chunks[] = [substr($bytes, $at + 4, 4), $at + 12 + $length, substr($bytes, $at + 8, $length)];
            $at += 12 + $length;
        }

        return $chunks;
    }
}
