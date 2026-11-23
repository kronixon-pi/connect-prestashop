<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Support;

use Kronixon\Connect\Update\Files;
use RuntimeException;

/**
 * A file system in memory for Update\Swap: folders, files with their permission bits, folders PHP may not write,
 * a disk that fills up, renames that fail, and a process killed after a number of writes (a RuntimeException that
 * nothing in the module catches, like a SIGKILL nothing survives).
 */
final class MemoryFiles implements Files
{
    /** @var array<string, array{dir: bool, contents: string, mode: int}> */
    public $nodes = [];

    /** @var list<string> folders PHP may not write to */
    public $readOnly = [];

    /** @var float|null */
    public $free;

    /** @var int|null writes that succeed before the disk is full */
    public $writesUntilFull;

    /** @var int|null writes before the process is killed */
    public $writesUntilKill;

    /** @var list<string> `from>to` renames that fail */
    public $failRenames = [];

    /** @var list<string> */
    public $forgotten = [];

    /** @var bool OPcache lets new files run (Files::canForget()) */
    public $forgettable = true;

    /**
     * @param  array<string, string>  $files  relative path => contents
     */
    public function seed(string $dir, array $files, int $fileMode = 0644, int $dirMode = 0755): void
    {
        $this->nodes[$dir] = ['dir' => true, 'contents' => '', 'mode' => $dirMode];
        foreach ($files as $path => $contents) {
            $parent = $dir;
            foreach (array_slice(explode('/', $path), 0, -1) as $segment) {
                $parent .= '/'.$segment;
                $this->nodes[$parent] = ['dir' => true, 'contents' => '', 'mode' => $dirMode];
            }
            $this->nodes[$dir.'/'.$path] = ['dir' => false, 'contents' => $contents, 'mode' => $fileMode];
        }
    }

    /**
     * @return array<string, string> relative path => contents of the files below $dir
     */
    public function tree(string $dir): array
    {
        $files = [];
        foreach ($this->nodes as $path => $node) {
            if (! $node['dir'] && strpos($path, $dir.'/') === 0) {
                $files[substr($path, strlen($dir) + 1)] = $node['contents'];
            }
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    public function exists(string $path): bool
    {
        return isset($this->nodes[$path]);
    }

    public function writableTree(string $path): bool
    {
        if (! isset($this->nodes[$path]) || ! $this->nodes[$path]['dir']) {
            return false;
        }
        foreach ($this->readOnly as $dir) {
            if ($dir === $path || strpos($dir, $path.'/') === 0) {
                return false;
            }
        }

        return true;
    }

    public function mkdir(string $path, int $mode): bool
    {
        if (! $this->writable(dirname($path)) || ! isset($this->nodes[dirname($path)])) {
            return false;
        }
        $this->nodes[$path] = $this->nodes[$path] ?? ['dir' => true, 'contents' => '', 'mode' => $mode];

        return $this->nodes[$path]['dir'];
    }

    public function write(string $path, string $contents, int $mode): bool
    {
        if ($this->writesUntilKill !== null && $this->writesUntilKill-- <= 0) {
            throw new RuntimeException('killed');
        }
        if (! $this->writable(dirname($path)) || ! isset($this->nodes[dirname($path)])) {
            return false;
        }
        if ($this->writesUntilFull !== null && $this->writesUntilFull-- <= 0) {
            // A full disk leaves a cut file behind.
            $this->nodes[$path] = ['dir' => false, 'contents' => substr($contents, 0, 1), 'mode' => $mode];

            return false;
        }
        $this->nodes[$path] = ['dir' => false, 'contents' => $contents, 'mode' => $mode];

        return true;
    }

    public function read(string $path): ?string
    {
        return isset($this->nodes[$path]) && ! $this->nodes[$path]['dir'] ? $this->nodes[$path]['contents'] : null;
    }

    public function rename(string $from, string $to): bool
    {
        if (! isset($this->nodes[$from]) || isset($this->nodes[$to]) || in_array($from.'>'.$to, $this->failRenames, true) || ! $this->writable(dirname($from))) {
            return false;
        }
        $moved = [];
        foreach ($this->nodes as $path => $node) {
            if ($path === $from || strpos($path, $from.'/') === 0) {
                $moved[$to.substr($path, strlen($from))] = $node;
                unset($this->nodes[$path]);
            }
        }
        $this->nodes += $moved;

        return true;
    }

    public function remove(string $path): bool
    {
        foreach (array_keys($this->nodes) as $node) {
            if ($node === $path || strpos($node, $path.'/') === 0) {
                if (! $this->writable(dirname($node))) {
                    return false;
                }
                unset($this->nodes[$node]);
            }
        }

        return true;
    }

    public function freeBytes(string $path): ?float
    {
        return $this->free;
    }

    public function mode(string $path): ?int
    {
        return isset($this->nodes[$path]) ? $this->nodes[$path]['mode'] : null;
    }

    public function canForget(string $path): bool
    {
        return $this->forgettable;
    }

    public function forget(string $path): void
    {
        $this->forgotten[] = $path;
    }

    private function writable(string $dir): bool
    {
        return ! in_array($dir, $this->readOnly, true);
    }
}
