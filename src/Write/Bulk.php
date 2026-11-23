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
use Kronixon\Connect\Catalog\Rows;
use Kronixon\Connect\Catalog\Scope;
use Kronixon\Connect\Support\Log;
use Throwable;

/**
 * A bulk endpoint (`prices/bulk`, `stock/bulk`; README §6.2): up to 1 000 rows without a `key`, a
 * result per row in the order sent, told apart by `product_id` and `variant_id`. The rows are read
 * and written in chunks of 100, each chunk in one transaction that reads the rows it writes with their
 * locks; a row is checked before anything is written, and an order or a save that commits meanwhile
 * waits for the chunk's commit instead of being overwritten. Rows whose locks another session holds
 * longer than the wait are `skipped` (`rows_locked`) with `continue`. A row whose current value the shop already holds is `unchanged`, so a repeat after a lost
 * answer neither writes nor sees a conflict. `expected_*` names the value the platform believes the
 * shop holds: when the shop holds another one (changed in the shop), the row is `conflict` with the
 * shop's value and is not written. When the time budget runs out the rest is `skipped`.
 */
abstract class Bulk
{
    public const MAX_ROWS = 1000;

    private const CHUNK = 100;

    /** Seconds a chunk waits at most for the locks of its rows (an order or a back-office save). */
    private const LOCK_WAIT_S = 10;

    /** @var Database */
    protected $db;

    /** @var Scope */
    protected $scope;

    /** @var PendingWrites */
    protected $pending;

    /** @var Log */
    protected $log;

    /** @var bool the paired shop is the default one: `product` holds its values too */
    protected $defaultShop;

    /** @var callable(string, array<string, mixed>): void Hook::exec() */
    protected $hook;

    /** @var array<int, true> products whose rows changed */
    protected $changed = [];

    /**
     * @param  callable(string, array<string, mixed>): void  $hook
     */
    public function __construct(Database $db, Scope $scope, PendingWrites $pending, Log $log, bool $defaultShop, callable $hook)
    {
        $this->db = $db;
        $this->scope = $scope;
        $this->pending = $pending;
        $this->log = $log;
        $this->defaultShop = $defaultShop;
        $this->hook = $hook;
    }

    /**
     * @throws ApiError 400, 413 batch_too_large, 422 validation_failed
     */
    public function run(Request $request, Budget $budget): Response
    {
        $body = json_decode($request->body(), true);
        if (! is_array($body)) {
            throw ApiError::badRequest('The body is not a JSON object.');
        }
        $rows = $body['rows'] ?? null;
        if (! is_array($rows) || $rows === [] || $rows !== array_values($rows)) {
            throw ApiError::validationFailed(['rows']);
        }
        if (count($rows) > self::MAX_ROWS) {
            throw ApiError::batchTooLarge(self::MAX_ROWS);
        }
        $options = $body['options'] ?? [];
        if (! is_array($options) || ($options !== [] && isset($options[0]))) {
            throw ApiError::validationFailed(['options']);
        }
        try {
            $this->options(new Item($options, 'options'));
        } catch (ItemError $error) {
            throw ApiError::validationFailed([$error->field() ?? 'options']);
        }
        $this->pending->recover($budget);
        $this->changed = [];

        $results = [];
        $parsed = [];
        $seen = [];
        foreach ($rows as $i => $data) {
            $row = is_array($data) ? new Item($data, 'rows.'.$i) : null;
            $results[$i] = self::identity($data);
            try {
                if ($row === null) {
                    throw ItemError::invalid('rows.'.$i);
                }
                $product = $row->id('product_id');
                $variant = $row->string('variant_id') === '0' ? 0 : $row->id('variant_id');
                if ($product === null || $variant === null) {
                    throw ItemError::invalid($row->at($product === null ? 'product_id' : 'variant_id'));
                }
                if (isset($seen[$product.':'.$variant])) {
                    throw ItemError::invalid($row->at('variant_id'), 'names a row sent before');
                }
                $seen[$product.':'.$variant] = true;
                $parsed[$i] = ['product' => $product, 'variant' => $variant, 'warnings' => new Warnings()] + $this->parse($row);
            } catch (ItemError $error) {
                $results[$i] += ['status' => 'error'] + $error->result();
            }
        }

        foreach (array_chunk($parsed, self::CHUNK, true) as $chunk) {
            if ($budget->exhausted()) {
                foreach (array_keys($chunk) as $i) {
                    $results[$i] += ['status' => 'skipped', 'code' => 'time_budget', 'message' => 'not processed'];
                }
                continue;
            }
            // The longest wait for a row lock: what is left of the budget, at most LOCK_WAIT_S.
            $wait = (int) max(1, min(self::LOCK_WAIT_S, floor($budget->remaining() - Budget::RESERVE_S)));
            foreach ($this->chunk($chunk, $wait) as $i => $result) {
                $results[$i] += $result;
            }
        }
        $extra = $this->finish();

        $summary = ['ok' => 0, 'error' => 0, 'skipped' => 0, 'conflict' => 0];
        foreach ($results as $result) {
            $summary[$result['status']]++;
        }
        ksort($results);

        return Response::json(200, [
            'results' => array_values($results),
            'summary' => $summary + ['time_ms' => $budget->elapsedMs()],
            'continue' => $summary['skipped'] > 0,
            'retry_after' => 0,
        ] + $extra);
    }

    /**
     * The endpoint's options.
     *
     * @throws ItemError
     */
    abstract protected function options(Item $options): void;

    /**
     * What the row asks for, checked without the database.
     *
     * @return array<string, mixed>
     *
     * @throws ItemError
     */
    abstract protected function parse(Item $row): array;

    /**
     * What the shop holds of the rows' products and combinations, read once for the chunk inside its
     * transaction: first the rows the chunk writes, with their locks (Database::lockRows(), in the order
     * of their primary key), so `expected_*` is compared with what the shop holds until the commit and
     * a change committed meanwhile is not overwritten.
     *
     * @param  array<int, array<string, mixed>>  $rows
     *
     * @throws RowsLocked
     */
    abstract protected function load(array $rows, int $waitSeconds): void;

    /**
     * The row's result without `product_id`/`variant_id`; it writes only when its status is `ok` and
     * something changes.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     *
     * @throws ItemError the row is refused, nothing of it written
     */
    abstract protected function row(array $row): array;

    /**
     * The chunk is committed (hooks, indexes).
     *
     * @param  list<int>  $products  the products whose rows of the chunk changed
     */
    abstract protected function committed(array $products): void;

    /**
     * After the last chunk: caches, the batch's hook; members of the answer besides `results`.
     *
     * @return array<string, mixed>
     */
    abstract protected function finish(): array;

    /**
     * Whether the product is in the paired shop as a whole; what a failed item left is `cleanup_failed`.
     *
     * @param  array<int, mixed>  $known  product id => what load() read of it
     *
     * @throws ItemError
     */
    protected function product(array $known, int $id): void
    {
        if ($this->pending->isLeft('product', $id)) {
            throw ItemError::leftOver('product_id');
        }
        if (! isset($known[$id])) {
            throw ItemError::missing('product_id', 'product_not_found');
        }
    }

    /**
     * A statement on `product_shop` (or `product_attribute_shop`) of the paired shop, and on the main
     * table when the paired shop is the default one, as PrestaShop's own save does.
     *
     * @param  array<string, string>  $set  column => SQL value
     */
    protected function update(string $table, string $primary, int $id, array $set): void
    {
        $sql = [];
        foreach ($set as $column => $value) {
            $sql[] = $column.' = '.$value;
        }
        $this->db->bulk('UPDATE '.$this->db->table($table.'_shop').' SET '.implode(', ', $sql).' WHERE '.$primary.' = '.$id.' AND id_shop = '.$this->scope->shopId);
        if ($this->defaultShop) {
            $this->db->bulk('UPDATE '.$this->db->table($table).' SET '.implode(', ', $sql).' WHERE '.$primary.' = '.$id);
        }
    }

    /**
     * @param  list<int>  $ids
     */
    protected static function in(array $ids): string
    {
        return Rows::in($ids);
    }

    /**
     * Runs a hook of the batch; one that throws is logged and the rows stay written.
     *
     * @param  array<string, mixed>  $params
     */
    protected function announce(string $name, array $params): void
    {
        try {
            ($this->hook)($name, $params);
        } catch (Throwable $error) {
            $this->log->warning('write', 'A hook after a bulk write failed', ['hook' => $name, 'exception' => get_class($error)]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $chunk
     * @return array<int, array<string, mixed>>
     */
    private function chunk(array $chunk, int $waitSeconds): array
    {
        $results = [];
        $changed = [];
        try {
            $this->db->begin();
            $this->load($chunk, $waitSeconds);
            foreach ($chunk as $i => $row) {
                try {
                    $result = $this->row($row);
                } catch (ItemError $error) {
                    $result = ['status' => 'error'] + $error->result();
                }
                if (($result['action'] ?? null) === 'updated') {
                    $changed[$row['product']] = $row['product'];
                }
                $results[$i] = $result;
            }
            $this->db->commit();
        } catch (Throwable $error) {
            try {
                $this->db->rollback();
            } catch (Throwable $ignored) {
                // nothing open
            }
            // After the rollback: the log shares the connection.
            $this->log->warning('write', 'A bulk chunk failed', ['exception' => get_class($error)]);
            foreach (array_keys($chunk) as $i) {
                // Rows another session holds are sent again with `continue`, like rows out of time.
                $results[$i] = $error instanceof RowsLocked
                    ? ['status' => 'skipped', 'code' => 'rows_locked', 'message' => 'the rows are locked by another session']
                    : ['status' => 'error', 'code' => 'module_error', 'message' => 'Unexpected module error.'];
            }

            return $results;
        }
        $this->changed += $changed;
        $this->committed(array_values($changed));
        foreach ($chunk as $i => $row) {
            /** @var Warnings $warnings */
            $warnings = $row['warnings'];
            $results[$i] += ['warnings' => $warnings->take()];
        }

        return $results;
    }

    /**
     * @param  mixed  $data
     * @return array<string, string>
     */
    private static function identity($data): array
    {
        // An id that is not one is answered as "0", so the answer stays valid; `field` names the row.
        $id = static function (string $member) use ($data): string {
            $value = is_array($data) ? ($data[$member] ?? null) : null;

            return is_string($value) && preg_match('/\A(0|[1-9][0-9]{0,19})\z/', $value) === 1 ? $value : '0';
        };

        return ['product_id' => $id('product_id'), 'variant_id' => $id('variant_id')];
    }
}
