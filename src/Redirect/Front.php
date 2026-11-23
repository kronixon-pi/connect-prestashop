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

namespace Kronixon\Connect\Redirect;

use Context;
use Kronixon\Connect\Catalog\Scope;
use Tools;

/**
 * Serves `kx_redirect` on the storefront, from the hook that builds the routes: one indexed lookup by
 * the request's path when the shop has redirects at all, nothing otherwise.
 */
final class Front
{
    /** @var bool */
    private static $serving = false;

    /** @var RedirectStore */
    private $redirects;

    /** @var Context */
    private $context;

    /** @var Scope */
    private $scope;

    /** @var Pages */
    private $pages;

    public function __construct(RedirectStore $redirects, Context $context, Scope $scope, Pages $pages)
    {
        $this->redirects = $redirects;
        $this->context = $context;
        $this->scope = $scope;
        $this->pages = $pages;
    }

    /**
     * @param  array<string, mixed>  $server  $_SERVER
     */
    public static function concerns(array $server): bool
    {
        return PHP_SAPI !== 'cli' && ! defined('_PS_ADMIN_DIR_') && in_array($server['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true) && isset($server['REQUEST_URI']);
    }

    public function serve(string $requestUri): void
    {
        // Building the target's URL builds the routes again, and with them the hook that calls this.
        if (self::$serving) {
            return;
        }
        self::$serving = true;
        $path = RedirectStore::path($requestUri);
        $row = $path === null ? null : $this->redirects->find($path);
        if ($path === null || $row === null) {
            return;
        }
        // A page the shop has come to show at the path is served; the target's own answers any old slug.
        $page = $this->pages->at($path);
        if ($page !== null && $page !== [$row['target_type'], $row['target_id']]) {
            return;
        }
        if ($row['target_type'] === '') {
            header('HTTP/1.1 410 Gone');
            header('X-Robots-Tag: noindex');
            echo 'Gone';
            exit;
        }
        // A target that is gone or inactive by now would be a 404 or another redirect: the shop's own 404.
        if ($this->pages->active($row['target_type'], $row['target_id']) !== true) {
            return;
        }
        $url = $this->target($row['target_type'], $row['target_id'], $this->languageOf($path));
        if ($url !== '' && RedirectStore::path($url) !== $path) {
            Tools::redirect($url, __PS_BASE_URI__, null, [$row['code'] === 302 ? 'HTTP/1.1 302 Found' : 'HTTP/1.1 301 Moved Permanently']);
        }
    }

    /** The page of an active category is never redirected: what its paths led to goes. */
    public function releaseCategory(int $id): void
    {
        $paths = [];
        foreach (array_keys($this->scope->languages) as $langId) {
            $paths[] = RedirectStore::path($this->target('category', $id, $langId));
        }
        $this->redirects->remove(array_values(array_filter($paths, 'is_string')));
    }

    private function target(string $type, int $id, int $langId): string
    {
        return (string) ($type === 'product'
            ? $this->context->link->getProductLink($id, null, null, null, $langId, $this->scope->shopId)
            : $this->context->link->getCategoryLink($id, null, $langId, null, $this->scope->shopId));
    }

    /** The language of a path like `/bg/…`; the shop's default without one. */
    private function languageOf(string $path): int
    {
        $rest = strpos($path, __PS_BASE_URI__) === 0 ? substr($path, strlen(__PS_BASE_URI__)) : ltrim($path, '/');
        $langId = array_search(strtolower(explode('/', $rest, 2)[0]), $this->scope->languages, true);

        return $langId === false ? $this->scope->defaultLangId : $langId;
    }
}
