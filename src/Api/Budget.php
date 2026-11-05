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

use Kronixon\Connect\Protocol;
use Kronixon\Connect\Support\Clock;

/**
 * The request time budget (Spec §11): writers check remaining() before each item and answer
 * `continue: true` with the rest `skipped` when less than RESERVE_S is left.
 */
final class Budget
{
    public const RESERVE_S = 1.5;

    /** @var Clock */
    private $clock;

    /** @var float */
    private $startedAt;

    /** @var float */
    private $deadline;

    public function __construct(Clock $clock, int $seconds = Protocol::TIME_BUDGET_S)
    {
        $seconds = max(1, min(Protocol::MAX_TIME_BUDGET_S, $seconds));
        $this->clock = $clock;
        $this->startedAt = $clock->now();
        $this->deadline = $this->startedAt + $seconds;
    }

    public function remaining(): float
    {
        return max(0.0, $this->deadline - $this->clock->now());
    }

    /** True when the next item must not start (less than RESERVE_S left). */
    public function exhausted(): bool
    {
        return $this->remaining() < self::RESERVE_S;
    }

    public function seconds(): int
    {
        return (int) round($this->deadline - $this->startedAt);
    }

    public function elapsedMs(): int
    {
        return (int) round(($this->clock->now() - $this->startedAt) * 1000);
    }
}
