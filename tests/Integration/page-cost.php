<?php

declare(strict_types=1);
/**
 * Kronixon Connect for PrestaShop — test tooling, not part of the module zip.
 *
 * The cost of a catalog page on a real shop, in rows MySQL reads: for each reader, one page (limit 500)
 * from the start and one from the middle of its id range, counting the session's Handler_read_* (every
 * row an index or table scan touched) around read(). A keyset page of N items must read a bounded number
 * of rows per item wherever it starts; a plan that reads the whole id range (a join buffer before
 * LIMIT) or every product of a category (a join driven by a small *_shop table) reads thousands.
 * Each reader reads its first page once before measuring: the first read of a process carries
 * PrestaShop's one-time work (on 9.x about 3 000 rows of image type and link lookups), which is not a
 * cost of the page.
 * Prints one JSON line per reader; exit 1 when a page of an --enforce reader (default products) reads
 * more than --max rows per item (default 60). The other readers are measured and printed only.
 * Run inside the shop container as www-data, after catalog-walk.php has generated the products
 * (scripts/ps-smoke.sh does).
 *
 *   php page-cost.php [--endpoints=products,…] [--enforce=products,…] [--max=60]
 *
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$options = getopt('', ['endpoints::', 'enforce::', 'max::']);
$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = getenv('PS_DOMAIN') ?: 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
require $root.'/config/config.inc.php';

use Kronixon\Connect\Api\Request;
use Kronixon\Connect\Catalog\CatalogEndpoints;
use Kronixon\Connect\Catalog\Cursor;
use Kronixon\Connect\Catalog\PageQuery;
use Kronixon\Connect\Catalog\PrestaShopImageUrls;
use Kronixon\Connect\Catalog\PrestaShopScope;
use Kronixon\Connect\Catalog\PrestaShopSql;
use Kronixon\Connect\Compat\Version;

require_once dirname(__DIR__, 2).'/src/Autoloader.php';
Kronixon\Connect\Autoloader::register();

$db = Db::getInstance();
$reads = static function () use ($db): int {
    $total = 0;
    foreach ($db->executeS("SHOW SESSION STATUS LIKE 'Handler_read%'", true, false) as $row) {
        $total += (int) $row['Value'];
    }

    return $total;
};
$context = Context::getContext();
$scope = PrestaShopScope::of($context, Version::select(_PS_VERSION_));
$readers = CatalogEndpoints::readers(new PrestaShopSql($db, _DB_PREFIX_), new PrestaShopImageUrls($context->link));
$endpoints = isset($options['endpoints']) ? explode(',', (string) $options['endpoints']) : array_keys($readers);
$max = (int) ($options['max'] ?? 60);
$enforce = explode(',', (string) ($options['enforce'] ?? 'products'));
$failures = 0;
foreach ($endpoints as $endpoint) {
    // `attribute-values?kind=feature`: the endpoint and its own parameters.
    [$name, $extra] = explode('?', $endpoint, 2) + [1 => ''];
    if ($name === 'attribute-values' && $extra === '') {
        $endpoints[] = "$name?kind=feature";
        $endpoints[] = "$name?kind=attribute_group";
        continue;
    }
    $reader = $readers[$name]($scope);
    $line = ['endpoint' => $endpoint, 'max_id' => $reader->maxId()];
    $pages = ['warm-up' => 0, 'start' => 0, 'middle' => intdiv((int) $line['max_id'], 2)];
    foreach ($pages as $at => $after) {
        $query = PageQuery::from(
            Request::fromServer(['REQUEST_METHOD' => 'GET', 'QUERY_STRING' => "path=catalog/$name&$extra&limit=500&cursor=".Cursor::encode($after)], ''),
            $scope,
            $reader->maxLimit(),
            $reader->maxCursorVariant()
        );
        if ($at === 'warm-up') {
            $reader->read($query);
            continue;
        }
        $before = $reads();
        $items = count($reader->read($query)->items);
        $rows = $reads() - $before - 1;  // less the status query's own read
        $perItem = round($rows / max(1, $items), 1);
        $line[$at] = ['items' => $items, 'rows_read' => $rows, 'per_item' => $perItem];
        if ($perItem > $max && in_array($endpoint, $enforce, true)) {
            $failures++;
            fwrite(STDERR, "FAIL $endpoint page at $at: $rows rows read for $items items (more than $max per item)\n");
        }
    }
    echo json_encode($line), "\n";
}
exit($failures === 0 ? 0 : 1);
