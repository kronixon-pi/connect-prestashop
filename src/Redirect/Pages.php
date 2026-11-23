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

use Kronixon\Connect\Catalog\Scope;
use Kronixon\Connect\Catalog\Sql;

/**
 * Which page of the paired shop answers at a path, as PrestaShop's own router resolves it: the language
 * prefix, then the first of the dispatcher's routes that matches. A route of an entity is a page only
 * while the entity is there and active; any other route (cart, order, a module's) is one always.
 */
final class Pages
{
    /** route => entity (table) and the parameter of its id */
    private const ENTITIES = [
        'product_rule' => ['product', 'id_product'],
        'category_rule' => ['category', 'id_category'],
        'layered_rule' => ['category', 'id_category'],
        'cms_rule' => ['cms', 'id_cms'],
        'cms_category_rule' => ['cms_category', 'id_cms_category'],
        'manufacturer_rule' => ['manufacturer', 'id_manufacturer'],
        'supplier_rule' => ['supplier', 'id_supplier'],
    ];

    /** Entities whose friendly URL part may stand for the id (a shop's own rule without one). */
    private const BY_SLUG = ['product', 'category', 'cms', 'cms_category'];

    /** @var Sql */
    private $db;

    /** @var Scope */
    private $scope;

    /** @var callable(): array<int, array<string, array<string, mixed>>> */
    private $routes;

    /** @var string */
    private $baseUri;

    /** @var string */
    private $rootDir;

    /**
     * @param  callable(): array<int, array<string, array<string, mixed>>>  $routes  the dispatcher's, by language id and name
     * @param  string  $baseUri  `/` or `/folder/`
     */
    public function __construct(Sql $db, Scope $scope, callable $routes, string $baseUri, string $rootDir)
    {
        $this->db = $db;
        $this->scope = $scope;
        $this->routes = $routes;
        $this->baseUri = $baseUri;
        $this->rootDir = rtrim($rootDir, '/');
    }

    /** Whether the path is inside the shop and names one place only (no `.` and `..`). */
    public function inside(string $path): bool
    {
        return stripos($path, $this->baseUri) === 0 && preg_match('#(\A|/)\.{1,2}(/|\z)#', $path) !== 1;
    }

    /**
     * The entity (table and id) whose page answers at the path, `page` and 0 for any other page of the
     * shop, null when the shop answers 404.
     *
     * @return array{0: string, 1: int}|null
     */
    public function at(string $path): ?array
    {
        if (! $this->inside($path)) {
            return null;
        }
        $uri = '/'.ltrim(substr($path, strlen($this->baseUri)), '/');
        $languages = null;
        if (preg_match('#\A/([a-z]{2})(?:/.*)?\z#s', $uri, $m) === 1) {
            $langId = array_search($m[1], $this->scope->languages, true);
            if ($langId !== false || count($this->scope->languages) > 1) {
                $uri = '/'.ltrim(substr($uri, 3), '/');
                $languages = $langId === false ? null : [$langId];
            }
        }
        // Home, and what the web server answers itself: a file or folder of the installation.
        if ($uri === '/' || $uri === '/index.php' || file_exists($this->rootDir.'/'.explode('/', $uri)[1])) {
            return ['page', 0];
        }

        return $this->routed($uri, $languages);
    }

    /** Whether the entity's page is shown; null when the paired shop does not have the entity. */
    public function active(string $entity, int $id): ?bool
    {
        // PrestaShop keeps the flag of products per shop, of everything else once.
        $flag = $entity === 'product' ? 's.active' : 'e.active';
        $rows = $this->db->rows('SELECT '.$flag.' AS active FROM '.$this->db->table($entity).' e INNER JOIN '.$this->db->table($entity.'_shop')
            .' s ON s.id_'.$entity.' = e.id_'.$entity.' AND s.id_shop = '.$this->scope->shopId.' WHERE e.id_'.$entity.' = '.$id);

        return $rows === [] ? null : (int) $rows[0]['active'] === 1;
    }

    /**
     * @param  list<int>|null  $languages  null: the path does not say which
     * @return array{0: string, 1: int}|null
     */
    private function routed(string $uri, ?array $languages): ?array
    {
        // The dispatcher takes these for static files.
        if (preg_match('/\.(gif|jpe?g|png|css|js|ico)\z/i', $uri) === 1 && strpos($uri, '/upload') !== 0) {
            return null;
        }
        foreach (($this->routes)() as $langId => $routes) {
            if ($languages !== null && ! in_array($langId, $languages, true)) {
                continue;
            }
            foreach ($routes as $name => $route) {
                if (preg_match((string) $route['regexp'], $uri, $m) !== 1) {
                    continue;
                }
                if (! isset(self::ENTITIES[$name])) {
                    return ['page', 0];
                }
                [$entity, $parameter] = self::ENTITIES[$name];
                foreach (isset($m[$parameter]) && $m[$parameter] !== '' ? [(int) $m[$parameter]] : $this->bySlug($entity, $uri) as $id) {
                    if ($this->active($entity, $id) === true) {
                        return [$entity, $id];
                    }
                }
                break;
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function bySlug(string $entity, string $uri): array
    {
        $parts = explode('/', rtrim($uri, '/'));
        $slug = (string) preg_replace('/\.html?\z/i', '', (string) end($parts));
        if (! in_array($entity, self::BY_SLUG, true) || preg_match('/\A[\pL\pN_-]+\z/u', $slug) !== 1) {
            return [];
        }

        // Letters, digits, `_` and `-` only: nothing to escape.
        return array_map(static function (array $row): int {
            return (int) $row['id'];
        }, $this->db->rows('SELECT DISTINCT id_'.$entity.' AS id FROM '.$this->db->table($entity.'_lang')." WHERE link_rewrite = '".$slug."'"));
    }
}
