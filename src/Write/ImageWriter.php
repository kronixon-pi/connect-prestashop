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
use Combination;
use Configuration;
use Hook;
use Image;
use Kronixon\Connect\Api\Budget;
use Kronixon\Connect\Catalog\ImageReader;
use Kronixon\Connect\Catalog\Reader;
use ObjectModel;
use Throwable;

/**
 * `POST images` (module/images.json, Spec §6.24): the images of one product, by `kx_key`.
 *
 * An item whose `kx_key` (or `external_id`) names an image of the product whose file has the sha256 sent
 * is not fetched again and keeps its id: only its alt texts change. Another sha256 replaces the files of
 * the same image (its id and URLs stay). A new one is fetched and its files made before the item's
 * transaction; the image's rows are written by PrestaShop's `Image::add()`, and its files are put in
 * place from this module's `actionObjectAddAfter`, right after the rows and before any other module's
 * hook: a module that commits there early commits a complete image, and `kx_pending` keeps it out of
 * every read until the item is complete. After the last item `strategy` decides what else the product
 * keeps (`sync`: the images of the request and those the shop uploaded itself, after ours; `replace_all`:
 * only those of the request; `append`: all), then the order and the cover (exactly one: the last item
 * with `cover: true`, else the cover there was, else the first).
 *
 * `variant_id` links every image of the request to that combination of the product (others it has
 * stay). A combination sent before its images (`image_key_unknown` of `POST variants`) is linked by
 * sending the variant again after the images, or by this `variant_id`.
 */
final class ImageWriter extends ShopWriter
{
    /** Spec §5.7: what one request may fetch in all. */
    private const MAX_REQUEST_BYTES = 104857600;

    /** @var Download */
    private $download;

    /** @var ImageFiles */
    private $files;

    /** @var Item */
    private $body;

    /** @var int */
    private $productId = 0;

    /** @var int */
    private $variantId = 0;

    /** @var string */
    private $strategy = 'sync';

    /** @var ItemError|null why every item is refused */
    private $refused;

    /** @var array<int, array{position: int, cover: bool, kx_key: string|null, sha256: string|null}> the product's images */
    private $images = [];

    /** @var int bytes fetched by the request */
    private $fetched = 0;

    /** @var string|null the files of the item being written */
    private $work;

    /** @var list<string> files placed for an image the item created */
    private $placed = [];

    /** @var int the image the item wrote */
    private $id = 0;

    /** @var bool the item put new files in place */
    private $newFiles = false;

    /** @var array<string, int> kx_key => image, of the items written */
    private $written = [];

    /** @var list<string> kx_key of every item, in order, also of those refused */
    private $sent = [];

    /** @var array<string, int> kx_key => the position sent */
    private $positions = [];

    /** @var string|null kx_key of the last item with `cover: true` */
    private $cover;

    public function __construct(Shop $shop, Download $download, ImageFiles $files, Item $body)
    {
        parent::__construct($shop);
        $this->download = $download;
        $this->files = $files;
        $this->body = $body;
    }

    public function start(Budget $budget): void
    {
        parent::start($budget);
        $limit = (string) ini_get('memory_limit');
        if ($limit !== '-1' && self::bytes($limit) < 536870912) {
            @ini_set('memory_limit', '512M');
        }
        $this->files->sweep();
        try {
            $this->productId = (int) $this->body->id('product_id');
            $this->strategy = $this->body->choice('strategy', ['sync', 'append', 'replace_all']) ?? 'sync';
            if ($this->productId === 0 || ! $this->shop->holds('product', $this->productId)) {
                throw ItemError::missing('product_id', $this->shop->pending->isLeft('product', $this->productId) ? 'cleanup_failed' : 'product_not_found');
            }
            $this->variantId = (int) $this->body->id('variant_id');
            if ($this->variantId !== 0 && $this->shop->db->rows('SELECT 1 FROM '.$this->shop->db->table('product_attribute').' WHERE id_product_attribute = '
                .$this->variantId.' AND id_product = '.$this->productId) === []) {
                throw ItemError::missing('variant_id');
            }
        } catch (ItemError $error) {
            $this->refused = $error;
        }
        $this->load();
    }

    public function prepare(Item $item): void
    {
        parent::prepare($item);
        [$this->work, $this->placed, $this->id, $this->newFiles] = [null, [], 0, false];
        $kxKey = $item->string('kx_key', 64);
        if ($kxKey !== null && $kxKey !== '') {
            $this->sent[] = $kxKey;
        }
        if ($this->refused !== null) {
            throw $this->refused;
        }
        $sha256 = (string) $item->string('sha256');
        if ($kxKey === null || $kxKey === '' || preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1) {
            throw ItemError::invalid($kxKey === null || $kxKey === '' ? 'kx_key' : 'sha256');
        }
        if (isset($this->written[$kxKey]) || count(array_keys($this->sent, $kxKey, true)) > 1) {
            throw ItemError::invalid('kx_key', 'names an image sent before');
        }
        $position = $item->int('position');
        if ($position !== null) {
            $this->positions[$kxKey] = $position;
        }
        $item->bool('cover');
        $this->shop->texts->of($item, 'alt');
        $id = $this->target($item, $kxKey);
        if ($id !== null && $this->images[$id]['sha256'] === $sha256) {
            return;
        }
        $source = $this->download->fetch((string) $item->string('url'), $sha256, 'url', $this->files->workRoot());
        try {
            $size = (int) filesize($source);
            $this->fetched += $size;
            $max = (int) Configuration::get('PS_PRODUCT_PICTURE_MAX_SIZE');
            if ($this->fetched > self::MAX_REQUEST_BYTES || ($max > 0 && $size > $max)) {
                throw new ItemError('image_too_large', 'The image is larger than the shop takes', 'url');
            }
            if (! ImageFiles::accepts($source)) {
                throw new ItemError('image_invalid', 'The file is not a jpg, png, gif or webp image', 'url');
            }
            $this->work = $this->files->make($source, 'url');
        } finally {
            @unlink($source);
        }
    }

    public function write(Item $item): array
    {
        $kxKey = (string) $item->string('kx_key', 64);
        $sha256 = (string) $item->string('sha256');
        $id = $this->target($item, $kxKey);
        $image = new Image($id);
        $fields = $this->shop->fields($image)->sentAs(['legend' => $item->at('alt')]);
        $fields->text('legend', self::legends($this->shop->texts->of($item, 'alt')));
        if ($id === null) {
            $image->id_product = $this->productId;
            $image->cover = false;
            $image->position = Image::getHighestPosition($this->productId) + 1;
            $fields->check();
            $placed = false;
            $added = $this->shop->pending->add($image, function (ObjectModel $stored) use (&$placed): void {
                $this->placed = $this->files->place((string) $this->work, $this->baseOf($stored), 'url');
                $placed = true;
            });
            if (! $added || ! $placed) {
                throw ItemError::notSaved('image');
            }
        } else {
            $fields->save('image');
            if ($this->work !== null) {
                $this->files->place($this->work, $this->baseOf($image), 'url');
            }
        }
        $this->id = (int) $image->id;
        $this->newFiles = $this->work !== null;
        if ($this->newFiles) {
            $this->map($kxKey, $sha256, (string) $item->string('url'));
        }
        if ($this->variantId !== 0) {
            $this->link();
        }
        $this->written[$kxKey] = $this->id;
        if ($item->bool('cover') === true) {
            $this->cover = $kxKey;
        }

        return self::done($this->id, $id === null, $this->newFiles || $fields->changed()) + ['urls' => $this->urls($this->id)];
    }

    public function committed(Item $item): void
    {
        parent::committed($item);
        $this->files->drop($this->work);
        $this->work = null;
        if ($this->newFiles) {
            // As the back office does after storing an image's files (a watermark module listens).
            try {
                Hook::exec('actionWatermark', ['id_image' => $this->id, 'id_product' => $this->productId]);
            } catch (Throwable $error) {
                $this->shop->warnings->add('watermark_failed');
            }
        }
        $this->load();
    }

    public function rolledBack(Item $item): bool
    {
        // The files of an image the rollback took; those replaced in place are the new ones already and the
        // stored sha256 is the old one, so a repeat replaces them again.
        foreach ($this->placed as $file) {
            @unlink($file);
        }
        $this->placed = [];
        $this->files->drop($this->work);
        $this->work = null;
        $clean = parent::rolledBack($item);
        $this->load();

        return $clean;
    }

    /** After the last item: what the strategy removes, then the order and the cover. */
    public function finish(): void
    {
        if ($this->refused === null) {
            try {
                $this->arrange();
            } catch (Throwable $error) {
                $this->shop->warnings->add('images_not_arranged');
            }
        }
        parent::finish();
    }

    /** No `content_hash`: GET catalog/images answers none. */
    public function hashes(array $results): array
    {
        return [];
    }

    protected function reader(): Reader
    {
        return new ImageReader($this->shop->db, $this->shop->scope, $this->shop->urls);
    }

    /**
     * @throws ItemError
     */
    private function target(Item $item, string $kxKey): ?int
    {
        $id = $item->id('external_id');
        if ($id !== null) {
            if (! isset($this->images[$id])) {
                throw ItemError::missing('external_id');
            }

            return $id;
        }
        foreach ($this->images as $imageId => $image) {
            if ($image['kx_key'] === $kxKey) {
                return $imageId;
            }
        }

        return null;
    }

    /** The product's images in the paired shop, without what a failed item left. */
    private function load(): void
    {
        $this->images = [];
        if ($this->productId === 0) {
            return;
        }
        $db = $this->shop->db;
        foreach ($db->rows('SELECT i.id_image, i.position, ish.cover, m.kx_key, m.sha256 FROM '.$db->table('image').' i INNER JOIN '.$db->table('image_shop')
            .' ish ON ish.id_image = i.id_image AND ish.id_shop = '.$this->shop->scope->shopId.' LEFT JOIN '.$db->table('kx_image_map').' m ON m.id_image = i.id_image'
            .' WHERE i.id_product = '.$this->productId.' ORDER BY i.position, i.id_image') as $row) {
            if (! $this->shop->pending->isLeft('image', (int) $row['id_image'])) {
                $this->images[(int) $row['id_image']] = ['position' => (int) $row['position'], 'cover' => (int) $row['cover'] === 1,
                    'kx_key' => $row['kx_key'], 'sha256' => $row['sha256'], ];
            }
        }
    }

    private function map(string $kxKey, string $sha256, string $url): void
    {
        $db = $this->shop->db;
        $host = parse_url($url, PHP_URL_HOST);
        $db->execute('DELETE FROM '.$db->table('kx_image_map').' WHERE id_image = '.$this->id.' OR (id_product = '.$this->productId.' AND kx_key = '.$db->quote($kxKey).')');
        $db->execute('INSERT INTO '.$db->table('kx_image_map').' (id_image, id_product, kx_key, sha256, source_url_host, uploaded_at) VALUES ('.$this->id.', '.$this->productId.', '
            .$db->quote($kxKey).', '.$db->quote($sha256).', '.$db->quote(substr(is_string($host) ? $host : '', 0, 191)).', '.$db->quote(date('Y-m-d H:i:s')).')');
    }

    /** The image joins the combination's images. */
    private function link(): void
    {
        $current = array_map('intval', array_column($this->shop->db->rows('SELECT id_image FROM '.$this->shop->db->table('product_attribute_image')
            .' WHERE id_product_attribute = '.$this->variantId), 'id_image'));
        if (in_array($this->id, $current, true)) {
            return;
        }
        $combination = new Combination($this->variantId);
        if (! $combination->setImages(array_values(array_merge($current, [$this->id])))) {
            throw ItemError::notSaved('combination images');
        }
    }

    /**
     * Removes what the strategy does not keep, then numbers the images and sets the one cover.
     */
    private function arrange(): void
    {
        $this->load();
        $ours = [];
        foreach ($this->sent as $index => $kxKey) {
            foreach ($this->images as $id => $image) {
                if ($image['kx_key'] === $kxKey) {
                    $ours[$id] = [$this->positions[$kxKey] ?? PHP_INT_MAX, $index];
                }
            }
        }
        foreach ($this->images as $id => $image) {
            $foreign = $image['kx_key'] === null;
            if (! isset($ours[$id]) && (($this->strategy === 'sync' && ! $foreign) || $this->strategy === 'replace_all')) {
                $this->delete($id);
            }
        }
        $this->load();
        uasort($ours, static function (array $a, array $b): int {
            return $a <=> $b;
        });
        $others = array_diff_key($this->images, $ours);
        $order = $this->strategy === 'append' ? array_merge(array_keys($others), array_keys($ours)) : array_merge(array_keys($ours), array_keys($others));
        $cover = $this->cover !== null ? ($this->written[$this->cover] ?? null) : null;
        if ($cover === null) {
            foreach ($this->images as $id => $image) {
                $cover = $image['cover'] ? $id : $cover;
            }
        }
        $cover = $cover ?? ($order[0] ?? null);
        $changed = false;
        foreach ($order as $i => $id) {
            if (! isset($this->images[$id]) || ($this->images[$id]['position'] === $i + 1 && $this->images[$id]['cover'] === ($id === $cover))) {
                continue;
            }
            if ($id === $cover && ! $this->images[$id]['cover']) {
                Image::deleteCover($this->productId);
            }
            $image = new Image($id);
            $image->position = $i + 1;
            $image->cover = $id === $cover;
            $image->update();
            $changed = true;
        }
        if ($changed) {
            Cache::clean('objectmodel_Image_*');
            Cache::clean('Product::getCover*');
        }
    }

    private function delete(int $id): void
    {
        // In the paired shop; on multistore PrestaShop keeps the image and its files while another shop has it.
        ImageRemoval::delete(new Image($id));
        $this->shop->db->execute('DELETE FROM '.$this->shop->db->table('kx_image_map').' WHERE id_image = '.$id);
    }

    /**
     * @return array<string, string> image type (and `original`) => URL
     */
    private function urls(int $id): array
    {
        $rows = $this->shop->db->rows('SELECT link_rewrite FROM '.$this->shop->db->table('product_lang').' WHERE id_product = '.$this->productId
            .' AND id_shop = '.$this->shop->scope->shopId.' AND id_lang = '.$this->shop->scope->defaultLangId);
        $slug = (string) ($rows[0]['link_rewrite'] ?? '');
        $urls = [];
        foreach ($this->shop->scope->imageTypes as $type) {
            $urls[$type] = $this->shop->urls->url($id, $slug, $type);
        }

        return $urls + ['original' => $this->shop->urls->url($id, $slug, null)];
    }

    /** `…/img/p/1/2/12` of the image, its folder made. */
    private function baseOf(ObjectModel $image): string
    {
        /** @var Image $image */
        $image->createImgFolder();

        // `_PS_PROD_IMG_DIR_` up to 8.x, `_PS_PRODUCT_IMG_DIR_` from 9.0: the same folder.
        return _PS_IMG_DIR_.'p/'.$image->getImgFolder().(int) $image->id;
    }

    /**
     * Alt texts as PrestaShop keeps them (`legend`, at most 128 characters, no `<>={}`).
     *
     * @param  array<int, string>|null  $texts
     * @return array<int, string>|null
     */
    private static function legends(?array $texts): ?array
    {
        if ($texts === null) {
            return null;
        }
        foreach ($texts as $langId => $text) {
            $texts[$langId] = mb_substr(trim((string) preg_replace('/[<>={}]+/u', ' ', $text)), 0, 128, 'UTF-8');
        }

        return $texts;
    }

    private static function bytes(string $limit): int
    {
        $value = (int) $limit;
        $unit = strtolower(substr(trim($limit), -1));

        return $unit === 'g' ? $value << 30 : ($unit === 'm' ? $value << 20 : ($unit === 'k' ? $value << 10 : $value));
    }
}
