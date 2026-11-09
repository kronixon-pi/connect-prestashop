<?php

declare(strict_types=1);
/**
 * Kronixon Connect for PrestaShop — test tooling, not part of the module zip.
 *
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *
 * Walks GET catalog/* on a real shop the way the platform's import does: reads meta.max_id, splits
 * (0, max_id] into K ranges (its IdRange::split()) and reads each range page by page from the cursor
 * {"id":<after>} with until_id, in process (the readers without the HTTP kernel), K = 1, 3 and 8.
 * Checks that every row of the shop comes exactly once; with --mutate a K = 3 walk deletes one product
 * and creates one between pages and checks that every product that lived through it came once and
 * none twice. Prints one JSON line per walk (pages, rows, ms per page p50/p95/max, memory) and writes
 * the first page of each endpoint to --out for schema validation (validate-answers.php).
 *
 *   php catalog-walk.php [--generate=N | --size=N] [--endpoints=products,…] [--limit=500] [--mutate] [--out=DIR]
 *
 * --generate adds N products (--size: as many as the shop lacks to have N) by SQL copies of the shop's own products (1 image and its features
 * each, every 10th with 2 combinations), so a shop of any size can be walked without fixtures.
 * Run inside the shop container as www-data. Exit 1 on any failed check.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$options = getopt('', ['generate::', 'size::', 'endpoints::', 'limit::', 'mutate', 'out::']);
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
$p = _DB_PREFIX_;
$context = Context::getContext();
$shopId = (int) $context->shop->id;
$failures = 0;
$fail = static function (string $message) use (&$failures): void {
    $failures++;
    fwrite(STDERR, "FAIL $message\n");
};
$ids = static function (string $sql) use ($db): array {
    $rows = $db->executeS($sql, true, false);

    return array_map(static function (array $row): string {
        return (string) reset($row);
    }, is_array($rows) ? $rows : []);
};

/*
 * Copies rows of $table: every row matching $where (alias t) once per seq row of seq_1_to_$n (alias s,
 * filtered by $seqWhere), with the column expressions in $set; other columns are copied.
 */
$copy = static function (string $table, string $where, int $n, array $set, string $seqWhere = '1') use ($db, $p): void {
    $columns = [];
    foreach ($db->executeS("SELECT column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '$p$table' ORDER BY ordinal_position", true, false) as $row) {
        $columns[] = (string) $row['c'];
    }
    $select = array_map(static function (string $c) use ($set): string {
        return $set[$c] ?? 't.`'.$c.'`';
    }, $columns);
    $sql = "INSERT INTO `$p$table` (`".implode('`, `', $columns).'`) SELECT '.implode(', ', $select)
        ." FROM `$p$table` t CROSS JOIN seq_1_to_$n s WHERE ($where) AND ($seqWhere)";
    if (! $db->execute($sql, false)) {
        throw new RuntimeException("copy into $table failed: ".$db->getMsgError());
    }
};

if (isset($options['size'])) {
    $options['generate'] = max(0, (int) $options['size'] - (int) $db->getValue("SELECT COUNT(*) FROM {$p}product", false));
}
if (! empty($options['generate'])) {
    $n = (int) $options['generate'];
    $t0 = microtime(true);
    $simple = (int) $db->getValue("SELECT MIN(p.id_product) FROM {$p}product p WHERE p.id_product NOT IN (SELECT id_product FROM {$p}product_attribute) AND p.cache_is_pack = 0 AND p.is_virtual = 0", false);
    $variable = (int) $db->getValue("SELECT MIN(id_product) FROM {$p}product_attribute", false);
    $combos = $ids("SELECT id_product_attribute FROM {$p}product_attribute WHERE id_product = $variable ORDER BY id_product_attribute LIMIT 2");
    $offset = (int) $db->getValue("SELECT MAX(id_product) FROM {$p}product", false);
    $imageOffset = (int) $db->getValue("SELECT MAX(id_image) FROM {$p}image", false);
    $comboOffset = (int) $db->getValue("SELECT MAX(id_product_attribute) FROM {$p}product_attribute", false);
    $id = "($offset + s.seq)";
    $default = "IF(s.seq % 10 = 0, $comboOffset + s.seq DIV 10 * 2 - 1, 0)";
    $template = "(CASE WHEN s.seq % 10 = 0 THEN $variable ELSE $simple END)";
    $db->execute('START TRANSACTION', false);
    $copy('product', "t.id_product = $template", $n, ['id_product' => $id, 'reference' => "CONCAT('KX-', $id)", 'cache_default_attribute' => $default]);
    $copy('product_shop', "t.id_product = $template AND t.id_shop = $shopId", $n, ['id_product' => $id, 'cache_default_attribute' => $default]);
    $copy('product_lang', "t.id_product = $template", $n, ['id_product' => $id, 'name' => "CONCAT(t.name, ' ', $id)", 'link_rewrite' => "CONCAT(t.link_rewrite, '-', $id)"]);
    $copy('category_product', "t.id_product = $template", $n, ['id_product' => $id]);
    $copy('feature_product', "t.id_product = $template", $n, ['id_product' => $id]);
    $copy('product_tag', "t.id_product = $template", $n, ['id_product' => $id]);
    $copy('stock_available', "t.id_product = $template AND t.id_product_attribute = 0", $n, ['id_stock_available' => 'NULL', 'id_product' => $id]);
    $firstImage = (int) $db->getValue("SELECT MIN(id_image) FROM {$p}image", false);
    $copy('image', "t.id_image = $firstImage", $n, ['id_image' => "($imageOffset + s.seq)", 'id_product' => $id, 'position' => '1', 'cover' => '1']);
    $copy('image_shop', "t.id_image = $firstImage AND t.id_shop = $shopId", $n, ['id_image' => "($imageOffset + s.seq)", 'id_product' => $id, 'cover' => '1']);
    $copy('image_lang', "t.id_image = $firstImage", $n, ['id_image' => "($imageOffset + s.seq)"]);
    foreach ($combos as $k => $combo) {
        $newCombo = "($comboOffset + s.seq DIV 10 * 2 - 1 + $k)";
        $copy('product_attribute', "t.id_product_attribute = $combo", $n, ['id_product_attribute' => $newCombo, 'id_product' => $id], 's.seq % 10 = 0');
        $copy('product_attribute_shop', "t.id_product_attribute = $combo", $n, ['id_product_attribute' => $newCombo, 'id_product' => $id], 's.seq % 10 = 0');
        $copy('product_attribute_combination', "t.id_product_attribute = $combo", $n, ['id_product_attribute' => $newCombo], 's.seq % 10 = 0');
        $copy('stock_available', "t.id_product_attribute = $combo", $n, ['id_stock_available' => 'NULL', 'id_product' => $id, 'id_product_attribute' => $newCombo], 's.seq % 10 = 0');
    }
    $db->execute('COMMIT', false);
    echo json_encode(['generated' => $n, 'from_id' => $offset + 1, 'templates' => [$simple, $variable], 'seconds' => round(microtime(true) - $t0, 1)]), "\n";
}

$scope = PrestaShopScope::of($context, Version::select(_PS_VERSION_));
$readers = CatalogEndpoints::readers(new PrestaShopSql(Db::getInstance(_PS_USE_SQL_SLAVE_), $p), new PrestaShopImageUrls($context->link));
$expected = [
    'products' => "SELECT id_product FROM {$p}product_shop WHERE id_shop = $shopId",
    'variants' => "SELECT pa.id_product_attribute FROM {$p}product_attribute pa INNER JOIN {$p}product_attribute_shop pas ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = $shopId",
    'images' => "SELECT i.id_image FROM {$p}image i INNER JOIN {$p}image_shop ish ON ish.id_image = i.id_image AND ish.id_shop = $shopId",
    'prices' => "SELECT CONCAT(id_product, ':0') FROM {$p}product_shop WHERE id_shop = $shopId UNION ALL SELECT CONCAT(pa.id_product, ':', pa.id_product_attribute) FROM {$p}product_attribute pa INNER JOIN {$p}product_attribute_shop pas ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = $shopId",
    'stock' => "SELECT CONCAT(sa.id_product, ':', sa.id_product_attribute) FROM {$p}stock_available sa WHERE ".PrestaShopScope::of($context, Version::select(_PS_VERSION_))->stockWhere('sa'),
];
$byId = static function (array $item): string {
    return $item['external_id'];
};
$byRow = static function (array $item): string {
    return $item['product_id'].':'.$item['variant_id'];
};
$keys = ['products' => $byId, 'variants' => $byId, 'images' => $byId, 'prices' => $byRow, 'stock' => $byRow];
// A row key as a bit: an id, or product:variant (variant 0 → the product's bit, else the combination's).
$slotOf = static function (string $key): array {
    $parts = explode(':', $key);

    return isset($parts[1]) && $parts[1] !== '0' ? ['v', (int) $parts[1]] : ['p', (int) $parts[0]];
};
$mark = static function (string &$bits, int $bit): bool {
    $byte = $bit >> 3;
    if ($byte >= strlen($bits)) {
        $bits .= str_repeat("\0", $byte - strlen($bits) + 1);
    }
    $old = ord($bits[$byte]);
    $bits[$byte] = chr($old | (1 << ($bit & 7)));

    return ($old & (1 << ($bit & 7))) === 0;
};
$isSet = static function (string $bits, int $bit): bool {
    $byte = $bit >> 3;

    return $byte < strlen($bits) && (ord($bits[$byte]) & (1 << ($bit & 7))) !== 0;
};
// until_id bounds the item's own id, or the product id of rows keyed by product and variant.
$rangeOf = static function (string $endpoint, array $item): int {
    return (int) (in_array($endpoint, ['prices', 'stock'], true) ? $item['product_id'] : $item['external_id']);
};
$ranges = static function (int $max, int $k): array {
    $size = intdiv($max, $k) + ($max % $k === 0 ? 0 : 1);
    $result = [];
    for ($after = 0; $after < $max; $after = $until) {
        $until = $max - $after <= $size ? $max : $after + $size;
        $result[] = [$after, $until];
    }

    return $result;
};
$endpoints = isset($options['endpoints']) ? explode(',', (string) $options['endpoints']) : array_keys($expected);
$out = isset($options['out']) ? rtrim((string) $options['out'], '/') : null;
if ($out !== null && ! is_dir($out)) {
    mkdir($out, 0777, true);
}

foreach ($endpoints as $endpoint) {
    $reader = $readers[$endpoint]($scope);
    $limit = min((int) ($options['limit'] ?? 500), $reader->maxLimit());
    $want = $ids($expected[$endpoint]);
    $walks = [1, 3, 8];
    if (isset($options['mutate']) && $endpoint === 'products') {
        $walks[] = 'mutate';
    }
    foreach ($walks as $k) {
        $mutating = $k === 'mutate';
        $maxId = (int) $reader->maxId();
        // Rows seen, as preallocated bitmaps (products or the entity's ids; combination ids), so the
        // walk's own bookkeeping does not grow with the shop and the memory figures are the reader's.
        $bits = ['p' => str_repeat("\0", intdiv($maxId, 8) + 1), 'v' => str_repeat("\0", intdiv((int) $db->getValue("SELECT MAX(id_product_attribute) FROM {$p}product_attribute", false), 8) + 1)];
        $rows = 0;
        $twice = 0;
        $times = [];
        $baseline = [];
        $pageKb = 0;
        $deleted = [];
        $next = (int) $db->getValue("SELECT MAX(id_product) FROM {$p}product", false) + 1;
        gc_collect_cycles();
        $start = memory_get_usage();
        foreach ($ranges($maxId, $mutating ? 3 : (int) $k) as $r => [$after, $until]) {
            $cursor = Cursor::encode($after);
            do {
                $before = memory_get_usage();
                $baseline[] = $before - $start;
                $t = hrtime(true);
                $query = PageQuery::from(Request::fromServer(['REQUEST_METHOD' => 'GET', 'QUERY_STRING' => "path=catalog/$endpoint&limit=$limit&until_id=$until&cursor=$cursor"], ''), $scope, $reader->maxLimit());
                $answer = $reader->read($query)->answer((string) $reader->maxId(), gmdate('Y-m-d\TH:i:s\Z'));
                $json = json_encode($answer, Kronixon\Connect\Api\Response::JSON_FLAGS);
                $times[] = (hrtime(true) - $t) / 1e6;
                if ($json === false) {
                    $fail("$endpoint: an answer does not encode");
                    break 2;
                }
                if ($out !== null && $k === 1 && count($times) === 1) {
                    file_put_contents("$out/catalog-$endpoint.json--walk.json", $json);
                }
                $pageKb = max($pageKb, (int) ((memory_get_usage() - $before) / 1024));
                foreach ($answer['data'] as $item) {
                    [$slot, $bit] = $slotOf($keys[$endpoint]($item));
                    if (! $mark($bits[$slot], $bit)) {
                        $twice++;
                    }
                    $rows++;
                    $id = $rangeOf($endpoint, $item);
                    if ($id <= $after || $id > $until) {
                        $fail("$endpoint K=$k: id $id outside ($after, $until]");
                    }
                }
                $cursor = (string) $answer['meta']['next_cursor'];
                if ($mutating && $answer['meta']['has_more']) {
                    // One product deleted in a range ahead of or behind the walk, one created past max_id.
                    [$a, $u] = $ranges($maxId, 3)[count($times) % 3];
                    $victim = (int) $db->getValue("SELECT MIN(id_product) FROM {$p}product WHERE id_product > ".($a + (count($times) * 7919) % max(1, $u - $a)), false);
                    if ($victim > 0 && $victim <= $u) {
                        foreach (['product', 'product_shop', 'product_lang', 'category_product', 'stock_available'] as $table) {
                            $db->execute("DELETE FROM {$p}$table WHERE id_product = $victim", false);
                        }
                        $deleted[(string) $victim] = true;
                    }
                    $copy('product', 't.id_product = '.(int) $want[0], 1, ['id_product' => (string) $next]);
                    $copy('product_shop', 't.id_product = '.(int) $want[0].' AND t.id_shop = '.$shopId, 1, ['id_product' => (string) $next]);
                    $next++;
                }
                unset($answer, $json, $query);
            } while ($cursor !== '');
        }
        if ($twice > 0) {
            $fail("$endpoint K=$k: $twice rows twice");
        }
        $missing = [];
        foreach ($want as $key) {
            [$slot, $bit] = $slotOf($key);
            if (! isset($deleted[explode(':', $key)[0]]) && ! $isSet($bits[$slot], $bit)) {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            $fail("$endpoint K=$k: ".count($missing).' rows missing, e.g. '.$missing[0]);
        }
        if (! $mutating && $rows !== count($want)) {
            $fail("$endpoint K=$k: read $rows rows, the shop has ".count($want));
        }
        sort($times);
        $pct = static function (float $q) use ($times): float {
            return round($times[(int) min(count($times) - 1, floor($q * count($times)))], 1);
        };
        echo json_encode([
            'endpoint' => $endpoint, 'k' => $k, 'limit' => $limit, 'rows' => $rows, 'pages' => count($times),
            'ms_page_p50' => $pct(0.5), 'ms_page_p95' => $pct(0.95), 'ms_page_max' => round(end($times), 1),
            'seconds' => round(array_sum($times) / 1000, 1),
            // memory before each page relative to the walk's start (flat = nothing kept between pages)
            'base_first_kb' => (int) ($baseline[0] / 1024), 'base_last_kb' => (int) (end($baseline) / 1024),
            'base_max_kb' => (int) (max($baseline) / 1024), 'page_max_kb' => $pageKb,
            'peak_mb' => round(memory_get_peak_usage() / 1048576, 1), 'deleted' => count($deleted),
        ]), "\n";
        if ($mutating) {
            $want = $ids($expected[$endpoint]);
        }
    }
}
exit($failures === 0 ? 0 : 1);
