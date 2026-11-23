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

use Kronixon\Connect\Api\ApiError;
use Kronixon\Connect\Api\Budget;
use Kronixon\Connect\Api\Request;
use Kronixon\Connect\Api\Response;
use Kronixon\Connect\Catalog\Cursor;
use Kronixon\Connect\Catalog\Rows;
use Kronixon\Connect\Catalog\Scope;

/**
 * `POST maintenance/reindex`, `cache-flush` and `facets` (Spec §6.28–§6.30), under the write lock as
 * every POST. The long ones walk the paired shop's products by id in portions of 50 within `budget_s`
 * (at most the request's budget): the answer's `cursor` names the last product done and the platform
 * sends it back with the same body while `continue` is true, so a walk visits each product once and
 * ends even where PrestaShop leaves a product unindexed (an inactive one).
 */
final class Maintenance
{
    private const PORTION = 50;

    private const MAX_IDS = 1000;

    /** @var Database */
    private $db;

    /** @var Scope */
    private $scope;

    /** @var Facets */
    private $facets;

    /** @var callable(int): void Search::indexation() of one product */
    private $index;

    /** @var callable(string): void the caches of a `cache-flush` scope */
    private $flush;

    /**
     * @param  callable(int): void  $index
     * @param  callable(string): void  $flush
     */
    public function __construct(Database $db, Scope $scope, Facets $facets, callable $index, callable $flush)
    {
        $this->db = $db;
        $this->scope = $scope;
        $this->facets = $facets;
        $this->index = $index;
        $this->flush = $flush;
    }

    /**
     * @param  string  $operation  `reindex`, `cache-flush` or `facets`
     *
     * @throws ApiError
     */
    public function handle(string $operation, Request $request, Budget $budget): Response
    {
        try {
            if ($operation === 'reindex') {
                return $this->reindex($request, $budget);
            }

            return $operation === 'facets' ? $this->facets($request, $budget) : $this->cacheFlush($request, $budget);
        } catch (ItemError $error) {
            // A member of the body of the wrong type.
            throw ApiError::validationFailed([$error->field() ?? 'body']);
        }
    }

    /**
     * `scope: pending` indexes the products PrestaShop marked (`indexed = 0`), `products` marks and
     * indexes the listed ones, `full` marks every product of the shop first; a call with a `cursor`
     * marks nothing again.
     */
    private function reindex(Request $request, Budget $budget): Response
    {
        $body = self::body($request);
        $scope = $body->choice('scope', ['pending', 'products', 'full']);
        [$ids, $after, $seconds] = [$this->ids($body, 'product_ids'), self::cursor($body), self::seconds($body, $budget)];
        if ($scope === null || ($scope === 'products' && $ids === [])) {
            throw ApiError::validationFailed([$scope === null ? 'scope' : 'product_ids']);
        }
        $only = $scope === 'products' ? ' AND id_product IN ('.Rows::in($ids).')' : '';
        if ($after === 0 && $scope !== 'pending') {
            $this->db->bulk('UPDATE '.$this->db->table('product_shop').' SET indexed = 0 WHERE id_shop = '.$this->scope->shopId.$only);
        }
        $where = ' FROM '.$this->db->table('product_shop').' WHERE id_shop = '.$this->scope->shopId.' AND indexed = 0'.$only;
        [$done, $last] = $this->walk('SELECT id_product'.$where, $after, $budget, $seconds, function (int $id): void {
            ($this->index)($id);
        });
        $remaining = (int) ($this->db->rows('SELECT COUNT(*) AS n'.$where." AND active = 1 AND visibility IN ('both', 'search') AND id_product > ".$last)[0]['n'] ?? 0);
        $more = $this->db->rows('SELECT id_product'.$where.' AND id_product > '.$last.' LIMIT 1') !== [];

        return Response::json(200, ['indexed' => $done, 'remaining' => $remaining, 'continue' => $more, 'cursor' => $more ? Cursor::encode($last) : null]);
    }

    /** Through PrestaShop's own cache APIs, never by deleting files at large. */
    private function cacheFlush(Request $request, Budget $budget): Response
    {
        $scope = self::body($request)->choice('scope', ['prices', 'products', 'smarty', 'all']);
        if ($scope === null) {
            throw ApiError::validationFailed(['scope']);
        }
        ($this->flush)($scope);

        return Response::json(200, ['cleared' => [$scope], 'time_ms' => $budget->elapsedMs()]);
    }

    /**
     * ps_facetedsearch's indexes of the products of `product_ids`, of the categories of `category_ids`
     * (products directly in them) or of the whole shop: `prices` (layered_price_index), `attributes`
     * (layered_product_attribute; new features and groups become indexable first), `categories` and
     * `all` both. The storefront's cached filter blocks go when the walk ends.
     */
    private function facets(Request $request, Budget $budget): Response
    {
        if (! $this->facets->available()) {
            throw ApiError::capabilityDisabled('facets');
        }
        $body = self::body($request);
        $scope = $body->choice('scope', ['categories', 'prices', 'attributes', 'all']);
        [$products, $categories, $after, $seconds] = [$this->ids($body, 'product_ids'), $this->ids($body, 'category_ids'), self::cursor($body), self::seconds($body, $budget)];
        if ($scope === null) {
            throw ApiError::validationFailed(['scope']);
        }
        $prices = $scope !== 'attributes';
        $attributes = $scope !== 'prices';
        if ($attributes && $after === 0) {
            $this->facets->indexables();
        }
        $where = ' FROM '.$this->db->table('product_shop').' WHERE id_shop = '.$this->scope->shopId;
        if ($products !== []) {
            $where .= ' AND id_product IN ('.Rows::in($products).')';
        }
        if ($categories !== []) {
            $where .= ' AND id_product IN (SELECT id_product FROM '.$this->db->table('category_product').' WHERE id_category IN ('.Rows::in($categories).'))';
        }
        [$done, $last] = $this->walk('SELECT id_product'.$where, $after, $budget, $seconds, function (int $id) use ($prices, $attributes): void {
            if ($prices) {
                $this->facets->indexPrices($id);
            }
            if ($attributes) {
                $this->facets->indexAttributes($id);
            }
        });
        $more = $this->db->rows('SELECT id_product'.$where.' AND id_product > '.$last.' LIMIT 1') !== [];
        if (! $more) {
            $this->facets->invalidate();
        }

        return Response::json(200, ['indexed' => $done, 'cursor' => $more ? Cursor::encode($last) : null, 'continue' => $more]);
    }

    /**
     * The products of $select after $after, ascending, while time is left.
     *
     * @param  callable(int): void  $each
     * @return array{0: int, 1: int} how many were done, the last one
     */
    private function walk(string $select, int $after, Budget $budget, float $seconds, callable $each): array
    {
        $start = $budget->remaining();
        $done = 0;
        $last = $after;
        while (true) {
            $ids = Rows::ints($this->db->rows($select.' AND id_product > '.$last.' ORDER BY id_product LIMIT '.self::PORTION), 'id_product');
            foreach ($ids as $id) {
                if ($start - $budget->remaining() >= $seconds || $budget->exhausted()) {
                    return [$done, $last];
                }
                $each($id);
                $last = $id;
                $done++;
            }
            if (count($ids) < self::PORTION) {
                return [$done, $last];
            }
        }
    }

    /**
     * @return list<int>
     */
    private function ids(Item $body, string $name): array
    {
        $ids = [];
        foreach ($body->strings($name) ?? [] as $i => $id) {
            $item = new Item(['id' => $id]);
            $value = $item->id('id');
            if ($value === null) {
                throw ApiError::validationFailed([$name.'.'.$i]);
            }
            $ids[] = $value;
        }
        if (count($ids) > self::MAX_IDS) {
            throw ApiError::batchTooLarge(self::MAX_IDS);
        }

        return $ids;
    }

    private static function body(Request $request): Item
    {
        $body = json_decode($request->body(), true);
        if (! is_array($body) || ($body !== [] && isset($body[0]))) {
            throw ApiError::badRequest('The body is not a JSON object.');
        }

        return new Item($body);
    }

    /** The product id a cursor names; 0 without one. */
    private static function cursor(Item $body): int
    {
        try {
            $cursor = $body->string('cursor', 200);
        } catch (ItemError $error) {
            throw ApiError::validationFailed(['cursor']);
        }
        if ($cursor === null || $cursor === '') {
            return 0;
        }
        return Cursor::decode($cursor)[0];
    }

    private static function seconds(Item $body, Budget $budget): float
    {
        try {
            $seconds = $body->int('budget_s', 1);
        } catch (ItemError $error) {
            throw ApiError::validationFailed(['budget_s']);
        }

        return (float) min($seconds ?? $budget->seconds(), $budget->seconds());
    }
}
