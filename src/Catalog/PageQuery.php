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

use DateTimeImmutable;
use DateTimeZone;
use Kronixon\Connect\Api\ApiError;
use Kronixon\Connect\Api\Request;

/**
 * The paging members of every `GET catalog/*` (common.json `catalog_query`): `limit`, `cursor`,
 * `until_id` and `since`. A page holds the ids greater than the cursor's position and at most
 * `until_id` (inclusive), so the ranges `(0, a]`, `(a, b]`, … `(y, max_id]` read with the cursors
 * `{"id":0}`, `{"id":a}`, … cover every id once. Invalid members answer `400 bad_request`.
 */
final class PageQuery
{
    public const DEFAULT_LIMIT = 100;

    /** @var int */
    public $limit;

    /** @var int */
    public $afterId = 0;

    /** @var int|null the variant id after which rows of $afterId continue; null: after all of them */
    public $afterVariant;

    /** @var int */
    public $untilId = PHP_INT_MAX;

    /** @var string|null `since` in the shop's time zone, `Y-m-d H:i:s` */
    public $since;

    /** @var Request|null */
    private $request;

    public function __construct(int $limit = self::DEFAULT_LIMIT)
    {
        $this->limit = $limit;
    }

    /**
     * @throws ApiError 400 bad_request
     */
    public static function from(Request $request, Scope $scope, int $maxLimit): self
    {
        $query = new self(min(self::DEFAULT_LIMIT, $maxLimit));
        $query->request = $request;
        $errors = [];

        $limit = $request->query('limit');
        if ($limit !== null) {
            if (preg_match('/\A[1-9][0-9]{0,3}\z/', $limit) !== 1 || (int) $limit > $maxLimit) {
                $errors[] = 'limit';
            } else {
                $query->limit = (int) $limit;
            }
        }

        $cursor = $request->query('cursor');
        if ($cursor !== null) {
            try {
                [$query->afterId, $query->afterVariant] = Cursor::decode($cursor);
            } catch (ApiError $e) {
                $errors[] = 'cursor';
            }
        }

        $until = $request->query('until_id');
        if ($until !== null) {
            $id = self::id($until);
            if ($id === null) {
                $errors[] = 'until_id';
            } else {
                $query->untilId = $id;
            }
        }

        $since = $request->query('since');
        if ($since !== null) {
            $instant = preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(\.[0-9]{1,6})?Z\z/', $since) === 1
                ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', substr($since, 0, 19), new DateTimeZone('UTC'))
                : false;
            if ($instant === false || $instant->format('Y-m-d\TH:i:s') !== substr($since, 0, 19)) {
                $errors[] = 'since';
            } else {
                $query->since = $scope->local($instant);
            }
        }

        if ($errors !== []) {
            throw ApiError::badQuery($errors);
        }

        return $query;
    }

    /**
     * An endpoint's own parameter (`product_id`, `ids`, …), as sent.
     */
    public function param(string $name): ?string
    {
        return $this->request === null ? null : $this->request->query($name);
    }

    /**
     * An `external_id` parameter as an id; null when absent.
     *
     * @throws ApiError 400 bad_request
     */
    public function idParam(string $name): ?int
    {
        $value = $this->param($name);
        if ($value === null) {
            return null;
        }
        $id = self::id($value);
        if ($id === null) {
            throw ApiError::badQuery([$name]);
        }

        return $id;
    }

    /**
     * The conditions of a page of rows keyed by (product, variant), each an index range on
     * (product, variant) that a query orders and limits: the rest of the cursor's product when the
     * cursor names a variant, then the products after it up to `until_id`. Their results, in this
     * order, are in key order.
     *
     * @return array<string, string> `rest` (variants of the cursor's product), `range` (later products)
     */
    public function variantWindows(string $product, string $variant): array
    {
        $windows = [];
        if ($this->afterVariant !== null && $this->afterId <= $this->untilId) {
            $windows['rest'] = $product.' = '.$this->afterId.' AND '.$variant.' > '.$this->afterVariant;
        }
        if ($this->afterId < $this->untilId) {
            $windows['range'] = $product.' > '.$this->afterId.' AND '.$product.' <= '.$this->untilId;
        }

        return $windows;
    }

    /** Whether the range is empty (a cursor at or past `until_id`). */
    public function exhausted(): bool
    {
        return $this->afterVariant === null ? $this->afterId >= $this->untilId : $this->afterId > $this->untilId;
    }

    /**
     * An `external_id` (`0` or up to 20 digits without a leading zero); above PHP_INT_MAX it is
     * PHP_INT_MAX, which no shop id reaches.
     */
    private static function id(string $value): ?int
    {
        if (preg_match('/\A(0|[1-9][0-9]{0,19})\z/', $value) !== 1) {
            return null;
        }
        if (strlen($value) > 19 || (strlen($value) === 19 && strcmp($value, (string) PHP_INT_MAX) > 0)) {
            return PHP_INT_MAX;
        }

        return (int) $value;
    }
}
