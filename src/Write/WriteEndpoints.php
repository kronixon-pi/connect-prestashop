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

use Kronixon\Connect\Api\Budget;
use Kronixon\Connect\Api\Request;
use Kronixon\Connect\Api\Response;
use Kronixon\Connect\Api\Router;
use Kronixon\Connect\Protocol;

/**
 * The `POST <entities>` endpoints (README §6.1) and `DELETE images`. The Kernel runs them under the write
 * lock and once per Idempotency-Key; Batch runs their items.
 */
final class WriteEndpoints
{
    /** Endpoints with other limits than 100 items: endpoint => [items, whether none is a request]. */
    private const LIMITS = ['images' => [50, true]];

    /** @var Batch */
    private $batch;

    /** @var callable(Warnings, Item): Shop */
    private $shop;

    /** @var array<string, callable(Shop, Item, Item): Writer> endpoint => writer factory */
    private $writers;

    /**
     * @param  callable(Warnings, Item): Shop  $shop
     * @param  array<string, callable(Shop, Item, Item): Writer>  $writers
     */
    public function __construct(Batch $batch, callable $shop, array $writers)
    {
        $this->batch = $batch;
        $this->shop = $shop;
        $this->writers = $writers;
    }

    /**
     * @param  list<string>  $imageFormats
     * @return array<string, callable(Shop, Item, Item): Writer>
     */
    public static function writers(Download $download, array $imageFormats): array
    {
        return [
            'images' => static function (Shop $shop, Item $options, Item $body) use ($download, $imageFormats): Writer {
                return new ImageWriter($shop, $download, ImageFiles::forShop($imageFormats), $body);
            },
            'categories' => static function (Shop $shop, Item $options): Writer {
                return new CategoryWriter($shop, $options);
            },
            'attributes' => static function (Shop $shop): Writer {
                return new AttributeWriter($shop);
            },
            'attribute-values' => static function (Shop $shop): Writer {
                return new AttributeValueWriter($shop);
            },
            'brands' => static function (Shop $shop) use ($download, $imageFormats): Writer {
                return new BrandWriter($shop, $download, $imageFormats);
            },
            'tags' => static function (Shop $shop): Writer {
                return new TagWriter($shop);
            },
            'products' => static function (Shop $shop, Item $options): Writer {
                return new ProductWriter($shop, $options);
            },
            'variants' => static function (Shop $shop): Writer {
                return new VariantWriter($shop);
            },
            'seo/redirects' => static function (Shop $shop): Writer {
                return new RedirectWriter($shop);
            },
        ];
    }

    public function register(Router $router): Router
    {
        foreach ($this->writers as $endpoint => $factory) {
            [$max, $empty] = self::LIMITS[$endpoint] ?? [Protocol::MAX_BATCH, false];
            $router->add('POST', '/v1/'.$endpoint, function (Request $request, Budget $budget) use ($factory, $max, $empty): Response {
                return $this->batch->run($request, $budget, function (Item $options, Warnings $warnings, Item $body) use ($factory): Writer {
                    return $factory(($this->shop)($warnings, $options), $options, $body);
                }, $max, $empty);
            });
        }
        if (isset($this->writers['images'])) {
            $router->add('DELETE', '/v1/images', function (Request $request, Budget $budget): Response {
                return (new ImageDeleter(($this->shop)(new Warnings(), new Item([]))))->run($request, $budget);
            });
        }

        return $router;
    }
}
