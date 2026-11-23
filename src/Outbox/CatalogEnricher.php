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

namespace Kronixon\Connect\Outbox;

use Kronixon\Connect\Catalog\ImageUrls;
use Kronixon\Connect\Catalog\PageQuery;
use Kronixon\Connect\Catalog\PriceReader;
use Kronixon\Connect\Catalog\ProductReader;
use Kronixon\Connect\Catalog\Rows;
use Kronixon\Connect\Catalog\Scope;
use Kronixon\Connect\Catalog\Sql;

/**
 * The catalog readers of GET catalog/*, so an event's `content_hash` and `price` are what the platform
 * reads when it follows the event.
 */
final class CatalogEnricher implements Enricher
{
    /** @var Sql */
    private $sql;

    /** @var Scope */
    private $scope;

    /** @var ImageUrls */
    private $urls;

    public function __construct(Sql $sql, Scope $scope, ImageUrls $urls)
    {
        $this->sql = $sql;
        $this->scope = $scope;
        $this->urls = $urls;
    }

    public function contentHashes(array $productIds): array
    {
        $hashes = [];
        foreach (array_chunk($productIds, 100) as $ids) {
            foreach ((new ProductReader($this->sql, $this->scope, $this->urls))->read(PageQuery::ofIds($ids))->items as $item) {
                $hashes[(int) $item['external_id']] = (string) $item['content_hash'];
            }
        }

        return $hashes;
    }

    public function prices(array $productIds): array
    {
        $prices = [];
        $reader = new PriceReader($this->sql, $this->scope);
        foreach ($productIds as $id) {
            // The id range of one product: its row and every variant.
            $query = new PageQuery(10000);
            $query->afterId = $id - 1;
            $query->untilId = $id;
            foreach ($reader->read($query)->items as $item) {
                $prices[$item['product_id'].':'.$item['variant_id']] = (string) $item['price'];
            }
        }

        return $prices;
    }

    public function kxIds(string $entityType, array $ids): array
    {
        return Rows::kxIds($this->sql, $entityType, $ids);
    }
}
