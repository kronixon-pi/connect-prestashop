<?php

declare(strict_types=1);

/**
 * POST prices/bulk and stock/bulk while another database session writes the same rows (an order, a
 * back-office save), on a real shop: a second connection holds an uncommitted change, the request
 * starts, the change commits while the module waits for the row's lock. With `expected_*` the row
 * is `conflict` with the committed value and nothing is written; without it the platform's value
 * lands after the change. A row held longer than the module's wait is `skipped` (`rows_locked`).
 * On a small shop (about 434 stock rows, where a read by `id_product` is planned as a scan) a chunk
 * locks only its own rows: another product's row stays free while the chunk waits. PrestaShop's own
 * end-of-order statement (StockManager::updatePhysicalProductQuantity()) for an order whose lines
 * run against the chunk's lock order succeeds and the chunk is written (9.x: the chunk loses the
 * deadlock and runs once more). Then 1 000 rows per request while that statement runs in a loop for
 * an order of a product in chunk 6: its longest wait is what a checkout waits. Last, the session's
 * lock wait is its own again after a chunk's commit or rollback. One line per check; exit 1 on any
 * failure.
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
    echo json_encode(['status' => $answer['status'], 'data' => ['results' => array_slice($data['results'] ?? [], 0, 2), 'summary' => $data['summary'] ?? null, 'continue' => $data['continue'] ?? null], 'seconds' => round(microtime(true) - $t0, 3)]);
    exit(0);
}
$orderStatement = static function (int $idOrder): bool {
    // The last stock statements of PaymentModule::validateOrder(), autocommit, as a checkout runs
    // them; false when the server refuses one (a deadlock's victim), which PrestaShop does not repeat.
    try {
        return (bool) (new PrestaShop\PrestaShop\Adapter\StockManager())->updatePhysicalProductQuantity(
            (int) Configuration::get('PS_SHOP_DEFAULT'),
            (int) Configuration::get('PS_OS_ERROR'),
            (int) Configuration::get('PS_OS_CANCELED'),
            null,
            $idOrder
        );
    } catch (Throwable $error) {
        return false;
    }
};
if (($argv[3] ?? '') === 'order') {
    $t0 = microtime(true);
    $ok = $orderStatement((int) $argv[4]);
    echo json_encode(['ok' => $ok, 'errno' => Db::getInstance()->getNumberError(), 'seconds' => round(microtime(true) - $t0, 3)]);
    exit(0);
}
$db = Db::getInstance();
$p = _DB_PREFIX_;
Configuration::updateGlobalValue('KX_RATE_LIMIT', '100000');
$shop = (int) Configuration::get('PS_SHOP_DEFAULT');
$other = static function (): PDO {
    return new PDO('mysql:host='._DB_SERVER_.';dbname='._DB_NAME_, _DB_USER_, _DB_PASSWD_, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
};
$child = static function (array $args) use ($argv): array {
    $process = proc_open(array_merge([PHP_BINARY, __FILE__, (string) $argv[1], (string) $argv[2]], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('proc_open failed');
    }

    return [$process, $pipes];
};
$start = static function (string $endpoint, array $body) use ($child): array {
    return $child(['send', $endpoint, (string) json_encode($body)]);
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

// Copies of the race product (catalog rows and stock row) and an order of some of them.
$copies = static function (int $count) use ($db, $p, $id): int {
    $from = (int) $db->getValue("SELECT MAX(id_product) FROM {$p}product", false) + 1;
    $copyId = '('.($from - 1).' + s.seq)';
    foreach (['product' => [], 'product_shop' => [], 'product_lang' => ['link_rewrite' => "CONCAT('race-', $copyId)"], 'stock_available' => ['id_stock_available' => 'NULL']] as $table => $set) {
        $set['id_product'] = $copyId;
        $columns = [];
        foreach ($db->executeS("SELECT column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '$p$table' ORDER BY ordinal_position", true, false) as $row) {
            $columns[] = (string) $row['c'];
        }
        $select = array_map(static function (string $c) use ($set): string {
            return $set[$c] ?? 't.`'.$c.'`';
        }, $columns);
        $where = "t.id_product = $id".($table === 'stock_available' ? ' AND t.id_product_attribute = 0' : '');
        $db->execute("INSERT INTO `$p$table` (`".implode('`, `', $columns).'`) SELECT '.implode(', ', $select)." FROM `$p$table` t CROSS JOIN seq_1_to_$count s WHERE $where", false);
    }
    // Statistics of the shop as it now is: the plans of a small shop or of a large one.
    $db->execute("ANALYZE TABLE {$p}stock_available", false);

    return $from;
};
$dropCopies = static function (int $from) use ($db, $p): void {
    foreach (['product', 'product_shop', 'product_lang', 'stock_available'] as $copied) {
        $db->execute("DELETE FROM {$p}$copied WHERE id_product >= $from", false);
    }
};
$orderOf = static function (array $products) use ($db, $p): int {
    // Order lines only: what the end-of-order statement reads to find the order's stock rows.
    $idOrder = 900000000 + random_int(1, 99999999);
    // getRow() adds LIMIT 1 itself (8.x and 9.0 append it even to a statement that has one).
    $template = $db->getRow("SELECT * FROM {$p}order_detail ORDER BY id_order_detail DESC", false);
    foreach ($products as $product) {
        $line = $template;
        unset($line['id_order_detail']);
        $line['id_order'] = $idOrder;
        $line['product_id'] = $product;
        $line['product_attribute_id'] = 0;
        $db->insert('order_detail', array_map('pSQL', $line));
    }

    return $idOrder;
};
$byStockId = static function (int $first, int $last) use ($db, $p): array {
    return array_map('intval', array_column($db->executeS("SELECT id_product FROM {$p}stock_available WHERE id_product BETWEEN $first AND $last AND id_product_attribute = 0 ORDER BY id_stock_available", true, false), 'id_product'));
};
$logged = static function () use ($db, $p): int {
    return (int) $db->getValue("SELECT COUNT(*) FROM {$p}kx_log WHERE message = 'A bulk chunk lost a deadlock and runs again'", false);
};

// A small shop (about 434 stock rows: a locking read by id_product is planned as a scan of the table
// and locks every row). Another session holds the chunk's row with the highest id: the chunk waits for
// it holding its other rows, and only them.
$count = max(200, 434 - (int) $db->getValue("SELECT COUNT(*) FROM {$p}stock_available", false));
$from = $copies($count);
$chunk = $byStockId($from, $from + 99);
$session = $other();
$session->beginTransaction();
$session->exec("UPDATE {$p}stock_available SET quantity = quantity WHERE id_product = ".$chunk[99].' AND id_product_attribute = 0');
$batch = [];
foreach ($chunk as $i => $product) {
    $batch[] = ['product_id' => (string) $product, 'variant_id' => '0', 'quantity' => 20 + $i];
}
$request = $start('stock/bulk', ['rows' => $batch]);
usleep(800000);
$probe = $other();
$probe->exec('SET SESSION innodb_lock_wait_timeout = 1');
$free = [];
foreach (['race product, before the chunk' => $id, 'a copy after the chunk' => $from + $count - 1, 'first row of the chunk' => $chunk[0]] as $name => $product) {
    try {
        $probe->exec("UPDATE {$p}stock_available SET quantity = quantity WHERE id_product = $product AND id_product_attribute = 0");
        $free[$name] = 'free';
    } catch (PDOException $e) {
        $free[$name] = 'locked';
    }
}
$session->commit();
$answer = $finish($request);
$small = ['stock_rows' => (int) $db->getValue("SELECT COUNT(*) FROM {$p}stock_available", false)] + $free;
$kx->check('a small shop ('.$small['stock_rows'].' stock rows): while a chunk waits, another product\'s row is free and its own first row is locked', $free === ['race product, before the chunk' => 'free', 'a copy after the chunk' => 'free', 'first row of the chunk' => 'locked'] && ($answer['data']['summary']['ok'] ?? 0) === 100, (string) json_encode([$free, $answer['data']['summary'] ?? $answer]));
$dropCopies($from);

// An order whose lines run against the chunk's lock order: 9.x updates the order's stock rows in the
// order of its lines (B, then A), the chunk locks by id (A, then B). A third session holds A, so the
// chunk waits for A while the order's statement takes B and waits for A; then A is released.
$from = $copies(2);
[$a, $b] = $byStockId($from, $from + 1);
$idOrder = $orderOf([$b, $a]);
$before = $logged();
$session = $other();
$session->beginTransaction();
$session->exec("UPDATE {$p}stock_available SET quantity = quantity WHERE id_product = $a AND id_product_attribute = 0");
$request = $start('stock/bulk', ['rows' => [['product_id' => (string) $a, 'variant_id' => '0', 'quantity' => 41], ['product_id' => (string) $b, 'variant_id' => '0', 'quantity' => 42]]]);
usleep(700000);
$orderRun = $child(['order', (string) $idOrder]);
usleep(700000);
$session->commit();
$orderAnswer = $finish($orderRun);
$answer = $finish($request);
$results = $answer['data']['results'] ?? [];
$deadlock = ['order_statement' => $orderAnswer, 'chunk' => array_map(static function (array $r): string {
    return ($r['status'] ?? '?').'/'.($r['action'] ?? $r['code'] ?? '?');
}, $results), 'runs_again' => $logged() - $before, 'stock' => [$state($a)[0], $state($b)[0]], 'seconds' => $answer['seconds'] ?? null];
$kx->check('an order whose lines run against the chunk\'s lock order: its statement succeeds and the chunk is written (runs again: '.$deadlock['runs_again'].')', ($orderAnswer['ok'] ?? null) === true && $deadlock['chunk'] === ['ok/updated', 'ok/updated'] && $deadlock['stock'] === [41, 42], (string) json_encode($deadlock));
$db->execute("DELETE FROM {$p}order_detail WHERE id_order = $idOrder", false);
$dropCopies($from);

// An order of one product of a `fast` chunk: its statement reaches the stock row through the
// id_product index, the index entry first and the row second, while the chunk holds the row from its
// first statement. A chunk that wrote through that index would then wait for the entry: a deadlock
// in which the server rolled back the order's statement (9.0.3). A third session holds A's index
// entry (a covering shared read), so the chunk would wait on its write of A while the order of B
// takes B's entry and waits for B's row.
$from = $copies(2);
[$a, $b] = $byStockId($from, $from + 1);
$idOrder = $orderOf([$b]);
$session = $other();
$session->beginTransaction();
$session->query("SELECT id_product FROM {$p}stock_available FORCE INDEX (id_product) WHERE id_product = $a LOCK IN SHARE MODE")->fetchAll();
$request = $start('stock/bulk', ['options' => ['mode' => 'fast'], 'rows' => [['product_id' => (string) $a, 'variant_id' => '0', 'quantity' => 31], ['product_id' => (string) $b, 'variant_id' => '0', 'quantity' => 32]]]);
usleep(700000);
$orderRun = $child(['order', (string) $idOrder]);
usleep(700000);
$session->commit();
$orderAnswer = $finish($orderRun);
$answer = $finish($request);
$entry = ['order_statement' => $orderAnswer, 'chunk' => array_map(static function (array $r): string {
    return ($r['status'] ?? '?').'/'.($r['action'] ?? $r['code'] ?? '?');
}, $answer['data']['results'] ?? []), 'stock' => [$state($a)[0], $state($b)[0]]];
$kx->check('fast: an order of a product of the chunk, which takes the index entry before the row: its statement succeeds and the chunk is written', ($orderAnswer['ok'] ?? null) === true && $entry['chunk'] === ['ok/updated', 'ok/updated'] && $entry['stock'] === [31, 32], (string) json_encode($entry));
$db->execute("DELETE FROM {$p}order_detail WHERE id_order = $idOrder", false);
$dropCopies($from);

// 1 000 rows per request while PrestaShop's end-of-order statement runs in a loop for an order of a
// product in chunk 6 (prices: a back-office save of its row) and for an order of a product outside
// the request: their longest waits are what a checkout waits.
$n = 1000;
$from = $copies($n);
$probed = $from + 550;
$inside = $orderOf([$probed]);
$outside = $orderOf([$id]);
$holds = [];
foreach ([
    'prices/bulk' => [static function () use ($other, $p, $probed, $shop): void {
        static $session = null;
        $session = $session ?? $other();
        $session->exec("UPDATE {$p}product_shop SET price = price WHERE id_product = $probed AND id_shop = $shop");
    }, static function (int $id, int $i): array {
        return ['product_id' => (string) $id, 'variant_id' => '0', 'price' => (string) (100 + $i)];
    }],
    'stock/bulk safe' => [static function () use ($orderStatement, $inside): void {
        $orderStatement($inside);
    }, static function (int $id, int $i): array {
        return ['product_id' => (string) $id, 'variant_id' => '0', 'quantity' => $i];
    }],
    'stock/bulk fast' => [static function () use ($orderStatement, $inside): void {
        $orderStatement($inside);
    }, static function (int $id, int $i): array {
        return ['product_id' => (string) $id, 'variant_id' => '0', 'quantity' => $i + 1];
    }],
] as $endpoint => [$probe, $row]) {
    $batch = [];
    for ($i = 0; $i < $n; $i++) {
        $batch[] = $row($from + $i, $i);
    }
    $request = $start(explode(' ', $endpoint)[0], ['rows' => $batch] + (strpos($endpoint, 'fast') !== false ? ['options' => ['mode' => 'fast']] : []));
    $longest = 0.0;
    $longestOutside = 0.0;
    $probes = 0;
    do {
        $t0 = microtime(true);
        $probe();
        $longest = max($longest, microtime(true) - $t0);
        $t0 = microtime(true);
        $orderStatement($outside);
        $longestOutside = max($longestOutside, microtime(true) - $t0);
        $probes++;
        usleep(5000);
        $running = proc_get_status($request[0])['running'];
    } while ($running);
    $answer = $finish($request);
    $holds[$endpoint] = ['request_s' => $answer['seconds'] ?? null, 'chunk_avg_s' => round((float) ($answer['seconds'] ?? 0) / 10, 3), 'longest_wait_s' => round($longest, 3), 'longest_wait_outside_s' => round($longestOutside, 3), 'probes' => $probes];
    $kx->check("$endpoint: $n rows while an order of a product of chunk 6 is written, longest wait {$holds[$endpoint]['longest_wait_s']} s (outside the request {$holds[$endpoint]['longest_wait_outside_s']} s)", ($answer['data']['summary']['ok'] ?? 0) === $n && $longest < 2.0 && $longestOutside < 2.0, (string) json_encode([$answer['data']['summary'] ?? $answer, $holds[$endpoint]]));
}
$db->execute("DELETE FROM {$p}order_detail WHERE id_order IN ($inside, $outside)", false);
$dropCopies($from);

// The session's lock wait is its own again once a chunk's transaction ends, by commit or rollback:
// the hooks and logs after a chunk wait as long as the server lets them.
require_once dirname(__DIR__, 2).'/src/Autoloader.php';
Kronixon\Connect\Autoloader::register();
$shopDb = new Kronixon\Connect\Write\ShopDatabase($db, $p);
$sessionWait = static function () use ($db): int {
    return (int) $db->getValue('SELECT @@SESSION.innodb_lock_wait_timeout', false);
};
$own = $sessionWait();
$waits = [];
foreach (['commit', 'rollback'] as $end) {
    $shopDb->begin();
    $shopDb->lockRows("SELECT id_stock_available FROM {$p}stock_available WHERE id_product = $id ORDER BY id_stock_available", 3);
    $during = $sessionWait();
    $shopDb->$end();
    $waits[$end] = [$during, $sessionWait()];
}
$kx->check("the session's lock wait ($own s) is back after a chunk's commit and rollback", $waits === ['commit' => [3, $own], 'rollback' => [3, $own]] && $own !== 3, (string) json_encode($waits));

echo 'RACE TABLE ', json_encode($table), "\n";
echo 'SMALL SHOP ', json_encode($small), "\n";
echo 'ORDER DEADLOCK ', json_encode($deadlock), "\n";
echo 'FAST INDEX ENTRY ', json_encode($entry), "\n";
echo 'LOCK HOLDS ', json_encode($holds), "\n";
echo 'BULK RACE failures=', $kx->failures(), "\n";
exit($kx->failures() === 0 ? 0 : 1);
