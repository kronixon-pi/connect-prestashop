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

use Kronixon\Connect\Protocol;

/**
 * Replaces the module folder without ever leaving a half-copied one (Spec §10 item 4). Everything happens next to it,
 * inside the modules folder (one file system, so a rename is atomic; PrestaShop skips folders whose name starts with
 * a dot):
 *
 * 1. stage: the new files are written to `.kronixonconnect-next`; a stage a killed process left is removed first.
 *    The folder in use is not touched, so a kill here leaves the old version running and the next attempt starts over.
 * 2. swap: `kronixonconnect` → `.kronixonconnect-prev`, then `.kronixonconnect-next` → `kronixonconnect`: two renames,
 *    the second undone when it fails.
 * 3. commit removes `-prev`; rollback puts it back (the new files go to `.kronixonconnect-failed` and are removed).
 *
 * New files and folders get the permission bits of the module's own (`kronixonconnect.php`, the folder), and belong
 * to the user PHP runs as, as after an upload in the Module Manager.
 */
final class Swap
{
    /** Room on the disk beyond the new files: the old folder stays until the commit. */
    private const SPARE_BYTES = 5242880;

    /** @var Files */
    private $files;

    /** @var string */
    private $dir;

    public function __construct(Files $files, string $modulesDir)
    {
        $this->files = $files;
        $this->dir = rtrim($modulesDir, '/').'/';
    }

    public function live(): string
    {
        return $this->dir.Protocol::MODULE_NAME;
    }

    public function next(): string
    {
        return $this->dir.'.'.Protocol::MODULE_NAME.'-next';
    }

    public function prev(): string
    {
        return $this->dir.'.'.Protocol::MODULE_NAME.'-prev';
    }

    public function failed(): string
    {
        return $this->dir.'.'.Protocol::MODULE_NAME.'-failed';
    }

    /**
     * Whether this server lets the module replace its folder: the folder and every folder in it writable (so the old
     * one can be removed after the swap), room for the new files, and an OPcache that will run them.
     *
     * @throws UpdateError `server`
     */
    public function preflight(int $bytes): void
    {
        if (! $this->files->writableTree($this->live())) {
            throw UpdateError::server('read_only', $this->live());
        }
        if (! $this->files->canForget($this->live())) {
            throw UpdateError::server('opcache_static', 'opcache.validate_timestamps=0 and opcache_invalidate() not allowed');
        }
        $free = $this->files->freeBytes($this->dir);
        if ($free !== null && $free < $bytes + self::SPARE_BYTES) {
            throw UpdateError::server('disk_space', sprintf('%d MB free', (int) floor($free / 1048576)));
        }
    }

    /**
     * Writes the new module folder next to the one in use.
     *
     * @param  array<string, string>  $files  relative path => contents (Package::open())
     *
     * @throws UpdateError `server`
     */
    public function stage(array $files): void
    {
        $dirMode = ($this->files->mode($this->live()) ?? 0755) | 0700;
        $fileMode = ($this->files->mode($this->live().'/'.Protocol::MODULE_NAME.'.php') ?? 0644) | 0600;
        $next = $this->next();
        if (! $this->files->remove($next)) {
            throw UpdateError::server('read_only', $next);
        }
        if (! $this->files->mkdir($next, $dirMode)) {
            throw UpdateError::server('read_only', $this->dir);
        }
        ksort($files, SORT_STRING);
        $made = [];
        foreach ($files as $path => $contents) {
            $parent = '';
            foreach (array_slice(explode('/', $path), 0, -1) as $segment) {
                $parent .= '/'.$segment;
                if (! isset($made[$parent]) && ! $this->files->mkdir($next.$parent, $dirMode)) {
                    $this->files->remove($next);
                    throw UpdateError::server('write_failed', $parent);
                }
                $made[$parent] = true;
            }
            if (! $this->files->write($next.'/'.$path, $contents, $fileMode)) {
                $this->files->remove($next);
                throw UpdateError::server('write_failed', $path);
            }
        }
    }

    /**
     * The staged folder becomes the module's; the old one waits as `-prev` for commit() or rollback().
     *
     * @throws UpdateError `server`
     */
    public function swap(): void
    {
        if (! $this->files->remove($this->prev()) || ! $this->files->remove($this->failed())) {
            throw UpdateError::server('read_only', $this->prev());
        }
        if (! $this->files->rename($this->live(), $this->prev())) {
            $this->files->remove($this->next());
            throw UpdateError::server('read_only', 'rename');
        }
        if (! $this->files->rename($this->next(), $this->live())) {
            $this->files->rename($this->prev(), $this->live());
            $this->files->remove($this->next());
            throw UpdateError::server('swap_failed');
        }
        $this->files->forget($this->live());
    }

    /** The previous folder is back in place; false when there is none or a rename failed. */
    public function rollback(): bool
    {
        if (! $this->files->exists($this->prev()) || ! $this->files->remove($this->failed())) {
            return false;
        }
        if (! $this->files->rename($this->live(), $this->failed())) {
            return false;
        }
        if (! $this->files->rename($this->prev(), $this->live())) {
            $this->files->rename($this->failed(), $this->live());

            return false;
        }
        $this->files->forget($this->live());
        $this->files->remove($this->failed());

        return true;
    }

    /**
     * The version of the files in the module folder now (the line of `src/Protocol.php` Package::open() requires),
     * whatever the classes this request loaded say; null when it cannot be read.
     */
    public function liveVersion(): ?string
    {
        $source = $this->files->read($this->live().'/src/Protocol.php');

        return $source !== null && preg_match("/^    public const MODULE_VERSION = '([^'\\n]+)';\$/m", $source, $match) === 1 ? $match[1] : null;
    }

    /** The old folder waits next to the new one: a swap happened and is neither committed nor rolled back. */
    public function swapped(): bool
    {
        return $this->files->exists($this->prev());
    }

    /** Removes the previous folder; false leaves it for the next swap to remove. */
    public function commit(): bool
    {
        return $this->files->remove($this->prev());
    }

    /** Removes a staged folder no swap will use. */
    public function discard(): bool
    {
        return $this->files->remove($this->next());
    }
}
