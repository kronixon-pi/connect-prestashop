<?php

declare(strict_types=1);
/**
 * Kronixon Connect for PrestaShop — test tooling, not part of the module zip.
 *
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *
 * POST images and DELETE images on a real shop (scripts/ps-smoke.sh, inside the shop container as
 * www-data, after pair.php, with kxprobe installed): create, no-op by kx_key + sha256 (nothing fetched),
 * replace in place, alt per language, cover and order, the strategies and the images the shop uploaded
 * itself, refused items, combination links, and a request that dies while an image is written (before
 * and after a module commits) leaving no half image and converging on the retry. Files come from the
 * platform stub (`/files/`). One line per check; exit 1 on any failure.
 *
 *   php image-writes.php <shop base URL> <apache address, e.g. 127.0.0.1:80>
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
$en = (int) Configuration::get('PS_LANG_DEFAULT');
$tag = substr(SignedClient::uuid(), 0, 8);
$stub = getenv('KX_STUB_URL') ?: 'http://127.0.0.1:8099';
$pictures = _PS_IMG_DIR_.'p/';
$dir = '/tmp/kx-stub/files/';
@mkdir($dir, 0777, true);
$types = array_column(ImageType::getImagesTypes('products'), 'name');

/** A picture of $w×$h in $colour as the stub serves it: [kx_key, url, sha256]. */
$picture = static function (string $name, array $colour, string $format = 'jpg', int $w = 600, int $h = 400) use ($dir, $stub): array {
    $image = imagecreatetruecolor($w, $h);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, $colour[0], $colour[1], $colour[2]));
    $format === 'png' ? imagepng($image, $dir.$name) : imagejpeg($image, $dir.$name, 90);

    return [$name, $stub.'/files/'.$name, (string) hash_file('sha256', $dir.$name)];
};
$item = static function (string $key, array $picture, array $extra = []) use ($tag): array {
    return ['key' => $key, 'kx_key' => "$tag-{$picture[0]}", 'url' => $picture[1], 'sha256' => $picture[2]] + $extra;
};
$post = static function (string $product, array $items, array $extra = []) use ($kx): array {
    $answer = $kx->post('images', ['product_id' => $product, 'items' => $items] + $extra);
    if ($answer['status'] !== 200) {
        $kx->check('POST images answers 200', false, $answer['status'].' '.$answer['raw']);
    }

    return array_column($answer['data']['results'] ?? [], null, 'key');
};
/** The product's images in the paired shop: id => [position, cover, kx_key]. */
$images = static function (int $product) use ($db, $p, $shop): array {
    $rows = [];
    foreach ($db->executeS("SELECT i.id_image, i.position, ish.cover, m.kx_key FROM {$p}image i INNER JOIN {$p}image_shop ish ON ish.id_image = i.id_image AND ish.id_shop = $shop"
        ." LEFT JOIN {$p}kx_image_map m ON m.id_image = i.id_image WHERE i.id_product = $product ORDER BY i.position", true, false) ?: [] as $row) {
        $rows[(int) $row['id_image']] = [(int) $row['position'], (int) $row['cover'], $row['kx_key']];
    }

    return $rows;
};
/** Whether every file of the image is there (original and each type), and none when $gone. */
$files = static function (int $id, bool $gone = false) use ($types, $pictures): bool {
    $base = $pictures.Image::getImgFolderStatic($id).$id;
    clearstatcache();
    $there = array_filter(array_merge([$base.'.jpg'], array_map(static function (string $type) use ($base): string {
        return $base.'-'.$type.'.jpg';
    }, $types)), 'is_file');

    return $gone ? $there === [] : count($there) === count($types) + 1;
};

$caps = $kx->get('capabilities')['data'];
$kx->check('capabilities announce images and alt texts, 50 a request', $caps['capabilities']['images'] && $caps['capabilities']['image_alt'] && $caps['limits']['images_per_request'] === 50, (string) json_encode($caps['capabilities']));

$product = (string) ($kx->post('products', ['items' => [['key' => 'p', 'kx_id' => SignedClient::uuid(), 'groups' => ['content', 'visibility'], 'content' => ['name' => ['en' => "Pictures $tag"]], 'visibility' => ['active' => true]]]])['data']['results'][0]['external_id'] ?? '0');
$pid = (int) $product;
[$red, $green, $blue, $png] = [$picture("r-$tag.jpg", [200, 0, 0]), $picture("g-$tag.jpg", [0, 200, 0]), $picture("b-$tag.jpg", [0, 0, 200]), $picture("p-$tag.png", [90, 90, 0], 'png')];

// Create.
$t0 = microtime(true);
$r = $post($product, [$item('a', $red, ['alt' => ['en' => "Red $tag"], 'position' => 2]), $item('b', $green, ['alt' => ['en' => "Green $tag"], 'cover' => true, 'position' => 1]), $item('c', $png)]);
$created = round(microtime(true) - $t0, 2);
[$a, $b, $c] = [(int) ($r['a']['external_id'] ?? 0), (int) ($r['b']['external_id'] ?? 0), (int) ($r['c']['external_id'] ?? 0)];
$kx->check("images: three created in $created s, with their URLs", ($r['a']['action'] ?? '') === 'created' && ($r['b']['action'] ?? '') === 'created' && ($r['c']['action'] ?? '') === 'created'
    && isset($r['a']['urls']['original']) && count($r['a']['urls']) === count($types) + 1 && $files($a) && $files($b) && $files($c), (string) json_encode($r));
$kx->check('images: order by position, the one cover, the alt texts', $images($pid) === [$b => [1, 1, "$tag-{$green[0]}"], $a => [2, 0, "$tag-{$red[0]}"], $c => [3, 0, "$tag-{$png[0]}"]]
    && $text("SELECT legend FROM {$p}image_lang WHERE id_image = $a AND id_lang = $en") === "Red $tag", (string) json_encode($images($pid)));
$read = array_column($kx->get('catalog/images', ['product_id' => $product])['data']['data'] ?? [], null, 'external_id');
$kx->check('images: the read names kx_key, sha256, cover and alt', ($read[(string) $a]['kx_key'] ?? '') === "$tag-{$red[0]}" && $read[(string) $a]['sha256'] === $red[2] && $read[(string) $b]['cover'] === true && $read[(string) $a]['alt']['en'] === "Red $tag", (string) json_encode($read));

// The same again: nothing fetched (the stub no longer has the files), the same ids.
foreach ([$red, $green, $png] as $gone) {
    rename($dir.$gone[0], $dir.$gone[0].'.away');
}
$same = $post($product, [$item('a', $red, ['position' => 2]), $item('b', $green, ['cover' => true, 'position' => 1]), $item('c', $png)]);
$kx->check('images: the same again is unchanged, not fetched, the ids kept', array_column($same, 'action') === ['unchanged', 'unchanged', 'unchanged'] && array_map('intval', array_column($same, 'external_id')) === [$a, $b, $c], (string) json_encode($same));
foreach ([$red, $green, $png] as $gone) {
    rename($dir.$gone[0].'.away', $dir.$gone[0]);
}

// Another file under the same kx_key: replaced in place; alt in one language.
$before = (string) hash_file('sha256', $pictures.Image::getImgFolderStatic($c).$c.'.jpg');
$replaced = $post($product, [$item('a', $red, ['alt' => ['bg' => "Червено $tag"], 'position' => 2]), ['kx_key' => "$tag-{$png[0]}", 'url' => $blue[1], 'sha256' => $blue[2], 'key' => 'c'], $item('b', $green, ['position' => 1])]);
$kx->check('images: another sha256 replaces the files, the id and URL stay', ($replaced['c']['action'] ?? '') === 'updated' && (int) $replaced['c']['external_id'] === $c && $files($c)
    && (string) hash_file('sha256', $pictures.Image::getImgFolderStatic($c).$c.'.jpg') !== $before && $value("SELECT COUNT(*) FROM {$p}kx_image_map WHERE id_image = $c AND sha256 = '{$blue[2]}'") === 1, (string) json_encode($replaced));
$kx->check('images: an alt text in one language leaves the others', ($replaced['a']['action'] ?? '') === 'updated' && $text("SELECT legend FROM {$p}image_lang WHERE id_image = $a AND id_lang = $en") === "Red $tag", (string) json_encode($replaced));

// An image the shop uploaded itself; the strategies.
$own = new Image();
$own->id_product = $pid;
$own->legend = [$en => 'Own'];
$own->add();
$own->createImgFolder();
copy($dir.$red[0], $pictures.$own->getImgFolder().$own->id.'.jpg');
$sync = $post($product, [$item('a', $red), ['key' => 'c', 'kx_key' => "$tag-{$png[0]}", 'url' => $blue[1], 'sha256' => $blue[2]]]);
$kx->check('images: sync removes ours not sent, keeps the shop\'s own after ours, the cover goes to the first', array_column($sync, 'action') === ['unchanged', 'unchanged'] && $images($pid) === [$a => [1, 1, "$tag-{$red[0]}"], $c => [2, 0, "$tag-{$png[0]}"], (int) $own->id => [3, 0, null]] && $files($b, true), (string) json_encode([$images($pid), $a, $c, (int) $own->id, array_column($sync, 'action'), $files($b, true)]));
$append = $post($product, [$item('d', $green)], ['strategy' => 'append']);
$d = (int) ($append['d']['external_id'] ?? 0);
$kx->check('images: append adds at the end and keeps the rest', count($images($pid)) === 4 && array_key_last($images($pid)) === $d, (string) json_encode($images($pid)));
$all = $post($product, [$item('a', $red)], ['strategy' => 'replace_all']);
$kx->check('images: replace_all keeps only those sent', array_keys($images($pid)) === [$a] && ($all['a']['action'] ?? '') === 'unchanged', (string) json_encode($images($pid)));

// Refused items.
file_put_contents($dir."t-$tag.jpg", 'not an image');
$bad = $post($product, [
    ['key' => 'sha', 'kx_key' => "$tag-x", 'url' => $red[1], 'sha256' => str_repeat('0', 64)],
    ['key' => 'host', 'kx_key' => "$tag-y", 'url' => 'http://127.0.0.1/img/logo.jpg', 'sha256' => $red[2]],
    ['key' => 'txt', 'kx_key' => "$tag-z", 'url' => $stub."/files/t-$tag.jpg", 'sha256' => (string) hash_file('sha256', $dir."t-$tag.jpg")],
    ['key' => 'gone', 'kx_key' => "$tag-w", 'url' => $stub.'/files/none.jpg', 'sha256' => $red[2]],
    ['key' => 'other', 'kx_key' => "$tag-v", 'url' => $red[1], 'sha256' => $red[2], 'external_id' => '999999'],
    $item('a', $red), $item('dup', $red),
], ['strategy' => 'append']);
$kx->check('images: refused items name their code, nothing is left', array_map(static function (array $r): string {
    return $r['code'] ?? $r['action'];
}, array_values($bad)) === ['sha256_mismatch', 'download_host_refused', 'image_invalid', 'download_failed', 'not_found', 'unchanged', 'validation_failed'] && array_keys($images($pid)) === [$a], (string) json_encode($bad));
$none = $post('99999999', [$item('a', $red)]);
$big = $kx->post('images', ['product_id' => $product, 'items' => array_map(static function (int $i) use ($item, $red): array {
    return $item("k$i", $red);
}, range(1, 51))]);
$kx->check('images: an unknown product, 51 items', ($none['a']['code'] ?? '') === 'product_not_found' && $big['status'] === 413, (string) json_encode([$none, $big['status']]));

// A combination: linked by variant_id, and by POST variants after the images.
$v = $kx->post('variants', ['items' => [['key' => 'v', 'product_id' => $product, 'mode' => 'replace', 'option_groups' => [['key' => 'g', 'name' => ['en' => "Shade $tag"], 'values' => [['key' => 'l', 'name' => ['en' => 'Light']], ['key' => 'k', 'name' => ['en' => 'Dark']]]]],
    'combinations' => [['key' => 'c1', 'values' => ['g:l'], 'default' => true], ['key' => 'c2', 'values' => ['g:k'], 'image_keys' => ["$tag-{$red[0]}"]]], ]]])['data']['results'][0] ?? [];
[$c1, $c2] = [(int) ($v['combinations']['c1'] ?? 0), (int) ($v['combinations']['c2'] ?? 0)];
$linked = $post($product, [$item('e', $blue)], ['strategy' => 'append', 'variant_id' => (string) $c1]);
$e = (int) ($linked['e']['external_id'] ?? 0);
$kx->check('images: variant_id links the image; image_keys of POST variants find the images sent before', $value("SELECT COUNT(*) FROM {$p}product_attribute_image WHERE id_product_attribute = $c1 AND id_image = $e") === 1
    && $value("SELECT COUNT(*) FROM {$p}product_attribute_image WHERE id_product_attribute = $c2 AND id_image = $a") === 1 && ($v['warnings'] ?? ['x']) === [], (string) json_encode([$v, $linked]));

// DELETE images.
$own2 = new Image();
$own2->id_product = $pid;
$own2->add();
$delete = static function (array $body) use ($kx): array {
    return $kx->call('DELETE', 'images', [], (string) json_encode($body), SignedClient::uuid());
};
$del = $delete(['product_id' => $product, 'kx_keys' => ["$tag-{$red[0]}"], 'external_ids' => [(string) $own2->id]]);
$kx->check('DELETE images: by kx_key; the shop\'s own is image_not_kx; a new cover', $del['status'] === 200 && array_column($del['data']['results'], 'status', 'key') === [(string) $own2->id => 'error', (string) $a => 'ok']
    && $files($a, true) && $value("SELECT COUNT(*) FROM {$p}image_shop WHERE id_image = $e AND cover = 1") === 1 && $value("SELECT COUNT(*) FROM {$p}product_attribute_image WHERE id_image = $a") === 0, $del['raw']);
$del = $delete(['product_id' => $product, 'all' => true, 'only_kx' => false]);
$kx->check('DELETE images: all, the shop\'s own too with only_kx false', $del['status'] === 200 && $images($pid) === [] && $del['data']['summary']['ok'] === 2, $del['raw']);

// A request that dies while an image is written.
$probe = static function (array $modes): void {
    file_put_contents('/tmp/kxprobe.json', (string) json_encode($modes));
    @chmod('/tmp/kxprobe.json', 0666);
};
$installed = $value("SELECT COUNT(*) FROM {$p}module WHERE name = 'kxprobe' AND active = 1") === 1;
if ($installed) {
    Module::getInstanceByName('kxprobe')->registerAll();
    foreach (['kill-open' => 'before any commit', 'kill' => 'after a module committed'] as $how => $when) {
        $boom = $item('x', $green, ['alt' => ['en' => "BOOM $how $tag"]]);
        $key = SignedClient::uuid();
        $probe(['add' => $how]);
        $kx->lose('images', ['product_id' => $product, 'items' => [$boom], 'strategy' => 'append'], $key);
        $probe([]);
        $half = array_map('intval', array_column($db->executeS("SELECT id_image FROM {$p}image_lang WHERE legend = 'BOOM $how $tag'", true, false) ?: [], 'id_image'));
        // Holding the write lock as a running write would: the read cannot clean up, only leave it out.
        $lock = "'kx_write_".substr(sha1(_DB_NAME_.'.'._DB_PREFIX_), 0, 32)."'";
        $db->getValue("SELECT GET_LOCK($lock, 10)", false);
        $listed = array_column($kx->get('catalog/images', ['product_id' => $product])['data']['data'] ?? [], 'external_id');
        $db->getValue("SELECT RELEASE_LOCK($lock)", false);
        $committed = $half !== [];
        $kx->check(
            "images, the request dies $when ($how): no half image; a read lists none",
            $listed === [] && (! $committed || ($files($half[0]) && $value("SELECT COUNT(*) FROM {$p}kx_pending WHERE entity = 'image' AND id_object = {$half[0]}") === 1)),
            (string) json_encode([$half, $listed])
        );
        $again = $kx->post('images', ['product_id' => $product, 'items' => [$boom], 'strategy' => 'append'], $key);
        $now = $images($pid);
        $id = (int) array_key_first($now);
        $kx->check("images, $how: the retry makes exactly one whole image", $again['status'] === 200 && ($again['data']['results'][0]['action'] ?? '') === 'created' && count($now) === 1 && $files($id)
            && (! $committed || ($id !== $half[0] && $files($half[0], true))) && $value("SELECT COUNT(*) FROM {$p}kx_pending") === 0, $again['raw'].json_encode([$half, $now]));
        $delete(['product_id' => $product, 'all' => true]);
    }
    $probe(['add' => 'exception']);
    $failed = $post($product, [$item('y', $red, ['alt' => ['en' => "BOOM fail $tag"]])], ['strategy' => 'append']);
    $probe([]);
    // 1.7.8 lets the module's exception through (the item fails and is removed); 9.x logs it and goes on.
    $whole = ($failed['y']['status'] ?? '') === 'ok' && count($images($pid)) === 1 && $files((int) $failed['y']['external_id']);
    $gone = ($failed['y']['status'] ?? '') === 'error' && $images($pid) === [] && $value("SELECT COUNT(*) FROM {$p}image_lang WHERE legend = 'BOOM fail $tag'") === 0;
    $kx->check('images, a module fails after committing: the item is an error and leaves nothing, or PrestaShop went on and it is whole', $whole || $gone, (string) json_encode($failed));
    $delete(['product_id' => $product, 'all' => true]);
} else {
    echo "  --   kxprobe is not installed: no request that dies\n";
}

// The time budget: items skipped for it are in the request, so sync and replace_all remove nothing then;
// the continue request (the whole list again) is cheap and removes what is no longer sent.
$four = [];
foreach (['w', 'x', 'y', 'z'] as $i => $k) {
    $four[$k] = $picture("$k-$tag.jpg", [30 * $i, 120, 60]);
}
foreach (['sync', 'replace_all'] as $strategy) {
    $setup = $post($product, [$item('w', $four['w']), $item('x', $four['x']), $item('y', $four['y']), $item('z', $four['z'])], ['strategy' => 'replace_all']);
    $before = array_keys($images($pid));
    $big = $picture("big-$strategy-$tag.jpg", [250, 10, 10], 'jpg', 4000, 3000);
    $first = ['key' => 'w', 'kx_key' => "$tag-{$four['w'][0]}", 'url' => $big[1], 'sha256' => $big[2]];
    Configuration::updateGlobalValue('KX_TIME_BUDGET', '2');
    $answer = $kx->post('images', ['product_id' => $product, 'strategy' => $strategy, 'items' => [$first, $item('x', $four['x']), $item('y', $four['y']), $item('z', $four['z'])]]);
    Configuration::updateGlobalValue('KX_TIME_BUDGET', '25');
    $statuses = array_column($answer['data']['results'] ?? [], 'status', 'key');
    $kx->check("images, $strategy with a 2 s budget: the first replaced, the rest skipped, continue, no image removed", count($setup) === 4 && ($statuses['w'] ?? '') === 'ok' && in_array('skipped', $statuses, true)
        && ($answer['data']['continue'] ?? null) === true && array_keys($images($pid)) === $before, (string) json_encode([$statuses, $before, $images($pid)]));
    $again = $post($product, [$first, $item('x', $four['x']), $item('y', $four['y'])], ['strategy' => $strategy]);
    $kx->check("images, $strategy continue with the whole list: unchanged items, what is not sent goes", array_column($again, 'action', 'key') === ['w' => 'unchanged', 'x' => 'unchanged', 'y' => 'unchanged']
        && array_keys($images($pid)) === array_slice($before, 0, 3), (string) json_encode([$again, $images($pid)]));
}
// A fetch the budget cuts is skipped too: the request ends within its budget, not after the 20 s of a fetch.
$slow = $picture("slow-$tag.jpg", [1, 2, 3]);
Configuration::updateGlobalValue('KX_TIME_BUDGET', '4');
$t0 = microtime(true);
$answer = $kx->post('images', ['product_id' => $product, 'strategy' => 'append', 'items' => [['key' => 's', 'kx_key' => "$tag-slow", 'url' => str_replace('/files/', '/slow/6/files/', $slow[1]), 'sha256' => $slow[2]]]]);
$cut = round(microtime(true) - $t0, 2);
Configuration::updateGlobalValue('KX_TIME_BUDGET', '25');
$result = $answer['data']['results'][0] ?? [];
$kx->check(
    "images: a fetch the 4 s budget cuts is skipped/time_budget in $cut s, with continue",
    ($result['status'] ?? '') === 'skipped' && ($result['code'] ?? '') === 'time_budget' && ($answer['data']['continue'] ?? null) === true && $cut < 5.0,
    (string) json_encode([$result, $cut])
);
usleep(4000000);  // the stub serves one request at a time: let the slow one end
$delete(['product_id' => $product, 'all' => true]);

// Cost: ten images in one request.
$ten = [];
for ($i = 0; $i < 10; $i++) {
    $ten[] = $item("t$i", $picture("t$i-$tag.jpg", [20 * $i, 100, 255 - 20 * $i], 'jpg', 1200, 1200));
}
$t0 = microtime(true);
$timed = $post($product, $ten);
$seconds = round(microtime(true) - $t0, 2);
$kx->check("images: ten of 1200×1200 in one request in $seconds s", count(array_filter(array_column($timed, 'action'), static function (string $a): bool {
    return $a === 'created';
})) === 10, (string) json_encode($timed));
$delete(['product_id' => $product, 'all' => true]);
array_map('unlink', glob($dir."*-$tag.*") ?: []);
echo 'IMAGE TIMINGS ', json_encode(['three' => $created, 'ten_1200' => $seconds]), "\n";
echo 'IMAGE WRITES failures=', $kx->failures(), "\n";
exit($kx->failures() === 0 ? 0 : 1);
