<?php

declare(strict_types=1);
/**
 * Kronixon Connect for PrestaShop — test tooling, not part of the module zip.
 *
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *
 * The taxonomy write endpoints on a real shop (scripts/ps-smoke.sh, inside the shop container as
 * www-data, after pair.php): create → update → no-op with the same `content_hash` the `GET catalog/*`
 * reads answer, replays and conflicts of the Idempotency-Key, the write lock, languages that were not
 * sent, refused items that leave nothing behind, the category tree and the brand logo. One line per
 * check; exit 1 on any failure.
 *
 *   php catalog-writes.php <shop base URL> <apache address, e.g. 127.0.0.1:80>
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
[$value, $bg] = [$helpers['value'], $helpers['bulgarian']()];
Configuration::updateGlobalValue('KX_RATE_LIMIT', '100000');
$results = static function (array $answer): array {
    return array_column($answer['data']['results'] ?? [], null, 'key');
};
$home = (int) Configuration::get('PS_HOME_CATEGORY');
$default = (int) Configuration::get('PS_LANG_DEFAULT');
$tag = substr(SignedClient::uuid(), 0, 8);

$caps = $kx->get('capabilities')['data'];
$kx->check('capabilities announce the taxonomy writes', $caps['capabilities']['categories'] === true && $caps['capabilities']['attributes'] === true && $caps['capabilities']['tags'] === true
    && $caps['capabilities']['images'] === false && $caps['capabilities']['stock'] === false && $caps['limits']['batch'] === 100, (string) json_encode($caps));

// Categories: the tree by parent_key, positions, hashes.
$kxA = SignedClient::uuid();
$batch = ['options' => ['regenerate_tree' => 'end'], 'items' => [
    ['key' => 'c-0', 'external_id' => null, 'kx_id' => $kxA, 'name' => ['en' => "Storage $tag", 'bg' => "Памет $tag"], 'active' => true, 'position' => 3, 'match_by' => ['name_under_parent']],
    ['key' => 'c-1', 'external_id' => null, 'kx_id' => SignedClient::uuid(), 'parent_key' => 'c-0', 'name' => ['en' => "SSD $tag"], 'link_rewrite' => ['en' => "ssd-$tag"],
        'description' => ['en' => '<p>Fast</p>'], 'meta_title' => ['en' => 'SSD {best} <deal>'], 'active' => true, ],
    ['key' => 'c-2', 'parent_key' => 'nope', 'name' => ['en' => 'Orphan']],
    ['key' => 'c-3', 'parent_external_id' => '99999999', 'name' => ['en' => 'Orphan']],
    ['key' => 'c-4', 'name' => ['bg' => 'Само български']],
    ['key' => 'c-5', 'name' => ['en' => 'Bad <name>']],
]];
$before = $value("SELECT COUNT(*) FROM {$p}category");
$key = SignedClient::uuid();
$first = $kx->post('categories', $batch, $key);
$r = $results($first);
$kx->check('categories: two created, the parent by key', $first['status'] === 200 && ($r['c-0']['action'] ?? '') === 'created' && ($r['c-1']['action'] ?? '') === 'created' && $first['data']['summary']['ok'] === 2 && $first['data']['continue'] === false, $first['raw']);
$kx->check('categories: refused items name code and field', ($r['c-2']['code'] ?? '') === 'parent_not_found' && ($r['c-3']['code'] ?? '') === 'parent_not_found' && ($r['c-4']['field'] ?? '') === 'name.en' && ($r['c-5']['code'] ?? '') === 'validation_failed' && ($r['c-5']['field'] ?? '') === 'name.en', $first['raw']);
$kx->check('categories: refused items left nothing', $value("SELECT COUNT(*) FROM {$p}category") === $before + 2);
[$c0, $c1] = [(int) ($r['c-0']['external_id'] ?? 0), (int) ($r['c-1']['external_id'] ?? 0)];
$kx->check('categories: tree (depth, nested set)', $value("SELECT id_parent FROM {$p}category WHERE id_category = $c1") === $c0 && $value("SELECT level_depth FROM {$p}category WHERE id_category = $c1") === $value("SELECT level_depth FROM {$p}category WHERE id_category = $home") + 2
    && $value("SELECT COUNT(*) FROM {$p}category a INNER JOIN {$p}category b ON b.id_category = $c1 WHERE a.id_category = $c0 AND a.nleft > 0 AND a.nleft < b.nleft AND a.nright > b.nright") === 1);
$kx->check('categories: the missing language is the default one, the meta text is sanitised', $helpers['text']("SELECT name FROM {$p}category_lang WHERE id_category = $c1 AND id_lang = $bg") === "SSD $tag" && $helpers['text']("SELECT meta_title FROM {$p}category_lang WHERE id_category = $c1 AND id_lang = $default") === 'SSD  best   deal');
$kx->check('categories: the groups of the shop may see it', $value("SELECT COUNT(*) FROM {$p}category_group WHERE id_category = $c1") > 0);
$read = $helpers['readAll']($kx, 'catalog/categories');
$kx->check('categories: content_hash is the one of the read', ($r['c-0']['content_hash'] ?? 'a') === $read[$c0]['content_hash'] && ($r['c-1']['content_hash'] ?? 'a') === $read[$c1]['content_hash'] && $read[$c0]['kx_id'] === $kxA && $read[$c0]['position'] === 3, $first['raw']);

$replay = $kx->post('categories', $batch, $key);
$kx->check('the same key and body → the stored answer, replayed', $replay['status'] === 200 && $replay['replay'] && ! $first['replay'] && $replay['raw'] === $first['raw'] && $value("SELECT COUNT(*) FROM {$p}category") === $before + 2, $replay['raw']);
$other = $kx->post('categories', ['items' => [['key' => 'x', 'name' => ['en' => 'X']]]], $key);
$kx->check('the same key, another body → 409 idempotency_conflict', $other['status'] === 409 && ($other['data']['error']['code'] ?? '') === 'idempotency_conflict' && $value("SELECT COUNT(*) FROM {$p}category") === $before + 2, $other['raw']);
$kx->check('a key that is no UUID → 400', $kx->post('categories', $batch, 'abc')['status'] === 400);
$again = $results($kx->post('categories', $batch));
$kx->check('categories: the same items again → unchanged, the same hash', ($again['c-0']['action'] ?? '') === 'unchanged' && ($again['c-1']['action'] ?? '') === 'unchanged' && $again['c-1']['content_hash'] === $r['c-1']['content_hash'] && $again['c-0']['external_id'] === (string) $c0, (string) json_encode($again));

$dateBefore = $helpers['text']("SELECT date_upd FROM {$p}category WHERE id_category = $c1");
$update = $results($kx->post('categories', ['items' => [['key' => 'c-1', 'external_id' => (string) $c1, 'parent_external_id' => (string) $c0, 'name' => ['bg' => "ССД $tag"], 'xx' => 1], ['key' => 'l', 'external_id' => (string) $c1, 'parent_external_id' => (string) $c0, 'name' => ['bg' => "ССД $tag", 'fr' => 'SSD']]]]));
$kx->check('categories: only the language sent changes', ($update['c-1']['action'] ?? '') === 'updated' && $helpers['text']("SELECT name FROM {$p}category_lang WHERE id_category = $c1 AND id_lang = $default") === "SSD $tag" && $helpers['text']("SELECT name FROM {$p}category_lang WHERE id_category = $c1 AND id_lang = $bg") === "ССД $tag"
    && $helpers['text']("SELECT description FROM {$p}category_lang WHERE id_category = $c1 AND id_lang = $default") === '<p>Fast</p>' && $update['c-1']['content_hash'] !== $r['c-1']['content_hash'], (string) json_encode($update));
$kx->check('a language the shop lacks is left out with a warning', ($update['l']['action'] ?? '') === 'unchanged' && $update['l']['warnings'] === ['language_unknown'], (string) json_encode($update));
$strict = $results($kx->post('categories', ['options' => ['strict_languages' => true], 'items' => [['key' => 'l', 'external_id' => (string) $c1, 'name' => ['fr' => 'SSD']]]]));
$kx->check('strict_languages refuses it', ($strict['l']['code'] ?? '') === 'language_unknown', (string) json_encode($strict));

$moves = $results($kx->post('categories', ['items' => [
    ['key' => 'cycle', 'external_id' => (string) $c0, 'parent_external_id' => (string) $c1, 'name' => ['en' => "Storage $tag"]],
    ['key' => 'home', 'external_id' => (string) $home, 'name' => ['en' => 'Hacked']],
    ['key' => 'gone', 'external_id' => '99999999', 'name' => ['en' => 'Gone']],
    ['key' => 'up', 'external_id' => (string) $c1, 'name' => ['en' => "SSD $tag"], 'active' => false],
]]));
$kx->check('categories: cycle, Home and an unknown id are refused', ($moves['cycle']['code'] ?? '') === 'parent_cycle' && ($moves['home']['code'] ?? '') === 'root_category' && ($moves['gone']['code'] ?? '') === 'not_found' && $value("SELECT COUNT(*) FROM {$p}category_lang WHERE name = 'Hacked'") === 0, (string) json_encode($moves));
$kx->check('categories: moved under Home and deactivated', ($moves['up']['action'] ?? '') === 'updated' && $value("SELECT id_parent FROM {$p}category WHERE id_category = $c1") === $home && $value("SELECT level_depth FROM {$p}category WHERE id_category = $c1") === $value("SELECT level_depth FROM {$p}category WHERE id_category = $c0")
    && $value("SELECT active FROM {$p}category WHERE id_category = $c1") === 0, (string) json_encode($moves));
$read = $helpers['readAll']($kx, 'catalog/categories');
$kx->check('categories: the hash after the tree was rebuilt', $moves['up']['content_hash'] === $read[$c1]['content_hash'], (string) json_encode($read[$c1]));

// Features and attribute groups, their values.
$f = $results($kx->post('attributes', ['items' => [
    ['key' => 'f', 'kind' => 'feature', 'external_id' => null, 'kx_id' => SignedClient::uuid(), 'name' => ['en' => "Capacity $tag", 'bg' => "Капацитет $tag"], 'position' => 7, 'match_by' => ['name'], 'facets' => ['mode' => 'merge', 'categories' => []]],
    ['key' => 'g', 'kind' => 'attribute_group', 'name' => ['en' => "Finish $tag"], 'public_name' => ['en' => str_repeat('F', 70)], 'group_type' => 'color', 'match_by' => ['name']],
    ['key' => 'k', 'kind' => 'thing', 'name' => ['en' => 'X']],
]]));
[$feature, $group] = [(int) ($f['f']['external_id'] ?? 0), (int) ($f['g']['external_id'] ?? 0)];
$kx->check('attributes: a feature and a group created', ($f['f']['action'] ?? '') === 'created' && ($f['g']['action'] ?? '') === 'created' && ($f['k']['field'] ?? '') === 'kind' && $f['f']['warnings'] === ['facets_unsupported'] && $f['g']['warnings'] === ['public_name_cut']
    && $value("SELECT is_color_group FROM {$p}attribute_group WHERE id_attribute_group = $group") === 1 && $value("SELECT position FROM {$p}feature WHERE id_feature = $feature") === 7, (string) json_encode($f));
$f2 = $results($kx->post('attributes', ['items' => [
    ['key' => 'f', 'kind' => 'feature', 'name' => ['en' => "  CAPACITY  $tag"], 'match_by' => ['name']],
    ['key' => 'f2', 'kind' => 'feature', 'external_id' => (string) $feature, 'name' => ['bg' => "Обем $tag"]],
    ['key' => 'g', 'kind' => 'attribute_group', 'external_id' => (string) $group, 'name' => ['en' => "Finish $tag"], 'group_type' => 'select'],
]]));
$kx->check('attributes: matched by name whatever the case, updated per language', $f2['f']['external_id'] === (string) $feature && ($f2['f2']['action'] ?? '') === 'updated' && $helpers['text']("SELECT name FROM {$p}feature_lang WHERE id_feature = $feature AND id_lang = $bg") === "Обем $tag"
    && $value("SELECT is_color_group FROM {$p}attribute_group WHERE id_attribute_group = $group") === 0, (string) json_encode($f2));
$v = $results($kx->post('attribute-values', ['items' => [
    ['key' => 'fv', 'kind' => 'feature', 'parent_external_id' => (string) $feature, 'kx_id' => SignedClient::uuid(), 'value' => ['en' => '512 GB', 'bg' => '512 ГБ'], 'custom' => false, 'match_by' => ['value']],
    ['key' => 'fv2', 'kind' => 'feature', 'parent_external_id' => (string) $feature, 'value' => ['en' => '512 gb '], 'match_by' => ['value']],
    ['key' => 'av', 'kind' => 'attribute_group', 'parent_external_id' => (string) $group, 'name' => ['en' => 'Red'], 'color' => '#ff0000', 'position' => 2, 'match_by' => ['name']],
    ['key' => 'no', 'kind' => 'feature', 'parent_external_id' => '99999999', 'value' => ['en' => 'x']],
    ['key' => 'col', 'kind' => 'attribute_group', 'parent_external_id' => (string) $group, 'name' => ['en' => 'Blue'], 'color' => 'blue'],
]]));
[$fv, $av] = [(int) ($v['fv']['external_id'] ?? 0), (int) ($v['av']['external_id'] ?? 0)];
$kx->check('attribute-values: created, matched inside the batch, refused', ($v['fv']['action'] ?? '') === 'created' && $v['fv2']['external_id'] === (string) $fv && ($v['av']['action'] ?? '') === 'created' && ($v['no']['code'] ?? '') === 'parent_not_found' && ($v['col']['field'] ?? '') === 'color'
    && $helpers['text']("SELECT color FROM {$p}attribute WHERE id_attribute = $av") === '#ff0000', (string) json_encode($v));
$v2 = $results($kx->post('attribute-values', ['items' => [
    ['key' => 'fv', 'kind' => 'feature', 'parent_external_id' => (string) $feature, 'external_id' => (string) $fv, 'value' => ['en' => '512 gb']],
    ['key' => 'wrong', 'kind' => 'feature', 'parent_external_id' => (string) $feature, 'external_id' => '1', 'value' => ['en' => 'x']],
    ['key' => 'av', 'kind' => 'attribute_group', 'parent_external_id' => (string) $group, 'external_id' => (string) $av, 'name' => ['en' => 'Red']],
]]));
$kx->check('attribute-values: a repeat is unchanged with the same hash, a value of another parent is not found', ($v2['fv']['action'] ?? '') === 'unchanged' && $v2['fv']['content_hash'] === $v['fv']['content_hash'] && $v2['av']['content_hash'] === $v['av']['content_hash'] && ($v2['wrong']['code'] ?? '') === 'not_found', (string) json_encode($v2));

// Brands and the logo (served by this shop itself).
$logo = _PS_IMG_DIR_.'kx-logo.jpg';
$image = imagecreatetruecolor(300, 200);
imagejpeg($image, $logo);
$logoUrl = rtrim((string) ($argv[1] ?? ''), '/').'/img/kx-logo.jpg';
$sha = (string) hash_file('sha256', $logo);
$brand = ['key' => 'b', 'kx_id' => SignedClient::uuid(), 'name' => "Brand $tag", 'description' => ['en' => '<p>Good</p>'], 'match_by' => ['name']];
$b = $results($kx->post('brands', ['items' => [$brand, ['key' => 'n', 'name' => ''], ['key' => 'l', 'name' => "Logo $tag", 'logo' => ['url' => 'ftp://x/y', 'sha256' => $sha]]]]));
$brandId = (int) ($b['b']['external_id'] ?? 0);
$kx->check('brands: created active, refused names and URLs', ($b['b']['action'] ?? '') === 'created' && $value("SELECT active FROM {$p}manufacturer WHERE id_manufacturer = $brandId") === 1 && ($b['n']['field'] ?? '') === 'name' && ($b['l']['field'] ?? '') === 'logo.url', (string) json_encode($b));
$b2 = $results($kx->post('brands', ['items' => [['key' => 'm', 'name' => strtoupper("Brand $tag"), 'match_by' => ['name']], ['key' => 'b', 'external_id' => (string) $brandId, 'name' => "Brand $tag", 'active' => false]]]));
$kx->check('brands: matched by name, renamed back, deactivated', $b2['m']['external_id'] === (string) $brandId && ($b2['b']['action'] ?? '') === 'updated' && $value("SELECT active FROM {$p}manufacturer WHERE id_manufacturer = $brandId") === 0, (string) json_encode($b2));
// The shop's Apache listens on 80 inside the container; its public port is not reachable from here.
$inside = 'http://127.0.0.1/img/kx-logo.jpg';
$with = ['key' => 'b', 'external_id' => (string) $brandId, 'name' => "Brand $tag", 'logo' => ['url' => $inside, 'sha256' => $sha]];
$l = $results($kx->post('brands', ['items' => [$with, ['key' => 'bad', 'external_id' => (string) $brandId, 'name' => "Brand $tag", 'logo' => ['url' => $inside, 'sha256' => str_repeat('0', 64)]]]]));
$file = _PS_MANU_IMG_DIR_.$brandId.'.jpg';
$kx->check('brands: the logo is fetched, checked and resized', ($l['b']['action'] ?? '') === 'updated' && $l['b']['warnings'] === [] && is_file($file) && hash_file('sha256', $file) === $sha && glob(_PS_MANU_IMG_DIR_.$brandId.'-*.jpg') !== [] && ($l['bad']['code'] ?? '') === 'sha256_mismatch', (string) json_encode($l));
unlink($logo);
$l2 = $results($kx->post('brands', ['items' => [$with]]));
$kx->check('brands: the logo the shop holds is not fetched again', ($l2['b']['action'] ?? '') === 'unchanged' && $l2['b']['content_hash'] === $l['b']['content_hash'], (string) json_encode($l2));
$l3 = $results($kx->post('brands', ['items' => [['key' => 'b', 'external_id' => (string) $brandId, 'name' => "Brand $tag", 'logo' => null]]]));
clearstatcache();
$kx->check('brands: logo null removes it', ($l3['b']['action'] ?? '') === 'updated' && ! is_file($file), (string) json_encode($l3));
imagepng($image, $logo);
$png = ['key' => 'b', 'external_id' => (string) $brandId, 'name' => "Brand $tag", 'logo' => ['url' => $inside, 'sha256' => (string) hash_file('sha256', $logo)]];
$l4 = $results($kx->post('brands', ['items' => [$png]]));
unlink($logo);
$l5 = $results($kx->post('brands', ['items' => [$png]]));
clearstatcache();
$kx->check('brands: a PNG logo, which PrestaShop converts, is not fetched again', ($l4['b']['action'] ?? '') === 'updated' && $l4['b']['warnings'] === [] && is_array(getimagesize($file)) && hash_file('sha256', $file) !== $png['logo']['sha256']
    && ($l5['b']['action'] ?? '') === 'unchanged' && $l5['b']['content_hash'] === $l4['b']['content_hash'], (string) json_encode([$l4, $l5]));
$kx->check('options of a wrong type or value → 422 validation_failed', array_map(static function (array $options) use ($kx): array {
    $answer = $kx->post('categories', ['items' => [['key' => 'a', 'name' => ['en' => 'x']]], 'options' => $options]);

    return [$answer['status'], $answer['data']['error']['details']['errors'] ?? []];
}, [['strict_languages' => 'yes'], ['regenerate_tree' => 'sometimes']]) === [[422, ['options.strict_languages']], [422, ['options.regenerate_tree']]]);

// Tags.
$t = $results($kx->post('tags', ['items' => [['key' => 't', 'lang' => 'bg', 'name' => "таг $tag"], ['key' => 't2', 'lang' => 'bg', 'name' => "таг $tag"], ['key' => 'fr', 'lang' => 'fr', 'name' => 'x'], ['key' => 'long', 'lang' => 'bg', 'name' => str_repeat('x', 33)]]]));
$kx->check('tags: created once, unknown language and long names refused', ($t['t']['action'] ?? '') === 'created' && ($t['t2']['action'] ?? '') === 'unchanged' && $t['t']['external_id'] === $t['t2']['external_id'] && ($t['fr']['code'] ?? '') === 'language_unknown' && ($t['long']['field'] ?? '') === 'name', (string) json_encode($t));

// Envelope, limits, lock.
$many = [];
for ($i = 0; $i < 101; $i++) {
    $many[] = ['key' => "t-$i", 'lang' => 'en', 'name' => "many $tag $i"];
}
$big = $kx->post('tags', ['items' => $many]);
$kx->check('101 items → 413 batch_too_large, nothing written', $big['status'] === 413 && ($big['data']['error']['code'] ?? '') === 'batch_too_large' && $value("SELECT COUNT(*) FROM {$p}tag WHERE name LIKE 'many $tag%'") === 0, $big['raw']);
$twice = $kx->post('tags', ['items' => [$many[0], $many[0]]]);
$kx->check('a key twice → 422 validation_failed', $twice['status'] === 422 && ($twice['data']['error']['details']['errors'] ?? []) === ['items.1.key'], $twice['raw']);
$hundred = $kx->post('tags', ['items' => array_slice($many, 0, 100)]);
$kx->check('100 items → 100 results', $hundred['status'] === 200 && $hundred['data']['summary']['ok'] === 100 && count(array_filter(array_column($hundred['data']['results'], 'content_hash'))) === 100, substr($hundred['raw'], 0, 400));
$lock = 'kx_write_'.substr(sha1(_DB_NAME_.'.'.$p), 0, 32);
$db->getValue("SELECT GET_LOCK('$lock', 0)", false);
$busy = $kx->post('tags', ['items' => [$many[100]]]);
$db->getValue("SELECT RELEASE_LOCK('$lock')", false);
$kx->check('while another write holds the lock → 409 busy with retry_after', $busy['status'] === 409 && ($busy['data']['error']['code'] ?? '') === 'busy' && ($busy['data']['error']['retry_after'] ?? 0) === 5 && $value("SELECT COUNT(*) FROM {$p}tag WHERE name = 'many $tag 100'") === 0, $busy['raw']);
$kx->check('and a read still answers', $kx->get('health')['status'] === 200);

echo 'WRITES failures=', $kx->failures(), "\n";
exit($kx->failures() === 0 ? 0 : 1);
