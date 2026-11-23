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
use Kronixon\Connect\Protocol;
use Kronixon\Connect\Support\Log;
use PrestaShopException;
use Throwable;

/**
 * A write batch (README §6): the envelope is checked as a whole (422, 413), then the items are written
 * in order, each in its own transaction, so a refused item leaves nothing behind and the others stay.
 * A module hooked to a save may commit that transaction early (ps_facetedsearch truncates a table), so
 * writers check an item before its first write and rolledBack() removes what a rollback could not. When the time budget runs out the rest is `skipped` and the answer says `continue: true`. The
 * `content_hash` of every written entity is read once, after the last item, so it is what the shop
 * holds when the batch ends.
 */
final class Batch
{
    /** @var Database */
    private $db;

    /** @var Log */
    private $log;

    public function __construct(Database $db, Log $log)
    {
        $this->db = $db;
        $this->log = $log;
    }

    /**
     * @param  callable(Item, Warnings): Writer  $writers  the endpoint's writer for the request's `options`
     *
     * @throws ApiError 400, 413 batch_too_large, 422 validation_failed
     */
    public function run(Request $request, Budget $budget, callable $writers): Response
    {
        [$items, $options] = self::envelope($request);
        $warnings = new Warnings();
        $writer = $writers($options, $warnings);
        $results = [];
        $summary = ['ok' => 0, 'error' => 0, 'skipped' => 0];
        $written = false;
        foreach ($items as $item) {
            if ($budget->exhausted()) {
                $result = ['status' => 'skipped', 'code' => 'time_budget', 'message' => 'not processed'];
            } else {
                $result = $this->item($writer, $item, $warnings, $request);
            }
            $written = $written || $result['status'] === 'ok';
            $summary[$result['status']]++;
            $results[] = ['key' => $item->key()] + $result;
        }
        if ($written) {
            $writer->finish();
            $ok = array_values(array_filter($results, static function (array $result): bool {
                return $result['status'] === 'ok';
            }));
            $hashes = $writer->hashes($ok);
            foreach ($results as $i => $result) {
                unset($results[$i]['kind']);  // the writer's own, for hashes()
                if (isset($hashes[$result['key']])) {
                    $results[$i]['content_hash'] = $hashes[$result['key']];
                }
            }
        }

        return Response::json(200, [
            'results' => $results,
            'summary' => $summary + ['time_ms' => $budget->elapsedMs()],
            'continue' => $summary['skipped'] > 0,
            'retry_after' => 0,
        ]);
    }

    /**
     * @return array<string, mixed> the result without `key`
     */
    private function item(Writer $writer, Item $item, Warnings $warnings, Request $request): array
    {
        $warnings->take();
        $open = false;
        try {
            $kxId = $item->kxId();
            $writer->prepare($item);
            $open = true;
            $this->db->begin();
            $result = $writer->write($item);
            $this->db->commit();
            $open = false;
            $writer->committed($item);

            return ['status' => 'ok', 'kx_id' => $kxId] + $result + ['warnings' => $warnings->take()];
        } catch (Throwable $error) {
            if ($open) {
                $this->db->rollback();
                $writer->rolledBack($item);
            }
            if (! $error instanceof ItemError) {
                $error = $this->unexpected($error, $request);
            }

            return ['status' => 'error'] + $error->result() + ['warnings' => $warnings->take()];
        }
    }

    /**
     * PrestaShop's own validation (`Property Product->name is not valid`) is the item's fault; anything
     * else is logged by its class and answered without its message.
     */
    private function unexpected(Throwable $error, Request $request): ItemError
    {
        $this->log->warning('write', 'An item failed', ['path' => $request->path(), 'exception' => get_class($error)]);
        if (get_class($error) === PrestaShopException::class) {
            return ItemError::refused($error->getMessage());
        }

        return new ItemError('module_error', 'Unexpected module error.');
    }

    /**
     * @return array{0: list<Item>, 1: Item} items and options
     *
     * @throws ApiError
     */
    private static function envelope(Request $request): array
    {
        $body = json_decode($request->body(), true);
        if (! is_array($body)) {
            throw ApiError::badRequest('The body is not a JSON object.');
        }
        $items = $body['items'] ?? null;
        $options = $body['options'] ?? [];
        if (! is_array($items) || $items === [] || $items !== array_values($items)) {
            throw ApiError::validationFailed(['items']);
        }
        if (count($items) > Protocol::MAX_BATCH) {
            throw ApiError::batchTooLarge(Protocol::MAX_BATCH);
        }
        $errors = is_array($options) ? [] : ['options'];
        $parsed = [];
        foreach ($items as $i => $data) {
            $key = is_array($data) ? ($data['key'] ?? null) : null;
            // A result is told apart by its key only: without one, or with one twice, nothing is written.
            if (! is_array($data) || ! is_string($key) || $key === '' || mb_strlen($key, 'UTF-8') > 64 || isset($parsed['k'.$key])) {
                $errors[] = 'items.'.$i.'.key';
                continue;
            }
            $parsed['k'.$key] = new Item($data);
        }
        if ($errors !== [] || ! is_array($options)) {
            throw ApiError::validationFailed($errors);
        }

        return [array_values($parsed), new Item($options, 'options')];
    }
}
