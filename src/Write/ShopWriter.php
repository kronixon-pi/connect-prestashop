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
use Kronixon\Connect\Catalog\Reader;

/**
 * The steps most writers have nothing to do in, and the hashes of one reader.
 */
abstract class ShopWriter implements Writer
{
    /** @var Shop */
    protected $shop;

    public function __construct(Shop $shop)
    {
        $this->shop = $shop;
    }

    public function start(Budget $budget): void
    {
        $this->shop->pending->recover($budget);
    }

    public function prepare(Item $item): void
    {
        $this->shop->pending->item($item);
    }

    public function seal(Item $item): void
    {
        $this->shop->pending->seal();
    }

    public function committed(Item $item): void
    {
        $this->shop->pending->keep();
    }

    public function rolledBack(Item $item): bool
    {
        return $this->shop->undo();
    }

    public function finish(): void
    {
    }

    public function hashes(array $results): array
    {
        return $this->hashesOf($results);
    }

    /**
     * The hashes of the results (of one `kind`) by item key.
     *
     * @param  list<array<string, mixed>>  $results
     * @param  array<string, string>  $params  the reader's own parameters
     * @param  string|null  $readKind  only items of this kind of a read that mixes kinds
     * @return array<string, string>
     */
    protected function hashesOf(array $results, ?string $kind = null, array $params = [], ?string $readKind = null): array
    {
        $ids = [];
        foreach ($results as $result) {
            if ($kind === null || $result['kind'] === $kind) {
                $ids[(string) $result['key']] = (int) $result['external_id'];
            }
        }
        $hashes = ItemHashes::read($this->reader(), array_values($ids), $params, $readKind);
        $byKey = [];
        foreach ($ids as $key => $id) {
            if (isset($hashes[(string) $id])) {
                $byKey[(string) $key] = $hashes[(string) $id];
            }
        }

        return $byKey;
    }

    abstract protected function reader(): Reader;

    /**
     * An item result: `created` when there was no id, else `updated` or `unchanged`.
     *
     * @return array<string, mixed>
     */
    protected static function done(int $id, bool $created, bool $changed): array
    {
        return ['external_id' => (string) $id, 'action' => $created ? 'created' : ($changed ? 'updated' : 'unchanged')];
    }

    /** Whether `match_by` of the item names $rule. */
    protected static function matches(Item $item, string $rule): bool
    {
        return in_array($rule, $item->strings('match_by') ?? [], true);
    }
}
