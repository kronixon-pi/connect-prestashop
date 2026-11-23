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

/**
 * One `POST <entities>` endpoint for one request (Batch calls it).
 */
interface Writer
{
    /**
     * Work of the item that must not hold row locks (a download): before its transaction.
     *
     * @throws ItemError
     */
    public function prepare(Item $item): void;

    /**
     * Writes the item inside its transaction. The answer is the item's result without `key` and
     * `status`: `external_id`, `action` and the endpoint's own members.
     *
     * @return array<string, mixed>
     *
     * @throws ItemError the item is refused and rolled back
     */
    public function write(Item $item): array;

    /** The item is committed (files, the search index). */
    public function committed(Item $item): void;

    /** The item was rolled back: nothing it created or cached exists. */
    public function rolledBack(Item $item): void;

    /** Once after the last item, when any was written (the category tree, caches). */
    public function finish(): void;

    /**
     * The `content_hash` of the batch's entities as the shop holds them now.
     *
     * @param  list<array<string, mixed>>  $results  the `ok` results
     * @return array<string, string> item key => hash
     */
    public function hashes(array $results): array;
}
