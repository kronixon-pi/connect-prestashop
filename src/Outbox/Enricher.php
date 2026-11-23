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

namespace Kronixon\Connect\Outbox;

/**
 * What an event carries that the hook did not read: the entity as it is when the event is sent.
 */
interface Enricher
{
    /**
     * The `content_hash` of GET catalog/products for these products; a product that is gone is absent.
     *
     * @param  list<int>  $productIds
     * @return array<int, string>
     */
    public function contentHashes(array $productIds): array;

    /**
     * The `price` of GET catalog/prices: `product:variant` => money; a variant that is gone is absent.
     *
     * @param  list<int>  $productIds
     * @return array<string, string>
     */
    public function prices(array $productIds): array;

    /**
     * The catalog's UUIDs of linked entities (`kx_entity_map`).
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    public function kxIds(string $entityType, array $ids): array;
}
