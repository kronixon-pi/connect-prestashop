<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Support;

use Kronixon\Connect\Api\Budget;
use Kronixon\Connect\Write\Item;
use Kronixon\Connect\Write\Warnings;
use Kronixon\Connect\Write\Writer;

/**
 * A writer that does what its closure says and records every step.
 */
final class FakeWriter implements Writer
{
    /** @var list<string> */
    public $steps = [];

    /** @var callable(Item, Warnings): array<string, mixed> */
    private $write;

    /** @var Warnings */
    private $warnings;

    /** @var (callable(Item): bool)|null the clean-up of an item */
    public $undo;

    /**
     * @param  callable(Item, Warnings): array<string, mixed>  $write
     */
    public function __construct(callable $write, Warnings $warnings)
    {
        $this->write = $write;
        $this->warnings = $warnings;
    }

    public function start(Budget $budget): void
    {
        $this->steps[] = 'start';
    }

    public function seal(Item $item): void
    {
        $this->steps[] = 'seal '.$item->key();
    }

    public function prepare(Item $item): void
    {
        $this->steps[] = 'prepare '.$item->key();
    }

    public function write(Item $item): array
    {
        $this->steps[] = 'write '.$item->key();

        return ($this->write)($item, $this->warnings);
    }

    public function committed(Item $item): void
    {
        $this->steps[] = 'committed '.$item->key();
    }

    public function rolledBack(Item $item): bool
    {
        $this->steps[] = 'rolledBack '.$item->key();

        return $this->undo === null || ($this->undo)($item);
    }

    public function skipped(Item $item): void
    {
        $this->steps[] = 'skipped '.$item->key();
    }

    public function finish(): void
    {
        $this->steps[] = 'finish';
    }

    public function hashes(array $results): array
    {
        $this->steps[] = 'hashes';

        return array_fill_keys(array_map('strval', array_column($results, 'key')), str_repeat('a', 64));
    }
}
