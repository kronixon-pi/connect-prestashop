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

use Kronixon\Connect\Api\ApiError;
use Kronixon\Connect\Api\Budget;
use Kronixon\Connect\Api\Request;
use Kronixon\Connect\Api\Response;
use Kronixon\Connect\Api\Router;

/**
 * `POST prices/bulk`, `stock/bulk` and `maintenance/*` (README §6.2). The Kernel runs them under the
 * write lock and once per Idempotency-Key, as every POST; their objects are built per request.
 */
final class BulkEndpoints
{
    /** @var callable(): PriceBulk */
    private $prices;

    /** @var callable(): StockBulk */
    private $stock;

    /** @var callable(): Maintenance */
    private $maintenance;

    /**
     * @param  callable(): PriceBulk  $prices
     * @param  callable(): StockBulk  $stock
     * @param  callable(): Maintenance  $maintenance
     */
    public function __construct(callable $prices, callable $stock, callable $maintenance)
    {
        $this->prices = $prices;
        $this->stock = $stock;
        $this->maintenance = $maintenance;
    }

    public function register(Router $router): Router
    {
        $routes = [
            'prices/bulk' => function (Request $request, Budget $budget): Response {
                return ($this->prices)()->run($request, $budget);
            },
            'stock/bulk' => function (Request $request, Budget $budget): Response {
                return ($this->stock)()->run($request, $budget);
            },
            'maintenance/reindex' => function (Request $request, Budget $budget): Response {
                return ($this->maintenance)()->handle('reindex', $request, $budget);
            },
            'maintenance/cache-flush' => function (Request $request, Budget $budget): Response {
                return ($this->maintenance)()->handle('cache-flush', $request, $budget);
            },
            'maintenance/facets' => function (Request $request, Budget $budget): Response {
                return ($this->maintenance)()->handle('facets', $request, $budget);
            },
        ];
        foreach ($routes as $path => $handler) {
            $router->add('POST', '/v1/'.$path, static function (Request $request, Budget $budget) use ($handler): Response {
                try {
                    return $handler($request, $budget);
                } catch (ItemError $error) {
                    // A member of the body of the wrong type.
                    throw ApiError::validationFailed([$error->field() ?? 'body']);
                }
            });
        }

        return $router;
    }
}
