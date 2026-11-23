<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Support;

use Kronixon\Connect\Auth\IdempotencyStore;
use Kronixon\Connect\Protocol;
use RuntimeException;

/**
 * The answers of writes with an Idempotency-Key, in memory.
 */
final class MemoryIdempotency implements IdempotencyStore
{
    /** @var array<string, array{sha256: string, status: int, body: string, at: int}> */
    public $answers = [];

    /** @var bool */
    public $broken = false;

    public function find(string $key, int $now): ?array
    {
        $answer = $this->answers[$key] ?? null;

        return $answer === null || $answer['at'] < $now - Protocol::IDEMPOTENCY_TTL_S ? null : ['sha256' => $answer['sha256'], 'status' => $answer['status'], 'body' => $answer['body']];
    }

    public function save(string $key, string $sha256, int $status, string $body, int $now): void
    {
        if ($this->broken) {
            throw new RuntimeException('no table');
        }
        $this->answers[$key] = ['sha256' => $sha256, 'status' => $status, 'body' => $body, 'at' => $now];
    }
}
