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

use Db;
use Image;
use Throwable;

/**
 * `Image::delete()`, finished where PrestaShop 9.0 stops: its `deleteImage()` asks the Symfony container
 * for the configuration, and a module's front controller has none, so it throws once the image's rows,
 * its combination links and its original file are gone. What is left then (the thumbnails, the order
 * of the product's other images) is done here as `Image::delete()` does it.
 */
final class ImageRemoval
{
    private function __construct()
    {
    }

    /** Whether the image is gone (in the shops of the request; another shop may keep it). */
    public static function delete(Image $image): bool
    {
        try {
            return (bool) $image->delete();
        } catch (Throwable $error) {
            $id = (int) $image->id;
            $db = Db::getInstance();
            if ($id === 0 || $db->getValue('SELECT id_image FROM '._DB_PREFIX_.'image WHERE id_image = '.$id, false)) {
                throw $error;
            }
            $db->execute('DELETE FROM '._DB_PREFIX_.'product_attribute_image WHERE id_image = '.$id, false);
            $folder = _PS_IMG_DIR_.'p/'.$image->getImgFolder();
            foreach (array_merge((array) glob($folder.$id.'.*'), (array) glob($folder.$id.'-*')) as $file) {
                if (is_string($file)) {
                    @unlink($file);
                }
            }
            $db->execute('SET @position:=0', false);
            $db->execute('UPDATE '._DB_PREFIX_.'image SET position = (@position:=@position+1) WHERE id_product = '.(int) $image->id_product.' ORDER BY position ASC', false);

            return true;
        }
    }
}
