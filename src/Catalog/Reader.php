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

namespace Kronixon\Connect\Catalog;

use Kronixon\Connect\Api\ApiError;
use Kronixon\Connect\Api\Request;

/**
 * One `GET catalog/<kind>` endpoint.
 */
interface Reader
{
    /** The largest `limit` (500, or 1 000 for prices and stock). */
    public function maxLimit(): int;

    /** `meta.max_id`: the highest id of the entity in the shop, `"0"` when there is none. */
    public function maxId(): string;

    /**
     * The largest `v` of this endpoint's cursor for $request (rows keyed by an id and a second key);
     * null when its cursor is `{"id":N}` only, so that a cursor with `v` is a bad request, listed with
     * the request's other invalid paging members.
     */
    public function maxCursorVariant(Request $request): ?int;

    /**
     * @throws ApiError 400 for an invalid parameter of the endpoint
     */
    public function read(PageQuery $query): Page;
}
