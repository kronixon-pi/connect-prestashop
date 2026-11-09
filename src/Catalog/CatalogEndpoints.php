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

namespace Kronixon\Connect\Catalog;

use Kronixon\Connect\Api\Request;
use Kronixon\Connect\Api\Response;
use Kronixon\Connect\Api\Router;
use Kronixon\Connect\Support\Clock;

/**
 * The `GET catalog/*` endpoints (README §6): read-only, behind the Kernel's signature check like every
 * endpoint. The shop scope is built only when a catalog endpoint is called.
 */
final class CatalogEndpoints
{
    /** @var callable(): Scope */
    private $scope;

    /** @var array<string, callable(Scope): Reader> endpoint => reader factory */
    private $readers;

    /** @var Clock */
    private $clock;

    /**
     * @param  callable(): Scope  $scope
     * @param  array<string, callable(Scope): Reader>  $readers  `products` => factory, …
     */
    public function __construct(callable $scope, array $readers, Clock $clock)
    {
        $this->scope = $scope;
        $this->readers = $readers;
        $this->clock = $clock;
    }

    /**
     * The readers of the module (`Sql` and `ImageUrls` of the shop).
     *
     * @return array<string, callable(Scope): Reader>
     */
    public static function readers(Sql $sql, ImageUrls $urls): array
    {
        return [
            'products' => static function (Scope $scope) use ($sql, $urls): Reader {
                return new ProductReader($sql, $scope, $urls);
            },
            'variants' => static function (Scope $scope) use ($sql): Reader {
                return new VariantReader($sql, $scope);
            },
            'images' => static function (Scope $scope) use ($sql, $urls): Reader {
                return new ImageReader($sql, $scope, $urls);
            },
            'prices' => static function (Scope $scope) use ($sql): Reader {
                return new PriceReader($sql, $scope);
            },
            'stock' => static function (Scope $scope) use ($sql): Reader {
                return new StockReader($sql, $scope);
            },
        ];
    }

    public function register(Router $router): Router
    {
        foreach ($this->readers as $endpoint => $factory) {
            $router->add('GET', '/v1/catalog/'.$endpoint, function (Request $request) use ($factory): Response {
                $scope = ($this->scope)();
                $reader = $factory($scope);
                $page = $reader->read(PageQuery::from($request, $scope, $reader->maxLimit(), $reader->maxCursorVariant()));

                return Response::json(200, $page->answer($reader->maxId(), gmdate('Y-m-d\TH:i:s\Z', (int) floor($this->clock->now()))));
            });
        }

        return $router;
    }
}
