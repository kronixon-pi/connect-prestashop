<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Support;

use Kronixon\Connect\Support\Settings;

/**
 * One request's view of a shared store, as PrestaShop's `Configuration` gives it: get() answers from the copy taken
 * when the request started (plus the request's own writes), fresh() reads the store, set() and delete() write both.
 */
final class RequestSettings implements Settings
{
    /** @var MemorySettings */
    private $store;

    /** @var array<string, string> */
    private $copy;

    public function __construct(MemorySettings $store)
    {
        $this->store = $store;
        $this->copy = $store->values;
    }

    public function get(string $key): ?string
    {
        $value = $this->copy[$key] ?? null;

        return $value === null || $value === '' ? null : $value;
    }

    public function fresh(string $key): ?string
    {
        return $this->store->get($key);
    }

    public function set(string $key, string $value): bool
    {
        $this->copy[$key] = $value;

        return $this->store->set($key, $value);
    }

    public function delete(string $key): bool
    {
        unset($this->copy[$key]);

        return $this->store->delete($key);
    }
}
