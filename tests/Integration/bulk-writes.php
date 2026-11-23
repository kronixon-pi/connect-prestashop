<?php

declare(strict_types=1);
/**
 * Kronixon Connect for PrestaShop — test tooling, not part of the module zip.
 *
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *
 * POST prices/bulk, stock/bulk, maintenance/* and the facets of POST attributes on a real shop
 * (scripts/ps-smoke.sh, inside the shop container as www-data, after pair.php): writes with and without
 * expected_*, conflicts after a change in the back office, repeats, combinations and totals, the
 * visibility policies, ps_facetedsearch's indexes and filters, reindex walks, and 1 000 rows in one
 * request (timed). One line per check; exit 1 on any failure.
 *
 *   php bulk-writes.php <shop base URL> <apache address, e.g. 127.0.0.1:80>
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = getenv('PS_DOMAIN') ?: 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
require $root.'/config/config.inc.php';
require __DIR__.'/SignedClient.php';
require __DIR__.'/shop-helpers.php';

$kx = new SignedClient((string) ($argv[1] ?? ''), (string) ($argv[2] ?? ''));
$db = Db::getInstance();
$p = _DB_PREFIX_;
$helpers = kxShopHelpers($db, $p);
[$value, $text] = [$helpers['value'], $helpers['text']];
Configuration::updateGlobalValue('KX_RATE_LIMIT', '100000');
$shop = (int) Configuration::get('PS_SHOP_DEFAULT');
// The storefront price of the checks below is asked as the back office asks it.
Context::getContext()->employee = new Employee((int) $db->getValue("SELECT MIN(id_employee) FROM {$p}employee WHERE active = 1", false));
$tag = substr(SignedClient::uuid(), 0, 8);
$faceted = $value("SELECT COUNT(*) FROM {$p}module WHERE name = 'ps_facetedsearch' AND active = 1") === 1;
$send = static function (string $endpoint, array $body) use ($kx): array {
    $answer = $kx->post($endpoint, $body);
    if ($answer['status'] !== 200) {
        $kx->check("POST $endpoint answers 200", false, $answer['status'].' '.$answer['raw']);
    }

    return $answer['data'];
};
$rows = static function (array $answer): array {
    $byRow = [];
    foreach ($answer['results'] ?? [] as $result) {
        $byRow[$result['product_id'].':'.$result['variant_id']] = $result;
    }

    return $byRow;
};
$price = static function (int $id) use ($text, $p, $shop): string {
    return $text("SELECT price FROM {$p}product_shop WHERE id_product = $id AND id_shop = $shop");
};
$quantity = static function (int $id, int $variant = 0) use ($value, $p): int {
    return $value("SELECT quantity FROM {$p}stock_available WHERE id_product = $id AND id_product_attribute = $variant");
};

$caps = $kx->get('capabilities')['data']['capabilities'];
$kx->check('capabilities announce prices, stock and maintenance; facets with ps_facetedsearch', $caps['prices.bulk'] && $caps['stock'] && $caps['search_reindex'] && $caps['cache_flush'] && ! $caps['stock_locations'] && $caps['facets'] === $faceted, (string) json_encode($caps));

// Two products of the platform: a simple one and one with two combinations.
$made = $send('products', ['items' => [
    ['key' => 's', 'kx_id' => SignedClient::uuid(), 'groups' => ['content', 'visibility', 'pricing'], 'content' => ['name' => ['en' => "Bulk $tag"]], 'visibility' => ['active' => true], 'pricing' => ['price' => '10'], 'stock_initial' => ['quantity' => 5]],
    ['key' => 'v', 'kx_id' => SignedClient::uuid(), 'type' => 'variable', 'groups' => ['content', 'visibility', 'pricing'], 'content' => ['name' => ['en' => "Bulk V $tag"]], 'visibility' => ['active' => true], 'pricing' => ['price' => '20']],
]]);
[$a, $b] = [(int) ($made['results'][0]['external_id'] ?? 0), (int) ($made['results'][1]['external_id'] ?? 0)];
$w = $send('variants', ['items' => [['key' => 'v', 'product_id' => (string) $b, 'mode' => 'replace', 'option_groups' => [['key' => 'g', 'name' => ['en' => "Size $tag"], 'values' => [['key' => 's', 'name' => ['en' => 'S']], ['key' => 'm', 'name' => ['en' => 'M']]]]],
    'combinations' => [['key' => 'c1', 'values' => ['g:s'], 'default' => true, 'quantity_initial' => 1], ['key' => 'c2', 'values' => ['g:m'], 'quantity_initial' => 2]], ]]]);
[$c1, $c2] = [(int) ($w['results'][0]['combinations']['c1'] ?? 0), (int) ($w['results'][0]['combinations']['c2'] ?? 0)];
$kx->check('setup: two products and two combinations', $a > 0 && $b > 0 && $c1 > 0 && $c2 > 0, (string) json_encode([$made, $w]));

// Prices.
$upd = $text("SELECT date_upd FROM {$p}product_shop WHERE id_product = $a AND id_shop = $shop");
sleep(1);
$r = $rows($send('prices/bulk', ['rows' => [
    ['product_id' => (string) $a, 'variant_id' => '0', 'price' => '12.5', 'wholesale_price' => '6', 'expected_price' => '10.000000'],
    ['product_id' => (string) $b, 'variant_id' => (string) $c1, 'price' => '3', 'expected_price' => '0'],
    ['product_id' => (string) $b, 'variant_id' => '0', 'price' => '20'],
    ['product_id' => '99999999', 'variant_id' => '0', 'price' => '1'],
    ['product_id' => (string) $a, 'variant_id' => (string) $c1, 'price' => '1'],
]]));
$kx->check('prices: written, unchanged and refused rows', $r["$a:0"]['action'] === 'updated' && $r["$a:0"]['previous_price'] === '10.000000' && $r["$b:$c1"]['action'] === 'updated' && $r["$b:0"]['action'] === 'unchanged'
    && $r['99999999:0']['code'] === 'product_not_found' && $r["$a:$c1"]['code'] === 'not_found', (string) json_encode($r));
$read = array_column($kx->get('catalog/prices', ['until_id' => (string) $b, 'cursor' => rtrim(strtr(base64_encode((string) json_encode(['id' => $a - 1])), '+/', '-_'), '=')])['data']['data'] ?? [], null, 'variant_id');
$kx->check('prices: the read shows the price, the impact and the price of the combination', $price($a) === '12.500000' && $text("SELECT wholesale_price FROM {$p}product WHERE id_product = $a") === '6.000000'
    && ($read[(string) $c1]['price_impact'] ?? '') === '3.000000' && ($read[(string) $c1]['price'] ?? '') === '23.000000', (string) json_encode($read));
$kx->check('prices: date_upd of the product moved; the storefront price follows', $text("SELECT date_upd FROM {$p}product_shop WHERE id_product = $a AND id_shop = $shop") !== $upd && abs((float) Product::getPriceStatic($a, false, null, 6, null, false, false) - 12.5) < 0.000001);

// A change in the back office, then the platform's stale belief.
$product = new Product($a);
$product->price = 11.0;
$product->update();
$conflict = $rows($send('prices/bulk', ['rows' => [['product_id' => (string) $a, 'variant_id' => '0', 'price' => '14', 'expected_price' => '12.5']]]));
$kx->check('prices: a price changed in the shop is a conflict with the shop\'s value, nothing written', $conflict["$a:0"]['status'] === 'conflict' && $conflict["$a:0"]['current_price'] === '11.000000' && $conflict["$a:0"]['code'] === 'price_changed' && $price($a) === '11.000000', (string) json_encode($conflict));
$again = [];
for ($i = 0; $i < 2; $i++) {
    $again[] = $rows($send('prices/bulk', ['rows' => [['product_id' => (string) $a, 'variant_id' => '0', 'price' => '13', 'expected_price' => '11']]]))["$a:0"]['action'] ?? '';
}
$kx->check('prices: a repeat is unchanged, not a conflict', $again === ['updated', 'unchanged'] && $price($a) === '13.000000', (string) json_encode($again));

if ($faceted) {
    $deferred = $send('prices/bulk', ['rows' => [['product_id' => (string) $a, 'variant_id' => '0', 'price' => '15']]]);
    $before = $text("SELECT MIN(price_min) FROM {$p}layered_price_index WHERE id_product = $a AND id_shop = $shop");
    $facets = $send('maintenance/facets', ['scope' => 'prices', 'product_ids' => $deferred['facets_pending'] ?? []]);
    $after = (float) $text("SELECT MIN(price_min) FROM {$p}layered_price_index WHERE id_product = $a AND id_shop = $shop");
    $kx->check('facets: deferred lists the product, maintenance/facets indexes its price', ($deferred['facets_pending'] ?? []) === [(string) $a] && $facets['indexed'] === 1 && $facets['continue'] === false && $after >= 15.0 && (float) $before < 15.0, json_encode([$deferred, $facets, $before, $after]));
    $send('prices/bulk', ['options' => ['reindex' => 'inline'], 'rows' => [['product_id' => (string) $a, 'variant_id' => '0', 'price' => '16']]]);
    $kx->check('facets: inline indexes the price with the write', (float) $text("SELECT MIN(price_min) FROM {$p}layered_price_index WHERE id_product = $a AND id_shop = $shop") >= 16.0);
} else {
    echo "  --   ps_facetedsearch is not active: no price index\n";
}

// Stock.
$movements = $value("SELECT COUNT(*) FROM {$p}stock_mvt");
$s = $rows($send('stock/bulk', ['rows' => [
    ['product_id' => (string) $a, 'variant_id' => '0', 'quantity' => 9, 'expected_quantity' => 5, 'location' => 'default'],
    ['product_id' => (string) $b, 'variant_id' => (string) $c1, 'quantity' => 4, 'location' => 'shelf'],
    ['product_id' => (string) $b, 'variant_id' => (string) $c2, 'quantity' => 6],
    ['product_id' => (string) $b, 'variant_id' => '0', 'quantity' => 1],
]]));
$kx->check('stock: safe mode and the product\'s total', $s["$a:0"]['action'] === 'updated' && $s["$a:0"]['previous_quantity'] === 5 && $s["$b:$c1"]['warnings'] === ['location_ignored'] && $s["$b:0"]['code'] === 'validation_failed'
    && $quantity($a) === 9 && $quantity($b, $c1) === 4 && $quantity($b) === 10, (string) json_encode([$s, $quantity($a), $quantity($b, $c1), $quantity($b)]));
// PrestaShop records a movement only where its Symfony container runs (not in a front controller on 9.x).
echo '  --   stock movements recorded by the safe rows: ', $value("SELECT COUNT(*) FROM {$p}stock_mvt") - $movements, "\n";
StockAvailable::setQuantity($a, 0, 3);
$conflict = $rows($send('stock/bulk', ['rows' => [['product_id' => (string) $a, 'variant_id' => '0', 'quantity' => 8, 'expected_quantity' => 9]]]));
$kx->check('stock: a quantity changed in the shop is a conflict, nothing written', $conflict["$a:0"]['status'] === 'conflict' && $conflict["$a:0"]['current_quantity'] === 3 && $quantity($a) === 3, (string) json_encode($conflict));
$fast = $rows($send('stock/bulk', ['options' => ['mode' => 'fast'], 'rows' => [['product_id' => (string) $b, 'variant_id' => (string) $c1, 'quantity' => 1], ['product_id' => (string) $b, 'variant_id' => (string) $c2, 'quantity' => 2]]]));
$kx->check('stock: fast mode writes rows, physical quantity and the total', $fast["$b:$c1"]['action'] === 'updated' && $quantity($b, $c1) === 1 && $quantity($b) === 3
    && $value("SELECT physical_quantity - reserved_quantity FROM {$p}stock_available WHERE id_product = $b AND id_product_attribute = $c2") === 2, (string) json_encode($fast));
$hidden = $rows($send('stock/bulk', ['options' => ['visibility_policy' => 'hide_when_zero'], 'rows' => [['product_id' => (string) $a, 'variant_id' => '0', 'quantity' => 0]]]));
$visibility = $text("SELECT visibility FROM {$p}product_shop WHERE id_product = $a AND id_shop = $shop");
$shown = $rows($send('stock/bulk', ['options' => ['visibility_policy' => 'hide_when_zero'], 'rows' => [['product_id' => (string) $a, 'variant_id' => '0', 'quantity' => 2]]]));
$kx->check('stock: hide_when_zero hides the linked product at 0 and shows it again', $hidden["$a:0"]['warnings'] === ['visibility_hidden'] && $visibility === 'none' && $shown["$a:0"]['warnings'] === ['visibility_restored']
    && $text("SELECT visibility FROM {$p}product_shop WHERE id_product = $a AND id_shop = $shop") === 'both', (string) json_encode([$hidden, $shown]));

// Filters of POST attributes.
$category = (int) ($send('categories', ['items' => [['key' => 'c', 'name' => ['en' => "Filters $tag"]]]])['results'][0]['external_id'] ?? 0);
$feature = $send('attributes', ['items' => [['key' => 'f', 'kind' => 'feature', 'name' => ['en' => "Grip $tag"], 'facets' => ['mode' => 'replace', 'categories' => [['category_id' => (string) $category, 'filter_type' => 1]]]]]])['results'][0] ?? [];
if ($faceted) {
    $fid = (int) ($feature['external_id'] ?? 0);
    $listed = array_values(array_filter($kx->get('catalog/attributes', ['until_id' => (string) $fid, 'cursor' => rtrim(strtr(base64_encode((string) json_encode(['id' => $fid - 1])), '+/', '-_'), '=')])['data']['data'] ?? [], static function (array $item) use ($fid): bool {
        return $item['kind'] === 'feature' && $item['external_id'] === (string) $fid;
    }));
    $kx->check('facets: a filter of the category, in the read', ($feature['status'] ?? '') === 'ok' && $feature['warnings'] === [] && $value("SELECT COUNT(*) FROM {$p}layered_category WHERE id_category = $category AND type = 'id_feature' AND id_value = ".(int) $feature['external_id']." AND filter_type = 1 AND id_shop = $shop") === 1
        && ($listed[0]['facets'][0]['category_id'] ?? '') === (string) $category && $feature['content_hash'] === ($listed[0]['content_hash'] ?? $feature['content_hash']), (string) json_encode([$feature, $listed]));
    $all = $send('maintenance/facets', ['scope' => 'attributes', 'category_ids' => [(string) $category], 'budget_s' => 5]);
    $kx->check('facets: attributes of the categories', $all['continue'] === false && $value("SELECT COUNT(*) FROM {$p}layered_indexable_feature WHERE id_feature = ".(int) $feature['external_id']) === 1, (string) json_encode($all));
} else {
    $kx->check('facets: without ps_facetedsearch a warning, the item written', ($feature['warnings'] ?? []) === ['facets_unsupported']);
    $answer = $kx->post('maintenance/facets', ['scope' => 'all']);
    $kx->check('facets: maintenance/facets is capability_disabled', $answer['status'] === 403 && ($answer['data']['error']['code'] ?? '') === 'capability_disabled', $answer['raw']);
}

// Search reindex: a walk that ends.
$db->execute("UPDATE {$p}product_shop SET indexed = 0 WHERE id_product IN ($a, $b) AND id_shop = $shop");
$walk = [];
$body = ['scope' => 'pending', 'budget_s' => 20];
do {
    $answer = $send('maintenance/reindex', $body);
    $walk[] = [$answer['indexed'] ?? -1, $answer['continue'] ?? null];
    $body['cursor'] = $answer['cursor'] ?? '';
} while (($answer['continue'] ?? false) && count($walk) < 50);
$kx->check('reindex: the pending products are indexed and the walk ends', ! $answer['continue'] && $value("SELECT COUNT(*) FROM {$p}product_shop WHERE id_product IN ($a, $b) AND indexed = 1 AND id_shop = $shop") === 2
    && $value("SELECT COUNT(*) FROM {$p}search_index WHERE id_product = $a") > 0, (string) json_encode($walk));
foreach (['prices', 'products', 'smarty', 'all'] as $scope) {
    $flush = $send('maintenance/cache-flush', ['scope' => $scope]);
    $kx->check("cache-flush $scope", ($flush['cleared'] ?? []) === [$scope], (string) json_encode($flush));
}
$front = curl_init(rtrim((string) ($argv[1] ?? ''), '/').'/index.php?id_product='.$b.'&controller=product');
$parts = parse_url((string) ($argv[1] ?? ''));
curl_setopt_array($front, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECT_TO => [$parts['host'].':'.($parts['port'] ?? 80).':'.(string) ($argv[2] ?? '')]]);
curl_exec($front);
$kx->check('the storefront answers after cache-flush all', (int) curl_getinfo($front, CURLINFO_RESPONSE_CODE) === 200, (string) curl_getinfo($front, CURLINFO_RESPONSE_CODE));

// 1 000 rows in one request: copies of the simple product (test data by SQL, removed at the end).
$n = 1000;
$from = (int) $db->getValue("SELECT MAX(id_product) FROM {$p}product", false) + 1;
$copy = static function (string $table, string $where, array $set) use ($db, $p, $n): void {
    $columns = [];
    foreach ($db->executeS("SELECT column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '$p$table' ORDER BY ordinal_position", true, false) as $row) {
        $columns[] = (string) $row['c'];
    }
    $select = array_map(static function (string $c) use ($set): string {
        return $set[$c] ?? 't.`'.$c.'`';
    }, $columns);
    $db->execute("INSERT INTO `$p$table` (`".implode('`, `', $columns).'`) SELECT '.implode(', ', $select)." FROM `$p$table` t CROSS JOIN seq_1_to_$n s WHERE $where", false);
};
$id = '('.($from - 1).' + s.seq)';
[$template, $templateQuantity] = [$price($a), $quantity($a)];
$copy('product', "t.id_product = $a", ['id_product' => $id]);
$copy('product_shop', "t.id_product = $a", ['id_product' => $id]);
$copy('product_lang', "t.id_product = $a", ['id_product' => $id, 'link_rewrite' => "CONCAT('bulk-', $id)"]);
$copy('stock_available', "t.id_product = $a AND t.id_product_attribute = 0", ['id_stock_available' => 'NULL', 'id_product' => $id]);
$timed = [];
foreach (['prices/bulk' => static function (int $id, int $i) use ($template): array {
    return ['product_id' => (string) $id, 'variant_id' => '0', 'price' => (string) (100 + $i), 'expected_price' => $template];
}, 'stock/bulk' => static function (int $id, int $i) use ($templateQuantity): array {
    return ['product_id' => (string) $id, 'variant_id' => '0', 'quantity' => $i, 'expected_quantity' => $templateQuantity];
}, 'stock/bulk fast' => static function (int $id, int $i): array {
    return ['product_id' => (string) $id, 'variant_id' => '0', 'quantity' => $i + 1];
}] as $endpoint => $row) {
    $batch = [];
    for ($i = 0; $i < $n; $i++) {
        $batch[] = $row($from + $i, $i);
    }
    $t0 = microtime(true);
    $answer = $send(explode(' ', $endpoint)[0], ['rows' => $batch] + ($endpoint === 'stock/bulk fast' ? ['options' => ['mode' => 'fast']] : []));
    $timed[$endpoint] = round(microtime(true) - $t0, 2);
    $kx->check("$endpoint: $n rows in one request in {$timed[$endpoint]} s", ($answer['summary']['ok'] ?? 0) === $n && $answer['continue'] === false && $timed[$endpoint] < ($endpoint === 'stock/bulk' ? 20 : 10), (string) json_encode($answer['summary'] ?? $answer));
}
$kx->check('1 000 rows: the shop holds them', $price($from + 999) === '1099.000000' && $quantity($from + 999) === 1000);
foreach (['product', 'product_shop', 'product_lang', 'stock_available'] as $table) {
    $db->execute("DELETE FROM {$p}$table WHERE id_product >= $from", false);
}
echo 'BULK TIMINGS ', json_encode($timed), "\n";
echo 'BULK WRITES failures=', $kx->failures(), "\n";
exit($kx->failures() === 0 ? 0 : 1);
