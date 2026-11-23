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

use Configuration;
use ImageManager;
use ImageType;
use Throwable;

/**
 * The files of a product image as PrestaShop makes them (ImageManager): the original as `<id>.jpg` and
 * one file per product image type and format (`<id>-<type>.<format>`, with `2x` when high-DPI images
 * are on). They are made in a work folder on the same disk as the shop's images, before the item's
 * transaction (the slow part), and renamed into place while it is open: each file is complete the
 * moment it appears.
 */
final class ImageFiles
{
    /** What getimagesize() may say of a source (Spec §6.24). */
    private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

    /** The largest picture decoded: 50 megapixels (about 200 MB in GD), whatever `memory_limit` is. */
    public const MAX_PIXELS = 50000000;

    /** @var list<array{name: string, width: int, height: int}> */
    private $types;

    /** @var list<string> */
    private $formats;

    /** @var bool */
    private $highDpi;

    /** @var string the shop's folder of temporary images, on the disk of the product images */
    private $workRoot;

    /**
     * @param  list<array{name: string, width: int, height: int}>  $types
     * @param  list<string>  $formats  formats of the thumbnails (`jpg` first)
     */
    public function __construct(array $types, array $formats, bool $highDpi, string $workRoot)
    {
        $this->types = $types;
        $this->formats = $formats === [] ? ['jpg'] : $formats;
        $this->highDpi = $highDpi;
        $this->workRoot = rtrim($workRoot, '/').'/';
    }

    /**
     * @param  list<string>  $formats
     */
    public static function forShop(array $formats): self
    {
        $types = [];
        foreach (ImageType::getImagesTypes('products') as $type) {
            $types[] = ['name' => stripslashes((string) $type['name']), 'width' => (int) $type['width'], 'height' => (int) $type['height']];
        }

        return new self($types, $formats, (bool) Configuration::get('PS_HIGHT_DPI'), _PS_TMP_IMG_DIR_);
    }

    public function workRoot(): string
    {
        return $this->workRoot;
    }

    /**
     * Whether $file is an image the shop takes: jpg, png, gif or webp of at least 1×1 pixels.
     */
    public static function accepts(string $file): bool
    {
        $info = @getimagesize($file);

        return is_array($info) && in_array($info[2], self::TYPES, true) && $info[0] >= 1 && $info[1] >= 1 && ImageManager::isRealImage($file);
    }

    /**
     * Whether the image's header announces more than MAX_PIXELS, read before anything decodes it: a
     * small file can announce a picture GD needs gigabytes for, and PrestaShop's own memory check
     * (ImageManager::checkImageMemoryLimit()) passes everything when `memory_limit` is -1.
     */
    public static function tooLarge(string $file): bool
    {
        $info = @getimagesize($file);

        return is_array($info) && (float) $info[0] * (float) $info[1] > self::MAX_PIXELS;
    }

    /**
     * The files of $source in a new work folder; the caller drops it.
     *
     * @throws ItemError
     */
    public function make(string $source, string $field): string
    {
        $work = $this->workRoot.'kx-'.bin2hex(random_bytes(8)).'/';
        try {
            // A file GD cannot decode makes ImageManager throw on PHP 8 (a TypeError), not return false.
            if (! @mkdir($work, 0775) || ! ImageManager::resize($source, $work.'original.jpg')) {
                throw new ItemError('image_not_stored', 'PrestaShop could not convert the image', $field);
            }
            foreach ($this->types as $type) {
                foreach ($this->formats as $format) {
                    $made = ImageManager::resize($work.'original.jpg', $work.$type['name'].'.'.$format, $type['width'], $type['height'], $format, $format !== 'jpg');
                    if ($made && $this->highDpi) {
                        $made = ImageManager::resize($work.'original.jpg', $work.$type['name'].'2x.'.$format, $type['width'] * 2, $type['height'] * 2, $format, $format !== 'jpg');
                    }
                    if (! $made) {
                        throw new ItemError('image_not_stored', 'PrestaShop could not make the thumbnail '.$type['name'], $field);
                    }
                }
            }
        } catch (ItemError $error) {
            $this->drop($work);
            throw $error;
        } catch (Throwable $error) {
            $this->drop($work);
            throw new ItemError('image_not_stored', 'PrestaShop could not convert the image', $field);
        }

        return $work;
    }

    /**
     * The work folder's files as those of the image whose path is $base (`…/img/p/1/2/12`), each by one
     * rename; the files of types no longer configured stay as they were.
     *
     * @return list<string> the files placed
     *
     * @throws ItemError
     */
    public function place(string $work, string $base, string $field): array
    {
        $placed = [];
        foreach ((array) scandir($work) as $name) {
            if (! is_string($name) || $name === '.' || $name === '..') {
                continue;
            }
            $target = $name === 'original.jpg' ? $base.'.jpg' : $base.'-'.$name;
            if (! @rename($work.$name, $target)) {
                throw new ItemError('image_not_stored', 'The image file could not be put in place', $field);
            }
            $placed[] = $target;
        }

        return $placed;
    }

    public function drop(?string $work): void
    {
        if ($work === null || ! is_dir($work) || strpos($work, $this->workRoot.'kx-') !== 0) {
            return;
        }
        foreach ((array) scandir($work) as $name) {
            if (is_string($name) && $name !== '.' && $name !== '..') {
                @unlink($work.$name);
            }
        }
        @rmdir($work);
    }

    /** Work folders a request that died left, older than an hour. */
    public function sweep(): void
    {
        foreach ((array) glob($this->workRoot.'kx-*', GLOB_ONLYDIR) as $work) {
            if (is_string($work) && (int) @filemtime($work) < time() - 3600) {
                $this->drop($work.'/');
            }
        }
    }
}
