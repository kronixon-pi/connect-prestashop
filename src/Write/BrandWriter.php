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

use ImageManager;
use ImageType;
use Kronixon\Connect\Catalog\BrandReader;
use Kronixon\Connect\Catalog\Reader;
use Kronixon\Connect\Compat\MetaLengths;
use Manufacturer;

/**
 * `POST brands` (module/brands.json) through `Manufacturer`. A logo is fetched before the item's
 * transaction and put in place after it; the file the shop already holds is not fetched again.
 * `logo: null` removes the logo, an absent `logo` leaves it.
 */
final class BrandWriter extends ShopWriter
{
    public const ENTITY = BrandReader::ENTITY;

    /** @var Download */
    private $download;

    /** @var list<string> image formats the shop serves besides the original */
    private $formats;

    /** @var string|null a fetched logo waiting for its brand */
    private $logo;

    /** @var int */
    private $id = 0;

    /**
     * @param  list<string>  $formats
     */
    public function __construct(Shop $shop, Download $download, array $formats)
    {
        parent::__construct($shop);
        $this->download = $download;
        $this->formats = $formats;
    }

    public function prepare(Item $item): void
    {
        parent::prepare($item);
        $this->logo = null;
        $logo = $item->object('logo');
        if ($logo === null) {
            return;
        }
        $sha256 = (string) $logo->string('sha256');
        if (preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1) {
            throw ItemError::invalid('logo.sha256');
        }
        $id = $this->resolve($item, (string) $item->string('name', 64));
        if ($id !== null && is_file(self::file($id)) && in_array($sha256, [hash_file('sha256', self::file($id)), ImageNote::read(self::file($id))], true)) {
            return;
        }
        $this->logo = $this->download->fetch((string) $logo->string('url'), $sha256, 'logo');
        if (ImageFiles::tooLarge($this->logo)) {
            $this->dropLogo();
            throw new ItemError('image_too_large', 'The logo has more than 50 megapixels', 'logo');
        }
        if (! ImageManager::isRealImage($this->logo)) {
            $this->dropLogo();
            throw new ItemError('image_invalid', 'The logo is not an image', 'logo');
        }
    }

    public function write(Item $item): array
    {
        $name = Texts::oneLine((string) $item->string('name', 64));
        if ($name === '') {
            throw ItemError::invalid('name');
        }
        $id = $this->resolve($item, $name);
        $brand = new Manufacturer($id);
        $fields = $this->shop->fields($brand);
        $lengths = MetaLengths::of('brand');
        $fields->set('name', $name);
        $fields->set('active', $item->bool('active') ?? ($id === null ? true : null));
        $fields->text('description', $this->shop->texts->of($item, 'description'), true);
        $fields->text('short_description', $this->shop->texts->of($item, 'short_description'), true);
        $fields->text('meta_title', $this->shop->texts->meta($item, 'meta_title', $lengths['meta_title']));
        $fields->text('meta_description', $this->shop->texts->meta($item, 'meta_description', $lengths['meta_description']));
        $changed = $fields->changed();
        $fields->save('brand');
        $this->id = (int) $brand->id;
        $this->shop->remember(self::ENTITY, $name, $this->id);
        $this->shop->map->link(self::ENTITY, $this->id, $item->kxId());
        $removes = $item->has('logo') && $item->object('logo') === null && is_file(self::file($this->id));

        return self::done($this->id, $id === null, $changed || $this->logo !== null || $removes);
    }

    public function committed(Item $item): void
    {
        parent::committed($item);
        $file = self::file($this->id);
        if ($this->logo === null) {
            if ($item->has('logo') && $item->object('logo') === null && is_file($file)) {
                // Forced: on multistore PrestaShop keeps the file while any shop has the brand, this one too.
                (new Manufacturer($this->id))->deleteImage(true);
            }

            return;
        }
        $info = getimagesize($this->logo);
        if (is_array($info) && $info[2] === IMAGETYPE_JPEG) {
            $stored = copy($this->logo, $file);
        } else {
            // PrestaShop converts it: the stored file carries the hash of the one it was made of.
            $stored = ImageManager::resize($this->logo, $file) && ImageNote::write($file, (string) hash_file('sha256', $this->logo));
        }
        foreach (ImageType::getImagesTypes('manufacturers') as $type) {
            foreach ($this->formats as $format) {
                $stored = ImageManager::resize($file, _PS_MANU_IMG_DIR_.$this->id.'-'.stripslashes((string) $type['name']).'.'.$format, (int) $type['width'], (int) $type['height'], $format, $format !== 'jpg') && $stored;
            }
        }
        $this->dropLogo();
        if (! $stored) {
            $this->shop->warnings->add('logo_not_stored');
        }
    }

    public function rolledBack(Item $item): bool
    {
        $this->dropLogo();

        return parent::rolledBack($item);
    }

    protected function reader(): Reader
    {
        return new BrandReader($this->shop->db, $this->shop->scope, $this->shop->urls);
    }

    private function resolve(Item $item, string $name): ?int
    {
        return $this->shop->map->resolve($item, self::ENTITY, function (int $id): bool {
            return $this->shop->holds('manufacturer', $id);
        }, function () use ($item, $name): array {
            return self::matches($item, 'name') ? $this->shop->named(self::ENTITY, $name, function (): array {
                return $this->shop->db->rows('SELECT m.id_manufacturer AS id, m.name FROM '.$this->shop->db->table('manufacturer').' m INNER JOIN '
                    .$this->shop->db->table('manufacturer_shop').' s ON s.id_manufacturer = m.id_manufacturer AND s.id_shop = '.$this->shop->scope->shopId.' ORDER BY m.id_manufacturer');
            }) : [];
        });
    }

    private function dropLogo(): void
    {
        if ($this->logo !== null && is_file($this->logo)) {
            unlink($this->logo);
        }
        $this->logo = null;
    }

    private static function file(int $id): string
    {
        return _PS_MANU_IMG_DIR_.$id.'.jpg';
    }
}
