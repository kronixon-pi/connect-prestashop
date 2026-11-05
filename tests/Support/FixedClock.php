<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Support;

use Kronixon\Connect\Support\Clock;

final class FixedClock implements Clock
{
    /** @var float */
    private $now;

    public function __construct(float $now)
    {
        $this->now = $now;
    }

    public function now(): float
    {
        return $this->now;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }
}
