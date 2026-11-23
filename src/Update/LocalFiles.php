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

use FilesystemIterator;
use Kronixon\Connect\Support\Runtime;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Files of this server, through PHP's own functions only (no shell). A warning PHP would print (open_basedir, a
 * permission) becomes a false answer instead: the caller explains it.
 */
final class LocalFiles implements Files
{
    public function exists(string $path): bool
    {
        return @file_exists($path) || @is_link($path);
    }

    public function writableTree(string $path): bool
    {
        if (! @is_dir($path) || ! @is_writable($path)) {
            return false;
        }
        try {
            foreach (self::walk($path) as $entry) {
                if ($entry->isLink() || ($entry->isDir() && ! $entry->isWritable())) {
                    return false;
                }
            }
        } catch (Throwable $e) {
            return false;
        }

        return true;
    }

    public function mkdir(string $path, int $mode): bool
    {
        return (@is_dir($path) || @mkdir($path, $mode)) && @chmod($path, $mode);
    }

    public function write(string $path, string $contents, int $mode): bool
    {
        return @file_put_contents($path, $contents) === strlen($contents) && @chmod($path, $mode);
    }

    public function read(string $path): ?string
    {
        $contents = @is_file($path) ? @file_get_contents($path) : false;

        return is_string($contents) ? $contents : null;
    }

    public function rename(string $from, string $to): bool
    {
        return ! $this->exists($to) && @rename($from, $to);
    }

    public function remove(string $path): bool
    {
        if (@is_link($path) || @is_file($path)) {
            return @unlink($path);
        }
        if (! @is_dir($path)) {
            return ! $this->exists($path);
        }
        try {
            foreach (self::walk($path, RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                if ($entry->isDir() && ! $entry->isLink()) {
                    @rmdir($entry->getPathname());
                } else {
                    @unlink($entry->getPathname());
                }
            }
        } catch (Throwable $e) {
            // what is left is reported below
        }

        return @rmdir($path) || ! $this->exists($path);
    }

    public function freeBytes(string $path): ?float
    {
        $free = Runtime::canCall('disk_free_space') ? @disk_free_space($path) : false;

        return is_float($free) ? $free : null;
    }

    public function mode(string $path): ?int
    {
        $perms = @fileperms($path);

        return is_int($perms) ? $perms & 0777 : null;
    }

    public function canForget(string $path): bool
    {
        $on = static function (string $setting): bool {
            return filter_var(ini_get($setting), FILTER_VALIDATE_BOOLEAN);
        };
        // Without OPcache, or with OPcache comparing modification times, the new files are seen (within revalidate_freq).
        if (! $on('opcache.enable') || $on('opcache.validate_timestamps')) {
            return true;
        }
        $restricted = (string) ini_get('opcache.restrict_api');

        // Otherwise only opcache_invalidate() can; restrict_api allows it to scripts below a path (the shop's index.php).
        return Runtime::canCall('opcache_invalidate')
            && ($restricted === '' || strpos((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''), $restricted) === 0);
    }

    public function forget(string $path): void
    {
        if (! Runtime::canCall('opcache_invalidate') || ! @is_dir($path)) {
            return;
        }
        try {
            foreach (self::walk($path) as $entry) {
                if ($entry->isFile() && substr($entry->getFilename(), -4) === '.php') {
                    @opcache_invalidate($entry->getPathname(), true);
                }
            }
        } catch (Throwable $e) {
            // OPcache with validate_timestamps still sees the new modification times
        }
    }

    /**
     * @param  0|1|2  $mode  a RecursiveIteratorIterator mode
     * @return iterable<SplFileInfo>
     */
    private static function walk(string $path, int $mode = RecursiveIteratorIterator::SELF_FIRST): iterable
    {
        /** @var iterable<SplFileInfo> */
        return new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), $mode);
    }
}
