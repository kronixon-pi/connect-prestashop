<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Support;

use Kronixon\Connect\Support\Settings;

final class MemorySettings implements Settings
{
    /** @var array<string, string> */
    public $values = [];

    public function get(string $key): ?string
    {
        $value = $this->values[$key] ?? null;

        return $value === null || $value === '' ? null : $value;
    }

    public function set(string $key, string $value): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }
}
