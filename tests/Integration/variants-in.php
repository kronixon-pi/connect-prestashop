<?php

declare(strict_types=1);
/**
 * Kronixon Connect for PrestaShop — test tooling, not part of the module zip.
 *
 * `GET catalog/variants?product_id_in=` on a real shop: for lists of 100 product ids, the pages of
 * product_id_in (followed by next_cursor, limits 7, 100 and 500, so pages also end inside a product)
 * must hold exactly the items of the 100 `product_id=` answers, in product then combination order:
 * same rows, same bytes (sha256 over the JSON of every item). Prints one JSON line per list and limit
 * with the digest, rows, pages, pages that end inside a product, requests saved and the rows MySQL
 * read per item (session Handler_read_*) for both ways. Exit 1 on any difference.
 *
 * Lists: the shop's first 100 products (most without combinations), the first, middle and last 100
 * products with combinations, and a mixed list (combinations, none, ids no product has, a duplicate).
 * Run inside the shop container as www-data, after catalog-walk.php (scripts/ps-smoke.sh does).
 *
 *   php variants-in.php [--limits=7,100,500]
 *
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$options = getopt('', ['limits::']);
ini_set('serialize_precision', '-1');
$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = getenv('PS_DOMAIN') ?: 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
require $root.'/config/config.inc.php';

use Kronixon\Connect\Api\Request;
use Kronixon\Connect\Api\Response;
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
$p = _DB_PREFIX_;
$context = Context::getContext();
$shopId = (int) $context->shop->id;
$scope = PrestaShopScope::of($context, Version::select(_PS_VERSION_));
$reader = CatalogEndpoints::readers(new PrestaShopSql($db, $p), new PrestaShopImageUrls($context->link))['variants']($scope);
$limits = array_map('intval', explode(',', (string) ($options['limits'] ?? '7,100,500')));
$failures = 0;

$column = static function (string $sql) use ($db): array {
    $rows = $db->executeS($sql, true, false);

    return array_map(static function (array $row): int {
        return (int) reset($row);
    }, is_array($rows) ? $rows : []);
};
$reads = static function () use ($db): int {
    $total = 0;
    foreach ($db->executeS("SHOW SESSION STATUS LIKE 'Handler_read%'", true, false) as $row) {
        $total += (int) $row['Value'];
    }

    return $total + 1;  // the status query reads one row itself
};
// Every page of one query by next_cursor: [items as JSON lines, pages, pages ending inside a product, rows read].
$walk = static function (string $params, int $limit) use ($reader, $scope, $reads): array {
    $lines = [];
    $pages = 0;
    $splits = 0;
    $read = 0;
    $cursor = '';
    do {
        $request = Request::fromServer(['REQUEST_METHOD' => 'GET', 'QUERY_STRING' => "path=catalog/variants&$params&limit=$limit".($cursor === '' ? '' : "&cursor=$cursor")], '');
        $query = PageQuery::from($request, $scope, $reader->maxLimit(), $reader->maxCursorVariant($request));
        $before = $reads();
        $page = $reader->read($query);
        $read += $reads() - $before;
        $answer = $page->answer($reader->maxId(), gmdate('Y-m-d\TH:i:s\Z'));
        foreach ($answer['data'] as $item) {
            $lines[] = [(int) $item['product_id'], (int) $item['external_id'], (string) json_encode($item, Response::JSON_FLAGS)];
        }
        $pages++;
        $cursor = (string) $answer['meta']['next_cursor'];
        if ($cursor !== '' && Cursor::decode($cursor)[1] !== null) {
            [$product, $variant] = Cursor::decode($cursor);
            $last = end($lines);
            if ($last === false || $last[0] !== $product || $last[1] !== $variant) {
                throw new RuntimeException("next_cursor $product:$variant is not the last item of its page");
            }
            // The page ends inside a product when the next combination of that product is still to come.
            $splits += (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM '._DB_PREFIX_.'product_attribute_shop WHERE id_product = '.$product
                .' AND id_product_attribute > '.$variant.' AND id_shop = '.(int) Context::getContext()->shop->id, false) > 0 ? 1 : 0;
        }
    } while ($answer['meta']['has_more']);

    return [$lines, $pages, $splits, $read];
};
$digest = static function (array $lines): string {
    return hash('sha256', implode("\n", array_column($lines, 2)));
};

$variable = "SELECT DISTINCT pa.id_product FROM {$p}product_attribute pa INNER JOIN {$p}product_attribute_shop pas ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = $shopId";
$middle = intdiv((int) $db->getValue("SELECT MAX(id_product) FROM {$p}product", false), 2);
$maxProduct = (int) $db->getValue("SELECT MAX(id_product) FROM {$p}product", false);
$lists = [
    'first-100-products' => $column("SELECT id_product FROM {$p}product_shop WHERE id_shop = $shopId ORDER BY id_product LIMIT 100"),
    'variable-start' => $column("$variable ORDER BY pa.id_product LIMIT 100"),
    'variable-middle' => $column("$variable WHERE pa.id_product > $middle ORDER BY pa.id_product LIMIT 100"),
    'variable-end' => $column("$variable ORDER BY pa.id_product DESC LIMIT 100"),
];
$simple = $column("SELECT id_product FROM {$p}product_shop WHERE id_shop = $shopId AND id_product NOT IN ($variable) ORDER BY id_product DESC LIMIT 5");
$mixed = array_merge(array_slice($lists['variable-middle'], 0, 90), $simple, range($maxProduct + 1, $maxProduct + 5));
shuffle($mixed);
$lists['mixed'] = $mixed;

foreach ($lists as $name => $ids) {
    if ($ids === []) {
        fwrite(STDERR, "FAIL $name: the shop has no such products\n");
        $failures++;
        continue;
    }
    $sorted = array_values(array_unique($ids));
    sort($sorted);
    // The 100 product_id= answers, in product order.
    $expected = [];
    $singleRead = 0;
    $singlePages = 0;
    foreach ($sorted as $id) {
        [$lines, $pages, , $read] = $walk("product_id=$id", 500);
        $expected = array_merge($expected, $lines);
        $singleRead += $read;
        $singlePages += $pages;
    }
    $keys = array_map(static function (array $line): string {
        return $line[0].':'.$line[1];
    }, $expected);
    $ordered = $expected;
    usort($ordered, static function (array $a, array $b): int {
        return [$a[0], $a[1]] <=> [$b[0], $b[1]];
    });
    if ($ordered !== $expected) {
        fwrite(STDERR, "FAIL $name: the product_id= answers are not in product, combination order\n");
        $failures++;
    }
    $list = $name === 'mixed' ? implode(',', array_merge($ids, [$ids[0]])) : implode(',', $ids);
    foreach ($limits as $limit) {
        [$lines, $pages, $splits, $read] = $walk("product_id_in=$list", $limit);
        $same = $digest($lines) === $digest($expected);
        if (! $same) {
            $got = array_map(static function (array $line): string {
                return $line[0].':'.$line[1];
            }, $lines);
            $first = array_values(array_diff_assoc($got, $keys))[0] ?? array_values(array_diff_assoc($keys, $got))[0] ?? 'same keys, different bytes';
            fwrite(STDERR, "FAIL $name limit $limit: ".count($lines).' rows, want '.count($expected).", first difference at $first\n");
            $failures++;
        }
        echo json_encode([
            'list' => $name, 'products' => count($sorted), 'limit' => $limit, 'rows' => count($lines), 'pages' => $pages,
            'pages_ending_inside_a_product' => $splits, 'requests_product_id' => $singlePages,
            'sha256' => substr($digest($lines), 0, 16), 'same_as_product_id' => $same,
            'rows_read_per_item' => round($read / max(1, count($lines)), 1),
            'rows_read_per_item_product_id' => round($singleRead / max(1, count($expected)), 1),
        ]), "\n";
    }
}
exit($failures === 0 ? 0 : 1);
