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

use Kronixon\Connect\Catalog\Reader;
use Kronixon\Connect\Catalog\TagReader;
use Tag;

/**
 * `POST tags` (module/tags.json): tags without products. A tag has nothing to update: it is found by
 * its language and name or created. PrestaShop removes tags no product has whenever a product's tags
 * change, so such a tag lives until then.
 */
final class TagWriter extends ShopWriter
{
    /** `tag.name`. */
    public const LENGTH = 32;

    public function write(Item $item): array
    {
        $langId = $this->shop->texts->languageId((string) $item->string('lang'));
        $name = Texts::oneLine((string) $item->string('name', self::LENGTH));
        if ($name === '' || ! $item->has('lang')) {
            throw ItemError::invalid($name === '' ? 'name' : 'lang');
        }
        if ($langId === null) {
            throw new ItemError('language_unknown', 'The shop has no such language', 'lang');
        }
        $tag = new Tag(null, $name, $langId);
        $id = (int) $tag->id;
        if ($id === 0) {
            $tag->name = $name;
            $tag->id_lang = $langId;
            if (! $tag->add()) {
                throw ItemError::notSaved('tag');
            }
        }

        return self::done((int) $tag->id, $id === 0, false);
    }

    protected function reader(): Reader
    {
        return new TagReader($this->shop->db, $this->shop->scope);
    }
}
