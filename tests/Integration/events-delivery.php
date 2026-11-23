<?php

declare(strict_types=1);
/**
 * Kronixon Connect for PrestaShop — test tooling, not part of the module zip.
 *
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *
 * Delivery of store events on a real shop to platform-stub.php (scripts/ps-smoke.sh, inside the shop
 * container as www-data, after pair.php): the cron URL, coalescing on the wire, an order, the platform
 * down and back (backoff, Retry-After), a flush killed while it waits for the platform, a flush after a
 * page view, the heartbeat, a gap, refused events, no PHP warning from a flush, and the batches against
 * the protocol schema. One line per check; exit 1 on any failure.
 *
 *   php events-delivery.php <shop base URL> <apache address, e.g. 127.0.0.1:80>
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

use Kronixon\Connect\Outbox\Flusher;
use Kronixon\Connect\Outbox\Heartbeat;
use Kronixon\Connect\Outbox\Recorder;
use Kronixon\Connect\Services;

Module::getInstanceByName('kronixonconnect');
$base = rtrim((string) ($argv[1] ?? ''), '/');
$connectTo = (string) ($argv[2] ?? '');
$kx = new SignedClient($base, $connectTo);
$db = Db::getInstance();
$p = _DB_PREFIX_;
$helpers = kxShopHelpers($db, $p);
[$value, $text] = [$helpers['value'], $helpers['text']];
$stateFile = '/tmp/kx-stub/state.json';
$context = Context::getContext();
$shop = (int) $context->shop->id;
$lang = (int) Configuration::get('PS_LANG_DEFAULT');
$employee = new Employee();
$employee->id = 0;
$context->employee = $employee;
Cache::clean('hook_module_exec_list_*');
$tag = substr(SignedClient::uuid(), 0, 8);
Configuration::updateGlobalValue('KX_RATE_LIMIT', '100000');

$stub = static function (?callable $change = null) use ($stateFile): array {
    $state = json_decode((string) file_get_contents($stateFile), true);
    if ($change !== null) {
        $state = $change($state);
        file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    return $state;
};
// A clean start: no batches at the stub, an empty outbox, nothing to wait for, a heartbeat just sent.
$stub(static function (array $state): array {
    unset($state['events'], $state['event_batches'], $state['events_mode'], $state['events_retry_after'], $state['events_refused']);

    return $state;
});
array_map('unlink', glob('/tmp/kx-stub/batches/*.json') ?: []);
$reset = static function () use ($db, $p): void {
    $db->execute("DELETE FROM {$p}kx_outbox");
    foreach ([Flusher::RETRY_AT, Flusher::FAILURES, Recorder::GAP] as $key) {
        Configuration::deleteByName($key);
    }
    Configuration::updateGlobalValue(Flusher::HEARTBEAT_AT, (string) time());
};
$reset();
$token = Services::cronToken();
// The cron URL over HTTP, as a hosting cron calls it.
$http = static function (string $url) use ($connectTo): array {
    $parts = (array) parse_url($url);
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECT_TO => [$parts['host'].':'.($parts['port'] ?? 80).':'.$connectTo]]);
    $start = microtime(true);
    $body = (string) curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

    return ['status' => $status, 'data' => json_decode($body, true), 'raw' => $body, 'ms' => (microtime(true) - $start) * 1000,
        'ttfb' => (float) curl_getinfo($curl, CURLINFO_STARTTRANSFER_TIME) * 1000];
};
// A cron call; unless $soon, the last flush is made older than the cron URL's 10 s floor first.
$cron = static function (string $token = '', bool $soon = false) use ($http, $base): array {
    if (! $soon) {
        Configuration::deleteByName(Flusher::LAST_FLUSH);
    }

    return $http($base.'/index.php?fc=module&module=kronixonconnect&controller=cron&token='.rawurlencode($token));
};
// ps-smoke.sh sets KX_FPM=1 on the nginx + PHP-FPM leg: there a page view can flush after its answer.
$fpm = getenv('KX_FPM') === '1';
$received = static function () use ($stub): array {
    return array_values($stub()['events'] ?? []);
};
// What the cron and page requests wrote (this process caches Configuration from its start).
$setting = static function (string $name) use ($text, $p): string {
    return $text("SELECT value FROM {$p}configuration WHERE name = '$name' AND id_shop IS NULL AND id_shop_group IS NULL");
};
$rows = static function (string $status) use ($value, $p): int {
    return $value("SELECT COUNT(*) FROM {$p}kx_outbox WHERE status = '$status'");
};
$edit = static function (int $id, string $reference) {
    $product = new Product($id);
    $product->reference = $reference;
    $product->update();
};

$caps = $kx->get('capabilities')['data']['capabilities'] ?? [];
$kx->check('capabilities announce webhooks and events.pull', ($caps['webhooks'] ?? null) === true && ($caps['events.pull'] ?? null) === true);
$kx->check('the cron URL refuses a wrong token', $cron('nope')['status'] === 403 && $cron()['status'] === 403);

$made = $kx->post('products', ['items' => [['key' => 'a', 'kx_id' => SignedClient::uuid(), 'groups' => ['content', 'visibility', 'pricing'],
    'content' => ['name' => ['en' => "Delivery $tag"]], 'visibility' => ['active' => true], 'pricing' => ['price' => '10'], 'stock_initial' => ['quantity' => 50]]]]);
$a = (int) ($made['data']['results'][0]['external_id'] ?? 0);
$kx->check('setup: a product of the platform', $a > 0 && $rows('pending') === 0, $made['raw']);

// A back-office edit goes out on the next cron call, with its content hash.
$edit($a, "DL-$tag-1");
$answer = $cron($token);
$events = $received();
$read = $kx->get('catalog/products', ['ids' => (string) $a])['data']['data'][0] ?? [];
$kx->check('the cron call sends a back-office edit', $answer['status'] === 200 && ($answer['data']['flush']['sent'] ?? null) === 1 && count($events) === 1
    && $events[0]['type'] === 'product.updated' && $events[0]['external_id'] === (string) $a && ($events[0]['payload']['content_hash'] ?? null) === ($read['content_hash'] ?? 'x')
    && $events[0]['kx_id'] === ($read['kx_id'] ?? 'x') && $rows('pending') === 0 && $rows('sent') > 0, $answer['raw'].' '.json_encode($events));
printf("  time  cron call with one event: %.0f ms\n", $answer['ms']);
$kx->check('the cron answer says whether page views can deliver here ('.($fpm ? 'PHP-FPM' : 'mod_php').')', ($answer['data']['page_views'] ?? null) === $fpm, $answer['raw']);

// Within 10 s of the last flush the cron URL answers at once and writes nothing.
$edit($a, "DL-$tag-soon");
Configuration::updateGlobalValue(Heartbeat::CRON_AT, '1');
$soon = $cron($token, true);
$kx->check('a cron call right after a flush answers too_soon and does nothing', ($soon['data']['flush']['state'] ?? null) === 'too_soon' && $rows('pending') > 0
    && $setting(Heartbeat::CRON_AT) === '1', $soon['raw'].' pending '.$rows('pending').' cron_at '.$setting(Heartbeat::CRON_AT));
printf("  time  cron call too soon: %.0f ms\n", $soon['ms']);
$cron($token);

// A new token: the old URL answers 403, the new one works.
$old = $token;
$token = Services::rotateCronToken();
$kx->check('a new cron token retires the old URL', $cron($old)['status'] === 403 && $cron($token)['status'] === 200 && $old !== $token);

// A hundred edits and an order: two events.
for ($i = 0; $i < 100; $i++) {
    $edit($a, "DL-$tag-x$i");
}
StockAvailable::updateQuantity($a, 0, -1, $shop);
$before = count($received());
$answer = $cron($token);
$new = array_slice($received(), $before);
$kx->check('100 edits and a stock change arrive as two events', array_column($new, 'type') === ['product.updated', 'stock.updated'] && ($new[1]['payload']['quantity'] ?? null) === 49, json_encode($new));

// The platform is down: the rows wait, nothing is sent while waiting, then everything arrives once.
$stub(static function (array $state): array {
    $state['events_mode'] = 'down';

    return $state;
});
$edit($a, "DL-$tag-down1");
$brand = new Manufacturer((int) $value("SELECT MIN(id_manufacturer) FROM {$p}manufacturer"));
$brand->update();
$answer = $cron($token);
$wait = (int) $setting(Flusher::RETRY_AT) - time();
$kx->check('platform down: the rows stay pending and the module waits 60 s', ($answer['data']['flush']['state'] ?? null) === 'waiting' && $rows('pending') === 3 && $wait >= 55 && $wait <= 60
    && ($stub()['events_refused'] ?? 0) === 1, $answer['raw']." wait $wait");
$edit($a, "DL-$tag-down2");
$cron($token);
$kx->check('… and sends nothing while it waits', ($stub()['events_refused'] ?? 0) === 1 && $rows('pending') === 5);
$stub(static function (array $state): array {
    $state['events_retry_after'] = 90;

    return $state;
});
Configuration::deleteByName(Flusher::RETRY_AT);
$cron($token);
$wait = (int) $setting(Flusher::RETRY_AT) - time();
$kx->check('Retry-After: 90 is followed', $wait >= 85 && $wait <= 90, "wait $wait");
$stub(static function (array $state): array {
    $state['events_mode'] = 'ok';
    unset($state['events_retry_after']);

    return $state;
});
// The wait is over (as if 90 s had passed).
Configuration::deleteByName(Flusher::RETRY_AT);
$before = count($received());
$answer = $cron($token);
$new = array_slice($received(), $before);
$kx->check('platform back: the outbox drains, each change once', ($answer['data']['flush']['state'] ?? null) === 'done' && $rows('pending') === 0
    && array_column($new, 'type') === ['brand.updated', 'product.updated'], $answer['raw'].' '.json_encode($new));

// A flush killed while it waits for the platform: the batch goes again as it was, nothing is lost.
$stub(static function (array $state): array {
    $state['events_mode'] = 'hang';
    $state['events_hang_s'] = 6;

    return $state;
});
$edit($a, "DL-$tag-kill");
$batches = count($stub()['event_batches'] ?? []);
$process = proc_open([PHP_BINARY, __DIR__.'/flush-cli.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['PS_DOMAIN' => getenv('PS_DOMAIN') ?: 'localhost']);
$deadline = microtime(true) + 10;
while (count($stub()['event_batches'] ?? []) === $batches && microtime(true) < $deadline) {
    usleep(100000);
}
if (is_resource($process)) {
    // SIGKILL to the flush process itself (no shell between): no clean-up of any kind runs.
    proc_terminate($process, 9);
    proc_close($process);
}
$sending = $rows('sending');
$first = $stub()['event_batches'][$batches] ?? [];
$stub(static function (array $state): array {
    $state['events_mode'] = 'ok';

    return $state;
});
// The stub answers the killed request first (it serves one request at a time).
sleep(7);
$answer = $cron($token);
$again = $stub()['event_batches'][$batches + 1] ?? [];
$kx->check('a flush killed mid request leaves its batch claimed', $sending > 0 && ($first['event_ids'] ?? []) !== [], "sending $sending");
$kx->check('… which goes again with the same batch and event ids: a duplicate, not a new event', ($again['batch_id'] ?? 'x') === ($first['batch_id'] ?? 'y')
    && ($again['event_ids'] ?? []) === ($first['event_ids'] ?? ['y']) && $rows('sending') === 0 && $rows('pending') === 0, json_encode([$first, $again, $answer['raw']]));

// A page view flushes after its answer when no cron runs (at most once a minute), and only when PHP can
// finish the answer first (PHP-FPM); under mod_php it never flushes, so the visitor never waits.
$edit($a, "DL-$tag-visit");
// The first page after an edit rebuilds caches: view it once with no flush due, so the times compare.
Configuration::updateGlobalValue(Flusher::LAST_FLUSH, (string) time());
$warm = $http($base.'/index.php');
Configuration::deleteByName(Flusher::LAST_FLUSH);
Configuration::deleteByName(Heartbeat::CRON_AT);
$before = count($received());
$page = $http($base.'/index.php');
$deadline = microtime(true) + ($fpm ? 8 : 3);
while (count($received()) === $before && microtime(true) < $deadline) {
    usleep(200000);
}
if ($fpm) {
    $kx->check('PHP-FPM: a page view sends the outbox after its answer', $page['status'] === 200 && count($received()) === $before + 1 && $rows('pending') === 0, (string) $page['status']);
} else {
    $kx->check('mod_php: a page view sends nothing, the rows wait for the cron or the pull', $page['status'] === 200 && count($received()) === $before && $rows('pending') > 0
        && (int) $setting(Flusher::LAST_FLUSH) === 0, $page['status'].' pending '.$rows('pending').' last_flush '.$setting(Flusher::LAST_FLUSH));
}
$second = $http($base.'/index.php');
printf("  time  page view TTFB: no flush due %.0f ms; flush due %.0f ms (total %.0f ms); the next one %.0f ms\n", $warm['ttfb'], $page['ttfb'], $page['ms'], $second['ttfb']);
$edit($a, "DL-$tag-visit2");
$http($base.'/index.php');
usleep(1500000);
$kx->check('… at most once a minute', $rows('pending') > 0);
Configuration::updateGlobalValue(Heartbeat::CRON_AT, (string) time());
Configuration::deleteByName(Flusher::LAST_FLUSH);
$http($base.'/index.php');
usleep(1500000);
$kx->check('… and not while a cron calls', $rows('pending') > 0);
$cron($token);

// The heartbeat and a gap.
Configuration::deleteByName(Flusher::HEARTBEAT_AT);
Configuration::updateGlobalValue(Recorder::GAP, (string) (time() - 30));
$before = count($received());
$cron($token);
$new = array_slice($received(), $before);
$types = array_column($new, 'type');
$heartbeat = $new[array_search('module.heartbeat', $types, true)]['payload'] ?? [];
$kx->check('a gap is module.outbox_overflow and the day starts with module.heartbeat', $types === ['module.outbox_overflow', 'module.heartbeat']
    && ($heartbeat['ps_version'] ?? null) === _PS_VERSION_ && ($heartbeat['counts']['products'] ?? 0) > 0 && ($heartbeat['cron']['configured'] ?? null) === true
    && ($heartbeat['delivery'] ?? null) === 'cron', json_encode($new));

// The gap goes before the changes already waiting, in a batch of its own.
$edit($a, "DL-$tag-gap-first");
Configuration::updateGlobalValue(Recorder::GAP, (string) (time() - 30));
$before = count($received());
$cron($token);
$kx->check('a gap goes before the changes that wait', array_column(array_slice($received(), $before), 'type') === ['module.outbox_overflow', 'product.updated']
    && count($stub()['event_batches'] ?? []) >= 2, json_encode(array_slice($received(), $before)));

// No cron for an hour: a pull records the heartbeat, which says how this shop delivers now.
Configuration::updateGlobalValue(Heartbeat::CRON_AT, (string) (time() - 7200));
Configuration::deleteByName(Flusher::HEARTBEAT_AT);
$pulled = $kx->get('events', ['limit' => '200']);
$pulledBeat = null;
foreach ($pulled['data']['data'] ?? [] as $event) {
    if (($event['type'] ?? '') === 'module.heartbeat') {
        $pulledBeat = $event['payload'];
    }
}
$kx->check('a pull records the due heartbeat: delivery '.($fpm ? 'page_views' : 'pull_only'), ($pulledBeat['delivery'] ?? null) === ($fpm ? 'page_views' : 'pull_only')
    && ($pulledBeat['cron']['configured'] ?? null) === false, $pulled['raw']);
$cron($token);

// Events the platform refuses fail and are not sent again.
$stub(static function (array $state): array {
    $state['events_mode'] = 'reject';

    return $state;
});
$edit($a, "DL-$tag-rejected");
$cron($token);
$stub(static function (array $state): array {
    $state['events_mode'] = 'ok';

    return $state;
});
$batches = count($stub()['event_batches'] ?? []);
$cron($token);
$kx->check('a rejected event fails and is not sent again', $rows('failed') > 0 && $rows('pending') === 0 && count($stub()['event_batches'] ?? []) === $batches
    && strpos($text("SELECT last_error FROM {$p}kx_outbox WHERE status = 'failed'"), 'rejected_by_stub') !== false);

// A flush raises no PHP warning or notice: one that sends, one with nothing to send, and one that finds
// another flush holding the lock. The cron and page-view requests run the same Services::flushOutbox();
// ps-smoke.sh checks the server log for them.
$edit($a, "DL-$tag-quiet");
$raised = [];
set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0) use (&$raised): bool {
    $raised[] = "$level $message ($file:$line)";

    return true;
}, E_ALL);
$sends = Services::flushOutbox($context, 10);
$idle = Services::flushOutbox($context, 10);
$class = Db::getClass();
$holder = new $class(_DB_SERVER_, _DB_USER_, _DB_PASSWD_, _DB_NAME_, true);
$lock = "'kx_outbox_".substr(sha1(_DB_NAME_.'.'._DB_PREFIX_), 0, 24)."'";
$held = (int) $holder->getValue('SELECT GET_LOCK('.$lock.', 0)', false) === 1;
$locked = Services::flushOutbox($context, 10);
$holder->getValue('SELECT RELEASE_LOCK('.$lock.')', false);
unset($holder);
gc_collect_cycles();
restore_error_handler();
$kx->check('a flush raises no PHP warning or notice: sending, idle, or without the lock', $raised === [] && ($sends['sent'] ?? null) === 1
    && ($idle['sent'] ?? null) === 0 && $held && $locked === null, json_encode([$raised, $sends, $idle, $held, $locked]));

// Every batch the stub got, against platform/store-events.json.
$validation = [];
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/validate-answers.php').' /tmp/kx-stub/batches', $validation, $exit);
$kx->check('every batch matches platform/store-events.json', $exit === 0, implode("\n", array_slice($validation, -3)));

$reset();
exit($kx->failures() === 0 ? 0 : 1);
