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

use Kronixon\Connect\Catalog\ContentHash;
use Kronixon\Connect\Catalog\PageQuery;
use Kronixon\Connect\Catalog\Reader;

/**
 * The `content_hash` of entities just written, read back through the `GET catalog/*` reader: the item's
 * own `content_hash` where the read carries one (products, categories), else the hash of the read item
 * without the members that move without the entity changing, the same rule the platform applies to a
 * read that carries none.
 */
final class ItemHashes
{
    /** Members of a read that are no content: the link to the platform, counters, dates, the hash. */
    private const NOT_CONTENT = ['kx_id' => 0, 'date_upd' => 0, 'product_count' => 0, 'value_count' => 0, 'content_hash' => 0, 'facets' => 0];

    private function __construct()
    {
    }

    /**
     * @param  list<int>  $ids
     * @param  array<string, string>  $params  the reader's own parameters
     * @return array<string, string> external id => hash
     */
    public static function read(Reader $reader, array $ids, array $params = [], ?string $kind = null): array
    {
        $hashes = [];
        $ids = array_values(array_unique($ids));
        while ($ids !== []) {
            $items = $reader->read(PageQuery::ofIds($ids, $params))->items;
            foreach ($items as $item) {
                if ($kind === null || ($item['kind'] ?? null) === $kind) {
                    $hashes[(string) $item['external_id']] = $item['content_hash'] ?? ContentHash::of(array_diff_key($item, self::NOT_CONTENT));
                }
            }
            // A page stops at 8 MB: the rest of the ids is another page.
            $read = array_map('intval', array_column($items, 'external_id'));
            $ids = $read === [] ? [] : array_values(array_diff($ids, $read));
        }

        return $hashes;
    }
}
