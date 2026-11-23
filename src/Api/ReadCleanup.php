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

namespace Kronixon\Connect\Api;

/**
 * What the Kernel does before a GET about objects a dead write left half-written (PendingWrites): it
 * asks needed() without the write lock, and only then takes the lock for run(), so a read of a clean
 * shop never makes a write wait or answer `409 busy`.
 */
interface ReadCleanup
{
    /** Whether something is to be cleaned up: one indexed lookup, no lock. */
    public function needed(): bool;

    /** The clean-up, under the write lock. */
    public function run(Budget $budget): void;
}
