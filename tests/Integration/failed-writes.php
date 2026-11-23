<?php

declare(strict_types=1);
/**
 * Kronixon Connect for PrestaShop — test tooling, not part of the module zip.
 *
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *
 * What a failed item leaves behind on a real shop (scripts/ps-smoke.sh, inside the shop container as
 * www-data, after pair.php, with the module kxprobe installed): a hook inside PrestaShop's add() commits
 * the item's transaction and then throws, the clean-up's own hook throws too, or the request dies there.
 * For every kind of entity: the item is an `error`, the other items are written, nothing half-written
 * stays and a repeat of the batch makes exactly one entity. One line per check; exit 1 on any failure.
 *
 *   php failed-writes.php <shop base URL> <apache address, e.g. 127.0.0.1:80>
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
$value = $helpers['value'];
Configuration::updateGlobalValue('KX_RATE_LIMIT', '100000');
$en = (int) Configuration::get('PS_LANG_DEFAULT');
$tag = substr(SignedClient::uuid(), 0, 6);

/** How the probe fails from here on. */
$probe = static function (array $modes): void {
    file_put_contents('/tmp/kxprobe.json', (string) json_encode($modes));
    chmod('/tmp/kxprobe.json', 0666);
};
$probe([]);
$short = static function (array $answer): array {
    return array_map(static function (array $result): string {
        return $result['action'] ?? $result['code'];
    }, array_column($answer['data']['results'] ?? [], null, 'key'));
};
$pending = static function () use ($value, $p): int {
    return $value("SELECT COUNT(*) FROM {$p}kx_pending");
};

/*
 * kind => endpoint, its item by name, the ids of a name, the orphaned rows of an id that is gone, whether
 * the entity of an id is what was sent.
 */
$list = static function (string $sql) use ($db): array {
    return array_map('intval', array_column($db->executeS($sql, true, false) ?: [], 'id'));
};
$kinds = [
    'category' => [
        'categories',
        static function (string $name): array {
            return ['name' => ['en' => $name], 'description' => ['en' => "<p>$name</p>"], 'match_by' => ['name_under_parent']];
        },
        static function (string $name) use ($list, $p): array {
            return $list("SELECT DISTINCT id_category AS id FROM {$p}category_lang WHERE name = '$name'");
        },
        static function (int $id) use ($value, $p): int {
            return $value("SELECT (SELECT COUNT(*) FROM {$p}category WHERE id_category = $id) + (SELECT COUNT(*) FROM {$p}category_shop WHERE id_category = $id)
                + (SELECT COUNT(*) FROM {$p}category_lang WHERE id_category = $id) + (SELECT COUNT(*) FROM {$p}category_group WHERE id_category = $id)");
        },
        static function (int $id, string $name) use ($value, $p, $en): bool {
            return $value("SELECT COUNT(*) FROM {$p}category_group WHERE id_category = $id") > 0 && $value("SELECT COUNT(*) FROM {$p}category_lang WHERE id_category = $id AND id_lang = $en AND description = '<p>$name</p>'") > 0;
        },
    ],
    'brand' => [
        'brands',
        static function (string $name): array {
            return ['name' => $name, 'description' => ['en' => "<p>$name</p>"], 'match_by' => ['name']];
        },
        static function (string $name) use ($list, $p): array {
            return $list("SELECT id_manufacturer AS id FROM {$p}manufacturer WHERE name = '$name'");
        },
        static function (int $id) use ($value, $p): int {
            return $value("SELECT (SELECT COUNT(*) FROM {$p}manufacturer WHERE id_manufacturer = $id) + (SELECT COUNT(*) FROM {$p}manufacturer_shop WHERE id_manufacturer = $id) + (SELECT COUNT(*) FROM {$p}manufacturer_lang WHERE id_manufacturer = $id)");
        },
        static function (int $id, string $name) use ($value, $p, $en): bool {
            return $value("SELECT COUNT(*) FROM {$p}manufacturer_lang WHERE id_manufacturer = $id AND id_lang = $en AND description = '<p>$name</p>'") === 1;
        },
    ],
    'feature' => [
        'attributes',
        static function (string $name): array {
            return ['kind' => 'feature', 'name' => ['en' => $name], 'match_by' => ['name']];
        },
        static function (string $name) use ($list, $p): array {
            return $list("SELECT DISTINCT id_feature AS id FROM {$p}feature_lang WHERE name = '$name'");
        },
        static function (int $id) use ($value, $p): int {
            return $value("SELECT (SELECT COUNT(*) FROM {$p}feature WHERE id_feature = $id) + (SELECT COUNT(*) FROM {$p}feature_shop WHERE id_feature = $id) + (SELECT COUNT(*) FROM {$p}feature_lang WHERE id_feature = $id)");
        },
        static function (int $id) use ($value, $p): bool {
            return $value("SELECT COUNT(*) FROM {$p}feature_shop WHERE id_feature = $id") > 0;
        },
    ],
];

/*
 * kind => its table, its GET catalog/* and profile counter, and for one with a storefront page that page
 * by id: what a dead request left is read by neither until the clean-up removes it.
 */
$reads = [
    'category' => ['category', 'catalog/categories', 'categories', static function (int $id): string {
        return "index.php?id_category=$id&controller=category";
    }],
    'brand' => ['manufacturer', 'catalog/brands', 'manufacturers', static function (int $id): string {
        return "index.php?id_manufacturer=$id&controller=manufacturer";
    }],
    'feature' => ['feature', 'catalog/attributes', 'features', null],
];
/** The storefront's answer to a page, redirects followed: the status and the URL that answered it. */
$front = static function (string $path) use ($argv): array {
    $base = rtrim((string) ($argv[1] ?? ''), '/');
    $parts = parse_url($base);
    $curl = curl_init($base.'/'.$path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECT_TO => [$parts['host'].':'.($parts['port'] ?? 80).':'.(string) ($argv[2] ?? '')], ]);
    curl_exec($curl);

    return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL)];
};
/** Whether the storefront shows entity $id's own page (its id in the friendly or the plain URL). */
$shows = static function (array $answer, int $id): bool {
    return $answer[0] === 200 && preg_match('#/'.$id.'-|[?&]id_[a-z]+='.$id.'(&|$)#', $answer[1]) === 1;
};
// The write lock of the module (DbGuards): held here, a read cannot clean up and has to leave the object out.
$lock = "'kx_write_".substr(sha1(_DB_NAME_.'.'._DB_PREFIX_), 0, 32)."'";

foreach ($kinds as $kind => [$endpoint, $fields, $ids, $rows, $whole]) {
    $batch = static function (string $name) use ($fields): array {
        $items = [];
        foreach (['1', '2', 'BOOM', '4', '5'] as $i => $part) {
            $items[] = ['key' => (string) ($i + 1), 'external_id' => null, 'kx_id' => SignedClient::uuid()] + $fields("$part $name");
        }

        return ['items' => $items];
    };
    foreach (['error', 'exception'] as $how) {
        $name = "$kind $how $tag";
        $body = $batch($name);
        $probe(['add' => $how]);
        $answer = $kx->post($endpoint, $body);
        $got = $short($answer);
        // PrestaShop 9.1 swallows an Exception of a hook: then the item is complete.
        $swallowed = $how === 'exception' && $got['3'] === 'created';
        $kx->check("$kind, the add hook throws $how: the item is an error, the others are written", array_values($got) === ['created', 'created', $swallowed ? 'created' : 'module_error', 'created', 'created'], $answer['raw']);
        $kx->check("$kind, $how: nothing of it stays", $swallowed ? count($ids("BOOM $name")) === 1 : $ids("BOOM $name") === [] && $pending() === 0 && $value("SELECT COUNT(*) FROM {$p}kx_entity_map WHERE kx_id = '{$body['items'][2]['kx_id']}'") === 0, (string) json_encode($ids("BOOM $name")));
        $probe([]);
        $again = $kx->post($endpoint, $body);
        $now = $ids("BOOM $name");
        $kx->check("$kind, $how: the repeat makes exactly one, whole", array_values($short($again)) === ['unchanged', 'unchanged', $swallowed ? 'unchanged' : 'created', 'unchanged', 'unchanged'] && count($now) === 1 && $whole($now[0], "BOOM $name"), $again['raw']);
    }

    $name = "$kind left $tag";
    $body = $batch($name);
    $probe(['add' => 'error', 'delete' => 'error']);
    $answer = $kx->post($endpoint, $body);
    $kx->check("$kind, the clean-up throws too: cleanup_failed, the others are written", $answer['status'] === 200 && array_values($short($answer)) === ['created', 'created', 'cleanup_failed', 'created', 'created'] && $pending() > 0, $answer['raw']);
    $left = $ids("BOOM $name");
    $answer = $kx->post($endpoint, $body);
    $kx->check("$kind, as long as it cannot be removed: neither adopted nor made again", array_values($short($answer)) === ['unchanged', 'unchanged', 'cleanup_failed', 'unchanged', 'unchanged'] && $ids("BOOM $name") === $left, $answer['raw']);
    unset($body['items'][2]['kx_id']);
    $answer = $kx->post($endpoint, $body);
    $kx->check("$kind, nor by match_by", array_values($short($answer)) === ['unchanged', 'unchanged', 'cleanup_failed', 'unchanged', 'unchanged'] && $ids("BOOM $name") === $left, $answer['raw']);
    $probe([]);
    $again = $kx->post($endpoint, $body);
    $now = $ids("BOOM $name");
    $kx->check("$kind, once it can be removed: the repeat makes exactly one, whole", array_values($short($again)) === ['unchanged', 'unchanged', 'created', 'unchanged', 'unchanged'] && count($now) === 1 && $now !== $left && $whole($now[0], "BOOM $name") && $rows($left[0] ?? 0) === 0 && $pending() === 0, $again['raw']);

    foreach (['exit', 'kill'] as $how) {
        $name = "$kind $how $tag";
        $body = $batch($name);
        $key = SignedClient::uuid();
        $probe(['add' => $how]);
        $kx->lose($endpoint, $body, $key);
        $half = $ids("BOOM $name");
        $probe([]);
        $id = $half[0] ?? 0;

        // Before any further write, and before any request that can clean up: the reads leave it out.
        [$table, $read, $counter, $page] = $reads[$kind];
        $locked = (string) $db->getValue("SELECT GET_LOCK($lock, 10)", false) === '1';
        $answer = $kx->get($read, ['cursor' => rtrim(strtr(base64_encode('{"id":'.($id - 1).'}'), '+/', '-_'), '='), 'until_id' => (string) $id]);
        $listed = array_column(array_filter($answer['data']['data'] ?? [], static function (array $item): bool {
            return ($item['kind'] ?? 'feature') === 'feature';
        }), 'external_id');
        $count = $kx->get('profile')['data']['counts'][$counter] ?? -1;
        $kx->check("$kind, the request dies in the hook ($how): until the clean-up, $read, its max_id and the profile count leave it out", $locked && $id > 0 && $answer['status'] === 200
            && ! in_array((string) $id, $listed, true) && ($answer['data']['meta']['max_id'] ?? '') !== (string) $id
            && $count === $value("SELECT COUNT(*) FROM {$p}$table") - 1 && $rows($id) > 0 && $pending() === 1, json_encode([$half, $listed, $count]).$answer['raw']);
        if ($page !== null) {
            $shown = $front($page($id));
            $kx->check("$kind, $how: the storefront does not show it ($shown[0] $shown[1])", $value("SELECT active FROM {$p}$table WHERE id_$table = $id") === 0 && ! $shows($shown, $id), (string) json_encode($shown));
        }
        $db->getValue("SELECT RELEASE_LOCK($lock)", false);
        $health = $kx->get('health');
        $kx->check("$kind, $how: a read removes it, no write needed", $health['status'] === 200 && $rows($id) === 0 && $pending() === 0, $health['raw']);

        $again = $kx->post($endpoint, $body, $key);
        $now = $ids("BOOM $name");
        $kx->check("$kind, the request dies in the hook ($how): the repeat makes exactly one, whole", count($half) === 1 && array_values($short($again)) === ['unchanged', 'unchanged', 'created', 'created', 'created'] && count($now) === 1 && $now !== $half && $whole($now[0], "BOOM $name") && $rows($id) === 0 && $pending() === 0, json_encode([$half, $now]).$again['raw']);
        if ($page !== null) {
            $shown = $front($page($now[0] ?? 0));
            $kx->check("$kind, $how: the storefront shows the complete one ($shown[0] $shown[1])", $shows($shown, $now[0] ?? 0), (string) json_encode($shown));
        }
    }
}

echo 'FAILED-WRITES failures=', $kx->failures(), "\n";
exit($kx->failures() === 0 ? 0 : 1);
