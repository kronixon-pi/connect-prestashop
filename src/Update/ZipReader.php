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

namespace Kronixon\Connect\Update;

/**
 * Reads a release zip as scripts/release/ReleaseBuilder.php writes it — regular files, stored (no compression), no
 * flags, no extra fields, no comments — in plain PHP, so no ZipArchive is needed and no link, directory entry,
 * encrypted or compressed entry can come through. Anything else is refused. The bytes were checked against their
 * signature before; this only takes them apart.
 */
final class ZipReader
{
    public const MAX_ENTRIES = 5000;

    private const EOCD = "PK\x05\x06";

    private const CENTRAL = "PK\x01\x02";

    private const LOCAL = "PK\x03\x04";

    /**
     * @return array<string, string> entry name => contents, in the zip's order
     *
     * @throws UpdateError `refused` / `bad_package`
     */
    public static function entries(string $zip): array
    {
        $length = strlen($zip);
        if ($length < 22 || substr($zip, -22, 4) !== self::EOCD) {
            throw UpdateError::refused('bad_package', 'end of central directory');
        }
        /** @var array{disk: int, cdDisk: int, onDisk: int, count: int, size: int, offset: int, comment: int} $end */
        $end = unpack('vdisk/vcdDisk/vonDisk/vcount/Vsize/Voffset/vcomment', substr($zip, -18));
        if ($end['disk'] !== 0 || $end['cdDisk'] !== 0 || $end['onDisk'] !== $end['count'] || $end['comment'] !== 0
            || $end['count'] < 1 || $end['count'] > self::MAX_ENTRIES || $end['offset'] + $end['size'] !== $length - 22) {
            throw UpdateError::refused('bad_package', 'central directory');
        }
        $entries = [];
        $at = $end['offset'];
        $dataEnd = 0;
        for ($i = 0; $i < $end['count']; $i++) {
            if (substr($zip, $at, 4) !== self::CENTRAL || $at + 46 > $length - 22) {
                throw UpdateError::refused('bad_package', 'central entry '.$i);
            }
            /** @var array{made: int, needed: int, flags: int, method: int, time: int, date: int, crc: int, csize: int, usize: int, name: int, extra: int, comment: int, disk: int, internal: int, external: int, local: int} $entry */
            $entry = unpack('vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Vlocal', substr($zip, $at + 4, 42));
            $name = substr($zip, $at + 46, $entry['name']);
            $type = ($entry['external'] >> 16) & 0170000;
            if ($entry['flags'] !== 0 || $entry['method'] !== 0 || $entry['csize'] !== $entry['usize'] || $entry['extra'] !== 0
                || $entry['comment'] !== 0 || $entry['disk'] !== 0 || ($type !== 0 && $type !== 0100000) || isset($entries[$name])) {
                throw UpdateError::refused('bad_package', 'entry '.$i);
            }
            $data = $entry['local'] + 30 + $entry['name'];
            $local = substr($zip, $entry['local'], 30);
            if (substr($local, 0, 4) !== self::LOCAL || substr($zip, $entry['local'] + 30, $entry['name']) !== $name
                || substr($local, 4, 26) !== pack('vvvvvVVVvv', $entry['needed'], 0, 0, $entry['time'], $entry['date'], $entry['crc'], $entry['csize'], $entry['usize'], $entry['name'], 0)
                || $entry['local'] < $dataEnd || $data + $entry['usize'] > $end['offset']) {
                throw UpdateError::refused('bad_package', 'local entry '.$i);
            }
            $contents = substr($zip, $data, $entry['usize']);
            if ((crc32($contents) & 0xFFFFFFFF) !== $entry['crc']) {
                throw UpdateError::refused('bad_package', 'crc of entry '.$i);
            }
            $entries[$name] = $contents;
            $dataEnd = $data + $entry['usize'];
            $at += 46 + $entry['name'];
        }

        return $entries;
    }
}
