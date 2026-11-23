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

namespace Kronixon\Connect\Auth;

/**
 * The answers of write requests that carried an `Idempotency-Key` (README §3), kept 24 hours. The
 * Kernel reads and writes it under the write lock, so one key is never processed twice at once.
 */
interface IdempotencyStore
{
    /**
     * @return array{sha256: string, status: int, body: string}|null
     */
    public function find(string $key, int $now): ?array;

    public function save(string $key, string $sha256, int $status, string $body, int $now): void;
}
