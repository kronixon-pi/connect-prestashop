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

use Cache;
use Image;
use Kronixon\Connect\Api\ApiError;
use Kronixon\Connect\Api\Budget;
use Kronixon\Connect\Api\Request;
use Kronixon\Connect\Api\Response;
use Throwable;

/**
 * `DELETE images` (module/images-delete.json, Spec §6.25): images of one product by id, by `kx_key` or
 * all, through `Image::delete()` (rows, files, combination links; on multistore PrestaShop keeps an
 * image another shop has). `only_kx` (the default) spares the images the shop uploaded itself: named by
 * id they are `image_not_kx`, with `all` they stay. A result per image, keyed by its id (a `kx_key` the
 * product does not have: keyed by the `kx_key`, `not_found`). When the cover goes, the first image
 * left is the new one.
 */
final class ImageDeleter
{
    /** @var Shop */
    private $shop;

    public function __construct(Shop $shop)
    {
        $this->shop = $shop;
    }

    /**
     * @throws ApiError 400, 413, 422
     */
    public function run(Request $request, Budget $budget): Response
    {
        $data = json_decode($request->body(), true);
        if (! is_array($data) || ($data !== [] && isset($data[0]))) {
            throw ApiError::badRequest('The body is not a JSON object.');
        }
        $body = new Item($data);
        try {
            $productId = (int) $body->id('product_id');
            $ids = $body->strings('external_ids') ?? [];
            $keys = $body->strings('kx_keys') ?? [];
            $all = $body->bool('all') === true;
            $onlyKx = $body->bool('only_kx') ?? true;
        } catch (ItemError $error) {
            throw ApiError::validationFailed([$error->field() ?? 'body']);
        }
        if (count($ids) > 50 || count($keys) > 50) {
            throw ApiError::batchTooLarge(50);
        }
        if ($productId === 0 || (! $all && $ids === [] && $keys === [])) {
            throw ApiError::validationFailed([$productId === 0 ? 'product_id' : 'external_ids']);
        }
        $this->shop->pending->recover($budget);
        $results = [];
        if (! $this->shop->holds('product', $productId)) {
            $results[] = ['key' => (string) $productId, 'external_id' => (string) $productId, 'status' => 'error', 'code' => 'product_not_found', 'message' => 'product_id names nothing of this shop', 'field' => 'product_id'];

            return self::answer($results, $budget);
        }
        $images = $this->images($productId);
        $targets = [];
        foreach ($ids as $i => $id) {
            $id = preg_match('/\A[1-9][0-9]{0,9}\z/', $id) === 1 ? (int) $id : 0;
            if ($id === 0) {
                throw ApiError::validationFailed(['external_ids.'.$i]);
            }
            $targets[$id] = 'external_ids.'.$i;
        }
        foreach ($keys as $i => $key) {
            $found = array_search($key, array_map(static function (array $image): ?string {
                return $image['kx_key'];
            }, $images), true);
            if ($found === false) {
                $results[] = ['key' => substr($key, 0, 64), 'external_id' => null, 'status' => 'error', 'code' => 'not_found', 'message' => 'kx_keys.'.$i.' names no image of the product', 'field' => 'kx_keys.'.$i];
            } else {
                $targets[(int) $found] = 'kx_keys.'.$i;
            }
        }
        if ($all) {
            foreach ($images as $id => $image) {
                if (! $onlyKx || $image['kx_key'] !== null) {
                    $targets += [$id => 'all'];
                }
            }
        }
        $cover = false;
        foreach ($targets as $id => $field) {
            $result = ['key' => (string) $id, 'external_id' => (string) $id];
            if ($budget->exhausted()) {
                $results[] = $result + ['status' => 'skipped', 'code' => 'time_budget', 'message' => 'not processed'];
                continue;
            }
            if (! isset($images[$id])) {
                $results[] = $result + ['status' => 'error', 'code' => 'not_found', 'message' => $field.' names no image of the product', 'field' => $field];
                continue;
            }
            if ($onlyKx && $images[$id]['kx_key'] === null) {
                $results[] = $result + ['status' => 'error', 'code' => 'image_not_kx', 'message' => 'The shop uploaded this image itself (only_kx)', 'field' => $field];
                continue;
            }
            try {
                ImageRemoval::delete(new Image($id));
                $this->shop->db->execute('DELETE FROM '.$this->shop->db->table('kx_image_map').' WHERE id_image = '.$id);
                $cover = $cover || $images[$id]['cover'];
                $results[] = $result + ['status' => 'ok', 'action' => 'deleted', 'warnings' => []];
            } catch (Throwable $error) {
                $results[] = $result + ['status' => 'error', 'code' => 'module_error', 'message' => 'Unexpected module error.'];
            }
        }
        if ($cover) {
            $this->newCover($productId);
        }

        return self::answer($results, $budget);
    }

    /**
     * @return array<int, array{cover: bool, kx_key: string|null}>
     */
    private function images(int $productId): array
    {
        $db = $this->shop->db;
        $images = [];
        foreach ($db->rows('SELECT i.id_image, ish.cover, m.kx_key FROM '.$db->table('image').' i INNER JOIN '.$db->table('image_shop').' ish ON ish.id_image = i.id_image AND ish.id_shop = '
            .$this->shop->scope->shopId.' LEFT JOIN '.$db->table('kx_image_map').' m ON m.id_image = i.id_image WHERE i.id_product = '.$productId.' ORDER BY i.position, i.id_image') as $row) {
            if (! $this->shop->pending->isLeft('image', (int) $row['id_image'])) {
                $images[(int) $row['id_image']] = ['cover' => (int) $row['cover'] === 1, 'kx_key' => $row['kx_key']];
            }
        }

        return $images;
    }

    private function newCover(int $productId): void
    {
        $left = array_keys($this->images($productId));
        if ($left === []) {
            return;
        }
        Image::deleteCover($productId);
        $image = new Image($left[0]);
        $image->cover = true;
        $image->update();
        Cache::clean('objectmodel_Image_*');
        Cache::clean('Product::getCover*');
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private static function answer(array $results, Budget $budget): Response
    {
        $summary = ['ok' => 0, 'error' => 0, 'skipped' => 0];
        foreach ($results as $result) {
            $summary[$result['status']]++;
        }

        return Response::json(200, ['results' => $results, 'summary' => $summary + ['time_ms' => $budget->elapsedMs()], 'continue' => $summary['skipped'] > 0, 'retry_after' => 0]);
    }
}
