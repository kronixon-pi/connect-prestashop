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
 * Prints one JSON line per reader; exit 1 when a page of an --enforce reader reads more rows per item
 * than its limit (`name:limit`, or --max, default 60). By default products (60), prices (20),
 * variants (20) and `variants?product_id_in` (20: the combinations of 100 products with combinations,
 * from the start and from the middle of the product ids) are enforced; the other readers are measured
 * and printed only.
 * Run inside the shop container as www-data, after catalog-walk.php has generated the products
 * (scripts/ps-smoke.sh does).
 *
 *   php page-cost.php [--endpoints=products,…] [--enforce=products:60,prices,…] [--max=60]
 *
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$options = getopt('', ['endpoints::', 'enforce::', 'max::']);
ini_set('serialize_precision', '-1');
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
// `attribute-values?kind=feature`: an endpoint and its own parameters. attribute-values is measured for
// each kind, variants also with product_id_in (a list made below); expanded before the loop, which
// walks a copy of the array.
$endpoints = [];
foreach (isset($options['endpoints']) ? explode(',', (string) $options['endpoints']) : array_keys($readers) as $endpoint) {
    if ($endpoint === 'attribute-values') {
        array_push($endpoints, 'attribute-values?kind=feature', 'attribute-values?kind=attribute_group');
    } elseif ($endpoint === 'variants' && ! isset($options['endpoints'])) {
        array_push($endpoints, 'variants', 'variants?product_id_in');
    } else {
        $endpoints[] = $endpoint;
    }
}
$enforce = [];
foreach (explode(',', (string) ($options['enforce'] ?? 'products:60,prices:20,variants:20,variants?product_id_in:20')) as $limit) {
    [$name, $most] = explode(':', $limit, 2) + [1 => $options['max'] ?? 60];
    $enforce[$name] = (int) $most;
}
$failures = 0;
foreach ($endpoints as $endpoint) {
    [$name, $extra] = explode('?', $endpoint, 2) + [1 => ''];
    $reader = $readers[$name]($scope);
    $line = ['endpoint' => $endpoint, 'max_id' => $reader->maxId()];
    $pages = ['warm-up' => 0, 'start' => 0, 'middle' => intdiv((int) $line['max_id'], 2)];
    $extras = array_fill_keys(array_keys($pages), $extra);
    if ($extra === 'product_id_in') {
        // One page of the combinations of 100 products with combinations, from the start and from the
        // middle of the product ids.
        $p = _DB_PREFIX_;
        $shopId = (int) $context->shop->id;
        $middle = intdiv((int) $db->getValue("SELECT MAX(id_product) FROM {$p}product", false), 2);
        foreach (['warm-up' => 0, 'start' => 0, 'middle' => $middle] as $at => $from) {
            $ids = array_column($db->executeS("SELECT DISTINCT pa.id_product FROM {$p}product_attribute pa INNER JOIN {$p}product_attribute_shop pas"
                ." ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = $shopId WHERE pa.id_product > $from ORDER BY pa.id_product LIMIT 100", true, false), 'id_product');
            $extras[$at] = 'product_id_in='.implode(',', $ids === [] ? [0] : $ids);
            $pages[$at] = 0;
        }
    }
    foreach ($pages as $at => $after) {
        $query = PageQuery::from(
            Request::fromServer(['REQUEST_METHOD' => 'GET', 'QUERY_STRING' => "path=catalog/$name&{$extras[$at]}&limit=500&cursor=".Cursor::encode($after)], ''),
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
        if (isset($enforce[$endpoint]) && $perItem > $enforce[$endpoint]) {
            $failures++;
            fwrite(STDERR, "FAIL $endpoint page at $at: $rows rows read for $items items (more than {$enforce[$endpoint]} per item)\n");
        }
    }
    echo json_encode($line), "\n";
}
exit($failures === 0 ? 0 : 1);
