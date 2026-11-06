<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Support;

use Kronixon\Connect\Auth\NonceStore;
use Kronixon\Connect\Auth\WriteLock;
use Kronixon\Connect\Protocol;

/** The nonce store and write lock of DbGuards, in memory (the same TTL rule). */
final class MemoryGuards implements NonceStore, WriteLock
{
    /** @var array<string, int> nonce => seen at */
    public $nonces = [];

    /** @var bool another request holds the write lock */
    public $heldElsewhere = false;

    /** @var list<string> */
    public $lockLog = [];

    public function claim(string $nonce, int $now): bool
    {
        foreach ($this->nonces as $key => $seen) {
            if ($seen < $now - Protocol::NONCE_TTL_S) {
                unset($this->nonces[$key]);
            }
        }
        $key = strtolower($nonce);
        if (isset($this->nonces[$key])) {
            return false;
        }
        $this->nonces[$key] = $now;

        return true;
    }

    public function countSince(int $since): int
    {
        return count(array_filter($this->nonces, static function (int $seen) use ($since): bool {
            return $seen >= $since;
        }));
    }

    public function acquire(): bool
    {
        $this->lockLog[] = $this->heldElsewhere ? 'busy' : 'acquire';

        return ! $this->heldElsewhere;
    }

    public function release(): void
    {
        $this->lockLog[] = 'release';
    }
}
