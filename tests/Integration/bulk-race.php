<?php

declare(strict_types=1);

/**
 * POST prices/bulk and stock/bulk while another database session writes the same rows (an order, a
 * back-office save), on a real shop: a second connection holds an uncommitted change, the request
 * starts, the change commits while the module waits for the row's lock. With `expected_*` the row
 * is `conflict` with the committed value and nothing is written; without it the platform's value
 * lands after the change. A row held longer than the module's wait is `skipped` (`rows_locked`).
 * Then 1 000 rows per request while a probe session updates one row in a loop, as a checkout does:
 * its longest wait is the longest lock a chunk holds. One line per check; exit 1 on any failure.
 *
 *   php bulk-race.php <shop base URL> <apache address, e.g. 127.0.0.1:80>
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

ini_set('serialize_precision', '-1');
$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = getenv('PS_DOMAIN') ?: 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
require $root.'/config/config.inc.php';
require __DIR__.'/SignedClient.php';

$kx = new SignedClient((string) ($argv[1] ?? ''), (string) ($argv[2] ?? ''));
if (($argv[3] ?? '') === 'send') {
    // The request of a case, in a process of its own while the parent holds or probes rows.
    $t0 = microtime(true);
    $answer = $kx->post((string) $argv[4], (array) json_decode((string) $argv[5], true));
    // Short: the parent reads the pipe only once this process ends (a pipe holds 64 kB).
    $data = $answer['data'];
    echo json_encode(['status' => $answer['status'], 'data' => ['results' => array_slice($data['results'] ?? [], 0, 1), 'summary' => $data['summary'] ?? null, 'continue' => $data['continue'] ?? null], 'seconds' => round(microtime(true) - $t0, 3)]);
    exit(0);
}
$db = Db::getInstance();
$p = _DB_PREFIX_;
Configuration::updateGlobalValue('KX_RATE_LIMIT', '100000');
$shop = (int) Configuration::get('PS_SHOP_DEFAULT');
$other = static function (): PDO {
    return new PDO('mysql:host='._DB_SERVER_.';dbname='._DB_NAME_, _DB_USER_, _DB_PASSWD_, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
};
$start = static function (string $endpoint, array $body) use ($argv): array {
    $process = proc_open([PHP_BINARY, __FILE__, (string) $argv[1], (string) $argv[2], 'send', $endpoint, (string) json_encode($body)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('proc_open failed');
    }

    return [$process, $pipes];
};
$finish = static function (array $started): array {
    [$process, $pipes] = $started;
    $out = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
    proc_close($process);
    $answer = json_decode($out, true);

    return is_array($answer) ? $answer : ['status' => 0, 'data' => [], 'raw' => $out];
};
$state = static function (int $id) use ($db, $p, $shop): array {
    return [(int) $db->getValue("SELECT quantity FROM {$p}stock_available WHERE id_product = $id AND id_product_attribute = 0", false),
        (string) $db->getValue("SELECT price FROM {$p}product_shop WHERE id_product = $id AND id_shop = $shop", false)];
};

$tag = substr(SignedClient::uuid(), 0, 8);
$made = $kx->post('products', ['items' => [['key' => 'race', 'kx_id' => SignedClient::uuid(), 'groups' => ['content', 'visibility', 'pricing'], 'content' => ['name' => ['en' => "Race $tag"]], 'visibility' => ['active' => true], 'pricing' => ['price' => '10'], 'stock_initial' => ['quantity' => 5]]]]);
$id = (int) ($made['data']['results'][0]['external_id'] ?? 0);
$kx->check('a product to race on', $id > 0 && $state($id) === [5, '10.000000'], (string) json_encode($state($id)));

$order = "UPDATE {$p}stock_available SET quantity = quantity - 1, physical_quantity = physical_quantity - 1 WHERE id_product = $id AND id_product_attribute = 0";
$table = [];
// [name, endpoint, body, the other session's change, set before, expected status, current/previous, shop after]
foreach ([
    ['stock safe, expected 5', 'stock/bulk', ['options' => ['mode' => 'safe'], 'rows' => [['product_id' => (string) $id, 'variant_id' => '0', 'quantity' => 8, 'expected_quantity' => 5]]], $order, 5, 'conflict', 4, 4],
    ['stock safe, no expected', 'stock/bulk', ['options' => ['mode' => 'safe'], 'rows' => [['product_id' => (string) $id, 'variant_id' => '0', 'quantity' => 8]]], $order, 5, 'ok', 4, 8],
    ['stock fast, expected 5', 'stock/bulk', ['options' => ['mode' => 'fast'], 'rows' => [['product_id' => (string) $id, 'variant_id' => '0', 'quantity' => 9, 'expected_quantity' => 5]]], $order, 5, 'conflict', 4, 4],
    ['stock fast, no expected', 'stock/bulk', ['options' => ['mode' => 'fast'], 'rows' => [['product_id' => (string) $id, 'variant_id' => '0', 'quantity' => 9]]], $order, 5, 'ok', 4, 9],
    ['price, expected 10', 'prices/bulk', ['rows' => [['product_id' => (string) $id, 'variant_id' => '0', 'price' => '12', 'expected_price' => '10']]], "UPDATE {$p}product_shop SET price = 11 WHERE id_product = $id", 10, 'conflict', '11.000000', '11.000000'],
    ['price, no expected', 'prices/bulk', ['rows' => [['product_id' => (string) $id, 'variant_id' => '0', 'price' => '12']]], "UPDATE {$p}product_shop SET price = 11 WHERE id_product = $id", 10, 'ok', '11.000000', '12.000000'],
] as [$name, $endpoint, $body, $change, $before, $status, $seen, $after]) {
    $db->execute("UPDATE {$p}stock_available SET quantity = 5, physical_quantity = 5 + reserved_quantity WHERE id_product = $id AND id_product_attribute = 0", false);
    $db->execute("UPDATE {$p}product_shop SET price = 10 WHERE id_product = $id", false);
    $db->execute("UPDATE {$p}product SET price = 10 WHERE id_product = $id", false);
    $session = $other();
    $session->beginTransaction();
    $session->exec($change);  // not committed yet: the row is held
    $request = $start($endpoint, $body);
    usleep(1500000);
    $session->commit();  // while the module waits for the row
    $answer = $finish($request);
    $result = $answer['data']['results'][0] ?? [];
    $isStock = $endpoint === 'stock/bulk';
    $value = $result[$status === 'conflict' ? ($isStock ? 'current_quantity' : 'current_price') : ($isStock ? 'previous_quantity' : 'previous_price')] ?? null;
    $now = $state($id)[$isStock ? 0 : 1];
    $table[] = [$name, $before, ($result['status'] ?? '?').'/'.($result['action'] ?? $result['code'] ?? '?'), $value, $now, $answer['seconds'] ?? null];
    $kx->check("$name: the other session commits while the module waits → $status with $seen, shop $after", ($result['status'] ?? null) === $status && $value === $seen && $now === $after && ($answer['seconds'] ?? 0) >= 1.0, (string) json_encode([$result, $now, $answer['seconds'] ?? null]));
}

// A row held longer than the module waits: skipped, nothing written, continue.
$db->execute("UPDATE {$p}stock_available SET quantity = 5 WHERE id_product = $id AND id_product_attribute = 0", false);
$session = $other();
$session->beginTransaction();
$session->exec($order);
$request = $start('stock/bulk', ['rows' => [['product_id' => (string) $id, 'variant_id' => '0', 'quantity' => 7]]]);
$answer = $finish($request);
$session->rollBack();
$result = $answer['data']['results'][0] ?? [];
$kx->check('a row held longer than the wait (10 s): skipped/rows_locked with continue, nothing written', ($result['code'] ?? null) === 'rows_locked' && ($answer['data']['continue'] ?? null) === true && $state($id)[0] === 5 && ($answer['seconds'] ?? 0) < 13, (string) json_encode([$result, $answer['seconds'] ?? null, $state($id)]));
$table[] = ['stock safe, row held past the wait', 5, ($result['status'] ?? '?').'/'.($result['code'] ?? '?'), null, $state($id)[0], $answer['seconds'] ?? null];

// 1 000 rows per request while a checkout updates a row of the sixth chunk every 5 ms: the longest
// wait of its statements is the longest lock of a chunk.
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
$copyId = '('.($from - 1).' + s.seq)';
$copy('product', "t.id_product = $id", ['id_product' => $copyId]);
$copy('product_shop', "t.id_product = $id", ['id_product' => $copyId]);
$copy('product_lang', "t.id_product = $id", ['id_product' => $copyId, 'link_rewrite' => "CONCAT('race-', $copyId)"]);
$copy('stock_available', "t.id_product = $id AND t.id_product_attribute = 0", ['id_stock_available' => 'NULL', 'id_product' => $copyId]);
$probed = $from + 550;
$holds = [];
foreach ([
    'prices/bulk' => ["UPDATE {$p}product_shop SET price = price WHERE id_product = $probed AND id_shop = $shop", static function (int $id, int $i): array {
        return ['product_id' => (string) $id, 'variant_id' => '0', 'price' => (string) (100 + $i)];
    }],
    'stock/bulk safe' => ["UPDATE {$p}stock_available SET quantity = quantity WHERE id_product = $probed AND id_product_attribute = 0", static function (int $id, int $i): array {
        return ['product_id' => (string) $id, 'variant_id' => '0', 'quantity' => $i];
    }],
    'stock/bulk fast' => ["UPDATE {$p}stock_available SET quantity = quantity WHERE id_product = $probed AND id_product_attribute = 0", static function (int $id, int $i): array {
        return ['product_id' => (string) $id, 'variant_id' => '0', 'quantity' => $i + 1];
    }],
] as $endpoint => [$probe, $row]) {
    $batch = [];
    for ($i = 0; $i < $n; $i++) {
        $batch[] = $row($from + $i, $i);
    }
    $session = $other();
    $request = $start(explode(' ', $endpoint)[0], ['rows' => $batch] + (strpos($endpoint, 'fast') !== false ? ['options' => ['mode' => 'fast']] : []));
    $longest = 0.0;
    $probes = 0;
    do {
        $t0 = microtime(true);
        $session->exec($probe);
        $longest = max($longest, microtime(true) - $t0);
        $probes++;
        usleep(5000);
        $running = proc_get_status($request[0])['running'];
    } while ($running);
    $answer = $finish($request);
    $holds[$endpoint] = ['request_s' => $answer['seconds'] ?? null, 'chunk_avg_s' => round((float) ($answer['seconds'] ?? 0) / 10, 3), 'checkout_longest_wait_s' => round($longest, 3), 'probes' => $probes];
    $kx->check("$endpoint: $n rows while a checkout writes a row of chunk 6, longest wait {$holds[$endpoint]['checkout_longest_wait_s']} s", ($answer['data']['summary']['ok'] ?? 0) === $n && $longest < 2.0, (string) json_encode([$answer['data']['summary'] ?? $answer, $holds[$endpoint]]));
}
foreach (['product', 'product_shop', 'product_lang', 'stock_available'] as $copied) {
    $db->execute("DELETE FROM {$p}$copied WHERE id_product >= $from", false);
}

echo 'RACE TABLE ', json_encode($table), "\n";
echo 'LOCK HOLDS ', json_encode($holds), "\n";
echo 'BULK RACE failures=', $kx->failures(), "\n";
exit($kx->failures() === 0 ? 0 : 1);
