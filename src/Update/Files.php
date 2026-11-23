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
 * The file operations of an update (Swap): LocalFiles in the shop, a fake in the tests. Paths are absolute; no
 * method throws, each answers whether it worked.
 */
interface Files
{
    public function exists(string $path): bool;

    /** $path is a directory PHP may create entries in, and so is every directory below it. */
    public function writableTree(string $path): bool;

    public function mkdir(string $path, int $mode): bool;

    /** Writes the whole file or reports failure (a full disk). */
    public function write(string $path, string $contents, int $mode): bool;

    public function read(string $path): ?string;

    /** rename(2): atomic within one file system; refuses to replace an existing target. */
    public function rename(string $from, string $to): bool;

    /** Deletes a file or a directory with everything in it; true when nothing is left. */
    public function remove(string $path): bool;

    /** Free bytes on the file system of $path, null when PHP may not ask (disk_free_space() disabled). */
    public function freeBytes(string $path): ?float;

    /** The permission bits of $path (0755), null when it does not exist. */
    public function mode(string $path): ?int;

    /**
     * Whether the next requests will run new files put at $path: false when OPcache never compares a script with its
     * file (validate_timestamps off) and PHP may not call opcache_invalidate() (disabled, or restrict_api elsewhere).
     */
    public function canForget(string $path): bool;

    /** Drops the compiled scripts of a directory from OPcache, so the next request compiles the new files. */
    public function forget(string $path): void;
}
