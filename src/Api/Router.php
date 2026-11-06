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

namespace Kronixon\Connect\Api;

/**
 * Maps (method, logical path) to an endpoint handler. It runs only after the request is
 * authenticated: an unknown path is a 404, a known path with another method a 405.
 * The endpoints are registered by Endpoints::register().
 */
final class Router
{
    /** @var array<string, array<string, callable(Request, Budget): Response>> path => method => handler */
    private $routes = [];

    /**
     * @param  callable(Request, Budget): Response  $handler
     */
    public function add(string $method, string $path, callable $handler): self
    {
        $this->routes[$path][strtoupper($method)] = $handler;

        return $this;
    }

    /**
     * @throws ApiError 404 not_found | 405 method_not_allowed
     */
    public function dispatch(Request $request, Budget $budget): Response
    {
        $handler = $this->resolve($request);

        return $handler($request, $budget);
    }

    /**
     * @return callable(Request, Budget): Response
     *
     * @throws ApiError 404 not_found | 405 method_not_allowed
     */
    public function resolve(Request $request): callable
    {
        $byMethod = $this->routes[$request->path()] ?? null;
        if ($byMethod === null) {
            throw ApiError::notFound();
        }
        $handler = $byMethod[$request->method()] ?? null;
        if ($handler === null) {
            throw ApiError::methodNotAllowed(array_keys($byMethod));
        }

        return $handler;
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_keys($this->routes);
    }
}
