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

use Kronixon\Connect\Api\Budget;
use Kronixon\Connect\Api\ReadCleanup;

/**
 * Before a read (Kernel): when the paired shop has a mark, at most PendingWrites::PER_READ objects a dead
 * write left go, under the write lock and with the hooks of a write; without a mark one lookup and no
 * lock.
 */
final class ReadRecovery implements ReadCleanup
{
    /** @var PendingWrites */
    private $pending;

    /** @var callable(): void what makes the hooks run as in a write */
    private $hooks;

    /**
     * @param  callable(): void  $hooks
     */
    public function __construct(PendingWrites $pending, callable $hooks)
    {
        $this->pending = $pending;
        $this->hooks = $hooks;
    }

    public function needed(): bool
    {
        return $this->pending->any();
    }

    public function run(Budget $budget): void
    {
        ($this->hooks)();
        $this->pending->recover($budget, PendingWrites::PER_READ);
    }
}
