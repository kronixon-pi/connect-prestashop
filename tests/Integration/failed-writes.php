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
$textFeature = (string) ($kx->post('attributes', ['items' => [['key' => 'f', 'kind' => 'feature', 'name' => ['en' => "Text $tag"]]]])['data']['results'][0]['external_id'] ?? '');
$category = (string) ($kx->post('categories', ['items' => [['key' => 'c', 'name' => ['en' => "Failed $tag"]]]])['data']['results'][0]['external_id'] ?? '');
$kinds['product'] = [
    'products',
    static function (string $name) use ($textFeature, $category): array {
        return ['type' => 'simple', 'groups' => ['identity', 'content', 'categories', 'attributes', 'tags', 'visibility'], 'identity' => ['reference' => $name], 'content' => ['name' => ['en' => $name]],
            'categories' => ['default_id' => $category, 'ids' => [$category], 'mode' => 'replace'], 'attributes' => ['mode' => 'replace', 'values' => [['feature_id' => $textFeature, 'custom' => ['en' => "custom $name"]]]],
            'tags' => ['mode' => 'replace', 'values' => ['en' => [substr("t $name", 0, 32)]]], 'visibility' => ['active' => true], 'stock_initial' => ['quantity' => 7], 'match_by' => ['reference'], ];
    },
    static function (string $name) use ($list, $p): array {
        return $list("SELECT id_product AS id FROM {$p}product WHERE reference = '$name'");
    },
    static function (int $id) use ($value, $p): int {
        $sum = 0;
        foreach (['product', 'product_shop', 'product_lang', 'category_product', 'feature_product', 'product_tag', 'stock_available'] as $table) {
            $sum += $value("SELECT COUNT(*) FROM $p$table WHERE id_product = $id");
        }

        return $sum;
    },
    static function (int $id, string $name) use ($value, $p, $category): bool {
        return $value("SELECT quantity FROM {$p}stock_available WHERE id_product = $id AND id_product_attribute = 0") === 7 && $value("SELECT COUNT(*) FROM {$p}category_product WHERE id_product = $id AND id_category = $category") === 1
            && $value("SELECT COUNT(*) FROM {$p}feature_product fp INNER JOIN {$p}feature_value_lang fl ON fl.id_feature_value = fp.id_feature_value WHERE fp.id_product = $id AND fl.value = 'custom $name'") > 0
            && $value("SELECT COUNT(*) FROM {$p}product_tag WHERE id_product = $id") === 1 && $value("SELECT COUNT(DISTINCT id_feature_value) FROM {$p}feature_value_lang WHERE value = 'custom $name'") === 1;
    },
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
    'product' => ['product', 'catalog/products', 'products', static function (int $id): string {
        return "index.php?id_product=$id&controller=product";
    }],
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

// A product whose last hooks fail, after add() has returned and all of it is written.
$name = "product added $tag";
$body = ['items' => [['key' => 'p', 'external_id' => null, 'kx_id' => SignedClient::uuid()] + $kinds['product'][1]("BOOM $name")]];
$probe(['added' => 'error']);
$failed = $short($kx->post('products', $body));
$left = $kinds['product'][2]("BOOM $name");
$probe([]);
$again = $short($kx->post('products', $body));
$now = $kinds['product'][2]("BOOM $name");
$kx->check('product, a hook after add() throws: nothing of it stays, the repeat makes one', $failed === ['p' => 'module_error'] && $left === [] && $again === ['p' => 'created'] && count($now) === 1 && $kinds['product'][4]($now[0], "BOOM $name"), (string) json_encode([$failed, $left, $again, $now]));

// Combinations: the second of three fails.
$product = static function (string $name) use ($kx): string {
    return (string) ($kx->post('products', ['items' => [['key' => 'p', 'type' => 'variable', 'groups' => ['identity', 'content'], 'identity' => ['reference' => $name], 'content' => ['name' => ['en' => $name]]]]])['data']['results'][0]['external_id'] ?? '');
};
$variants = static function (string $id, string $name, array $references): array {
    $values = [];
    $combinations = [];
    foreach ($references as $i => $reference) {
        $values[] = ['key' => "v$i", 'name' => ['en' => "val $name $i"]];
        $combinations[] = ['key' => "c$i", 'values' => ["g:v$i"], 'reference' => $reference, 'quantity_initial' => 3];
    }

    return ['items' => [['key' => 'v', 'product_id' => $id, 'option_groups' => [['key' => 'g', 'name' => ['en' => "Opt $name"], 'values' => $values]], 'combinations' => $combinations]]];
};
// id, reference, attributes, default, stock of a product's combinations; and what the product says is its default.
$combinations = static function (string $id) use ($db, $p): array {
    return $db->executeS("SELECT pa.id_product_attribute AS id, pa.reference, (SELECT COUNT(*) FROM {$p}product_attribute_combination c WHERE c.id_product_attribute = pa.id_product_attribute) AS attributes, pas.default_on AS is_default,
        (SELECT SUM(s.quantity) FROM {$p}stock_available s WHERE s.id_product_attribute = pa.id_product_attribute) AS stock FROM {$p}product_attribute pa INNER JOIN {$p}product_attribute_shop pas ON pas.id_product_attribute = pa.id_product_attribute
        WHERE pa.id_product = $id ORDER BY pa.id_product_attribute", true, false) ?: [];
};
$defaultOf = static function (string $id) use ($value, $p): array {
    return [$value("SELECT cache_default_attribute FROM {$p}product_shop WHERE id_product = $id AND id_shop = ".(int) Configuration::get('PS_SHOP_DEFAULT')), $value("SELECT cache_default_attribute FROM {$p}product WHERE id_product = $id")];
};
$wholeSet = static function (string $id, int $count) use ($combinations, $defaultOf): bool {
    $rows = $combinations($id);
    $defaults = array_map('intval', array_column(array_filter($rows, static function (array $row): bool {
        return (int) $row['is_default'] === 1;
    }), 'id'));

    return count($rows) === $count && array_unique(array_map('intval', array_column($rows, 'attributes'))) === [1] && array_unique(array_map('intval', array_column($rows, 'stock'))) === [3] && count($defaults) === 1 && $defaultOf($id) === [$defaults[0], $defaults[0]];
};
$groupRows = static function (string $name) use ($value, $p): int {
    return $value("SELECT COUNT(*) FROM {$p}attribute_group_lang WHERE name = 'Opt $name'") + $value("SELECT COUNT(*) FROM {$p}attribute_lang WHERE name LIKE 'val $name %'");
};
foreach (['error', 'exception', 'exit', 'kill'] as $how) {
    $name = "combo $how $tag";
    $id = $product($name);
    $body = $variants($id, $name, ["A $name", "BOOM $name", "C $name"]);
    $probe(['add' => $how]);
    if (in_array($how, ['error', 'exception'], true)) {
        $got = $short($kx->post('variants', $body));
        $kx->check("combination, the add hook throws $how: the item is an error, nothing of it stays", $got === ['v' => 'updated'] && $how === 'exception' || $got === ['v' => 'module_error'] && $combinations($id) === [] && $defaultOf($id) === [0, 0] && $groupRows($name) === 0 && $pending() === 0, json_encode([$got, $combinations($id), $defaultOf($id), $groupRows($name)]).'');
    } else {
        $kx->lose('variants', $body, SignedClient::uuid());
        $kx->check("combination, the request dies in the hook ($how): something is left", $combinations($id) !== [] && $pending() > 0);
    }
    $probe([]);
    $again = $kx->post('variants', $body);
    $kx->check("combination, $how: the repeat makes exactly the three, one the default", in_array($short($again), [['v' => 'updated'], ['v' => 'unchanged']], true) && $wholeSet($id, 3) && $pending() === 0 && $groupRows($name) % 4 === 0, $again['raw'].json_encode($combinations($id)));
}
foreach ([0, 1] as $before) {
    $name = "combo left $before $tag";
    $id = $product($name);
    if ($before === 1) {
        $kx->post('variants', $variants($id, $name, ["A $name"]));
    }
    $whole = $combinations($id);
    $body = $variants($id, $name, ["A $name", "BOOM $name", "C $name"]);
    $probe(['add' => 'error', 'delete' => 'error']);
    $got = $short($kx->post('variants', $body));
    $rows = $combinations($id);
    $half = array_values(array_filter($rows, static function (array $row): bool {
        return (int) $row['attributes'] === 0;
    }));
    $default = $before === 1 ? (int) $whole[0]['id'] : 0;
    $kx->check("combination, the clean-up throws too ($before whole before): cleanup_failed, the half one is never the default", $got === ['v' => 'cleanup_failed'] && count($half) === 1 && (int) $half[0]['is_default'] === 0 && $defaultOf($id) === [$default, $default]
        && count($rows) === $before + 1 && $pending() > 0, json_encode([$got, $rows, $defaultOf($id)]).'');
    $got = $short($kx->post('variants', $body));
    $kx->check("combination ($before whole before): nothing is written as long as it cannot be removed", $got === ['v' => 'cleanup_failed'] && $combinations($id) === $rows && $defaultOf($id) === [$default, $default], json_encode([$got, $combinations($id)]).'');
    $probe([]);
    $again = $kx->post('variants', $body);
    $kx->check("combination ($before whole before): then the repeat makes exactly the three", $short($again) === ['v' => 'updated'] && $wholeSet($id, 3) && $pending() === 0, $again['raw'].json_encode($combinations($id)));
}

echo 'FAILED-WRITES failures=', $kx->failures(), "\n";
exit($kx->failures() === 0 ? 0 : 1);
