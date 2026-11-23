<?php

declare(strict_types=1);
/**
 * Kronixon Connect for PrestaShop — test tooling, not part of the module zip.
 *
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *
 * POST products, variants and seo/redirects on a real shop (scripts/ps-smoke.sh, inside the shop
 * container as www-data, after pair.php): create → update → no-op with the `content_hash` of the reads,
 * groups and languages that were not sent, links of another shop, refused items that leave nothing
 * behind, combinations replaced and merged, redirects served by the storefront. One line per check;
 * exit 1 on any failure.
 *
 *   php product-writes.php <shop base URL> <apache address, e.g. 127.0.0.1:80>
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
[$value, $text, $bg] = [$helpers['value'], $helpers['text'], $helpers['bulgarian']()];
Configuration::updateGlobalValue('KX_RATE_LIMIT', '100000');
$home = (int) Configuration::get('PS_HOME_CATEGORY');
$en = (int) Configuration::get('PS_LANG_DEFAULT');
$shop = (int) Configuration::get('PS_SHOP_DEFAULT');
$tag = substr(SignedClient::uuid(), 0, 8);
$modern = version_compare(_PS_VERSION_, '8.0.0', '>=');
$send = static function (string $endpoint, array $items, array $options = []) use ($kx): array {
    $answer = $kx->post($endpoint, ['items' => $items] + ($options === [] ? [] : ['options' => $options]));
    if ($answer['status'] !== 200) {
        $kx->check("POST $endpoint answers 200", false, $answer['status'].' '.$answer['raw']);
    }

    return array_column($answer['data']['results'] ?? [], null, 'key');
};
$hash = static function (int $id) use ($kx): string {
    return (string) ($kx->get('catalog/products', ['ids' => (string) $id])['data']['data'][0]['content_hash'] ?? '');
};
$product = static function (int $id) use ($kx): array {
    return $kx->get('catalog/products', ['ids' => (string) $id])['data']['data'][0] ?? [];
};

$caps = $kx->get('capabilities')['data']['capabilities'];
$kx->check('capabilities announce what this build writes', $caps['products.write'] && $caps['variants'] && $caps['seo'] && $caps['redirects'] && $caps['attribute_values_custom'] && ! $caps['images'] && ! $caps['stock'] && ! $caps['prices.bulk'] && ! $caps['facets'], (string) json_encode($caps));

// What products point at.
$c = $send('categories', [['key' => 'a', 'name' => ['en' => "Cat A $tag"]], ['key' => 'b', 'name' => ['en' => "Cat B $tag"]]]);
[$catA, $catB] = [$c['a']['external_id'], $c['b']['external_id']];
$a = $send('attributes', [['key' => 'f', 'kind' => 'feature', 'name' => ['en' => "Size $tag"]], ['key' => 't', 'kind' => 'feature', 'name' => ['en' => "Weight $tag"]]]);
[$feature, $textFeature] = [$a['f']['external_id'], $a['t']['external_id']];
$v = $send('attribute-values', [['key' => '1', 'kind' => 'feature', 'parent_external_id' => $feature, 'value' => ['en' => 'Small']], ['key' => '2', 'kind' => 'feature', 'parent_external_id' => $feature, 'value' => ['en' => 'Large']]]);
[$small, $large] = [$v['1']['external_id'], $v['2']['external_id']];
$brand = $send('brands', [['key' => 'b', 'name' => "Maker $tag"]])['b']['external_id'];
$supplier = new Supplier();
$supplier->name = "Supplier $tag";
$supplier->active = true;
$supplier->add();
$supplierId = (string) $supplier->id;

// Create.
$kxId = SignedClient::uuid();
$ean = '38'.str_pad((string) random_int(0, 99999999999), 11, '0', STR_PAD_LEFT);
$full = ['key' => 'p', 'external_id' => null, 'kx_id' => $kxId, 'match_by' => ['ean13', 'reference'], 'type' => 'simple',
    'groups' => ['identity', 'content', 'seo', 'categories', 'brand', 'attributes', 'tags', 'visibility', 'suppliers', 'pricing', 'physical'],
    'identity' => ['reference' => "R-$tag", 'ean13' => $ean, 'mpn' => 'M1', 'upc' => '', 'isbn' => ''],
    'content' => ['name' => ['en' => "SSD $tag", 'bg' => "Диск $tag"], 'description_short' => ['en' => '<p>NVMe</p>'], 'description' => ['en' => '<p>Long <strong>text</strong></p>', 'bg' => '<p>Текст</p>'], 'available_now' => ['en' => 'In stock']],
    'seo' => ['link_rewrite' => ['en' => "ssd-$tag"], 'meta_title' => ['en' => 'SSD <best>'], 'meta_description' => ['en' => str_repeat('я', 600)], 'redirect' => ['type' => '404', 'target_id' => '0']],
    'categories' => ['default_id' => $catA, 'ids' => [$catA], 'mode' => 'replace'], 'brand' => ['id' => $brand],
    'attributes' => ['mode' => 'replace', 'values' => [['feature_id' => $feature, 'value_id' => $small], ['feature_id' => $textFeature, 'custom' => ['en' => '1.5 kg', 'bg' => '1.5 кг']]]],
    'tags' => ['mode' => 'replace', 'values' => ['en' => ["ssd $tag", 'nvme'], 'bg' => ["диск $tag"]]],
    'visibility' => ['active' => true, 'visibility' => 'both', 'available_for_order' => true, 'show_price' => true, 'condition' => 'new', 'online_only' => false],
    'suppliers' => ['default_id' => $supplierId, 'mode' => 'replace', 'rows' => [['supplier_id' => $supplierId, 'reference' => 'S-1', 'price_te' => '80.00']]],
    'pricing' => ['price' => '99.990000', 'wholesale_price' => '80.00', 'unit_price' => '10', 'unity' => 'kg'],
    'physical' => ['weight' => '1.2', 'width' => '0', 'height' => '0', 'depth' => '0'], 'stock_initial' => ['quantity' => 7, 'out_of_stock' => 1], ];
$before = $value("SELECT COUNT(*) FROM {$p}product");
$r = $send('products', [$full, ['key' => 'n', 'groups' => ['identity'], 'identity' => ['reference' => "N-$tag"]],
    ['key' => 'bg', 'groups' => ['content'], 'content' => ['name' => ['bg' => 'Само']]],
    ['key' => 'x', 'external_id' => '99999999', 'groups' => ['content'], 'content' => ['name' => ['en' => 'X']]],
    ['key' => 'g', 'groups' => ['content'], 'identity' => ['reference' => 'x']],
    ['key' => 'cat', 'groups' => ['content', 'categories'], 'content' => ['name' => ['en' => "Half $tag"]], 'categories' => ['ids' => ['99999999']]],
    ['key' => 'fv', 'groups' => ['content', 'attributes'], 'content' => ['name' => ['en' => "Half $tag"]], 'attributes' => ['values' => [['feature_id' => $feature, 'value_id' => '99999999']]]],
    ['key' => 'bad', 'groups' => ['content', 'identity'], 'content' => ['name' => ['en' => "Half $tag"]], 'identity' => ['ean13' => 'not-an-ean']],
]);
$id = (int) ($r['p']['external_id'] ?? 0);
$kx->check('products: created with every group', ($r['p']['status'] ?? '') === 'ok' && $r['p']['action'] === 'created' && $r['p']['kx_id'] === $kxId && $r['p']['link_rewrite'] === "ssd-$tag" && strpos($r['p']['url'], "ssd-$tag") !== false && $r['p']['warnings'] === ['meta_description_cut'], (string) json_encode($r));
$kx->check('products: refused items name code and field', ($r['n']['field'] ?? '') === 'content.name' && ($r['bg']['field'] ?? '') === 'content.name.en' && ($r['x']['code'] ?? '') === 'not_found' && ($r['g']['field'] ?? '') === 'content'
    && ($r['cat']['code'] ?? '') === 'category_not_found' && ($r['fv']['code'] ?? '') === 'feature_value_not_found' && ($r['bad']['code'] ?? '') === 'validation_failed' && ($r['bad']['field'] ?? '') === 'identity.ean13', (string) json_encode($r));
$kx->check('products: refused items left nothing', $value("SELECT COUNT(*) FROM {$p}product") === $before + 1 && $value("SELECT COUNT(*) FROM {$p}product_lang WHERE name = 'Half $tag'") === 0);
$item = $product($id);
$kx->check('products: the read shows what was sent', $item['reference'] === "R-$tag" && $item['ean13'] === $ean && $item['name'] === ['en' => "SSD $tag", 'bg' => "Диск $tag"] && $item['default_category_id'] === $catA && $item['category_ids'] === [$catA] && $item['brand_id'] === $brand
    && $item['price'] === '99.990000' && $item['wholesale_price'] === '80.000000' && $item['unit_price'] === '10.000000' && $item['unity'] === 'kg' && $item['weight'] === '1.200000' && $item['quantity'] === 7 && $item['out_of_stock'] === 1 && $item['active'] === true && $item['type'] === 'simple' && $item['kx_id'] === $kxId
    && count($item['attributes']) === 2 && $item['tags'] === ['en' => ['nvme', "ssd $tag"], 'bg' => ["диск $tag"]] && $item['suppliers'][0]['reference'] === 'S-1' && $item['supplier_id'] === $supplierId && $item['meta_title']['en'] === 'SSD  best' && mb_strlen($item['meta_description']['en']) === 512, (string) json_encode($item, JSON_UNESCAPED_UNICODE));
$kx->check('products: content_hash is the one of the read', $r['p']['content_hash'] === $item['content_hash']);
if ($value("SELECT COUNT(*) FROM {$p}module WHERE name = 'ps_facetedsearch' AND active = 1") === 1) {
    $kx->check('products: the hooks of other modules ran, after the features were there', $value("SELECT COUNT(*) FROM {$p}layered_price_index WHERE id_product = $id") > 0);
} else {
    echo "  --   ps_facetedsearch is not installed: no module to see the hooks with\n";
}
$kx->check('products: the tax rules group most products have', $value("SELECT id_tax_rules_group FROM {$p}product_shop WHERE id_product = $id AND id_shop = $shop") === (int) Product::getIdTaxRulesGroupMostUsed());

// No-op: by kx_id, by external_id, by match.
$upd = $text("SELECT date_upd FROM {$p}product_shop WHERE id_product = $id AND id_shop = $shop");
sleep(1);
$again = $send('products', [$full, ['key' => 'e', 'external_id' => (string) $id] + $full, ['key' => 'm', 'kx_id' => null] + $full]);
$kx->check('products: the same item again → unchanged, the same hash, no new date', $again['p']['action'] === 'unchanged' && $again['e']['action'] === 'unchanged' && $again['m']['action'] === 'unchanged' && $again['m']['external_id'] === (string) $id && $again['p']['content_hash'] === $item['content_hash']
    && $text("SELECT date_upd FROM {$p}product_shop WHERE id_product = $id AND id_shop = $shop") === $upd && $value("SELECT COUNT(*) FROM {$p}product") === $before + 1, (string) json_encode($again));

// Partial updates.
$u = $send('products', [['key' => 'p', 'external_id' => (string) $id, 'groups' => ['content', 'seo', 'categories'], 'content' => ['name' => ['bg' => "ССД $tag"]], 'identity' => ['reference' => 'IGNORED'],
    'seo' => ['link_rewrite' => ['en' => "new-$tag"]], 'categories' => ['ids' => [$catB], 'mode' => 'merge'], 'stock_initial' => ['quantity' => 99], ]]);
$item2 = $product($id);
$kx->check('products: only the listed groups and the languages sent change; the slug is preserved', $u['p']['action'] === 'updated' && $item2['name'] === ['en' => "SSD $tag", 'bg' => "ССД $tag"] && $item2['reference'] === "R-$tag" && $item2['link_rewrite']['en'] === "ssd-$tag" && $item2['category_ids'] === [$catA, $catB] && $item2['default_category_id'] === $catA
    && $item2['quantity'] === 7 && $item2['tags'] === $item['tags'] && $item2['attributes'] === $item['attributes'] && $item2['description'] === $item['description'] && $u['p']['content_hash'] === $item2['content_hash'] && $item2['content_hash'] !== $item['content_hash'], (string) json_encode([$u, $item2], JSON_UNESCAPED_UNICODE));
$kx->check('products: deferred indexing marks the product', $value("SELECT indexed FROM {$p}product_shop WHERE id_product = $id AND id_shop = $shop") === 0);
$u = $send('products', [['key' => 'p', 'external_id' => (string) $id, 'groups' => ['categories', 'attributes', 'tags', 'brand', 'suppliers', 'pricing'], 'categories' => ['ids' => [$catB], 'mode' => 'replace'],
    'attributes' => ['mode' => 'replace', 'values' => [['feature_id' => $feature, 'value_id' => $large], ['feature_id' => $textFeature, 'custom' => ['bg' => '2 кг']]]], 'tags' => ['mode' => 'replace', 'values' => ['bg' => []]], 'brand' => ['id' => '0'],
    'suppliers' => ['mode' => 'replace', 'rows' => [], 'default_id' => '0'], 'pricing' => ['price' => '120'], ]]);
$item3 = $product($id);
$custom = (int) ($item3['attributes'][1]['value_id'] ?? 0);
$kx->check('products: replaced relations', $item3['category_ids'] === [$catB] && $item3['default_category_id'] === $catB && $item3['attributes'][0]['value_id'] === $large && count($item3['attributes']) === 2 && $item3['tags'] === ['en' => ['nvme', "ssd $tag"]] && $item3['brand_id'] === '0' && $item3['suppliers'] === [] && $item3['supplier_id'] === '0'
    && $item3['price'] === '120.000000' && $item3['unit_price'] === '10.000000' && $u['p']['content_hash'] === $item3['content_hash'], (string) json_encode($item3, JSON_UNESCAPED_UNICODE));
$kx->check('products: a custom text keeps the languages that were not sent; the old value is gone', $text("SELECT value FROM {$p}feature_value_lang WHERE id_feature_value = $custom AND id_lang = $en") === '1.5 kg' && $text("SELECT value FROM {$p}feature_value_lang WHERE id_feature_value = $custom AND id_lang = $bg") === '2 кг'
    && $value("SELECT COUNT(*) FROM {$p}feature_value WHERE id_feature = $textFeature") === 1);
$none = $send('products', [['key' => 'p', 'external_id' => (string) $id, 'groups' => ['categories'], 'categories' => ['ids' => [], 'mode' => 'replace']], ['key' => 'again', 'external_id' => (string) $id, 'groups' => ['categories'], 'categories' => ['ids' => [], 'mode' => 'replace']]]);
$kx->check('products: in no category → Home', $none['p']['action'] === 'updated' && $none['again']['action'] === 'unchanged' && $product($id)['category_ids'] === [(string) $home] && $product($id)['default_category_id'] === (string) $home, (string) json_encode($none));
$merge = $send('products', [['key' => 'p', 'external_id' => (string) $id, 'groups' => ['attributes'], 'attributes' => ['mode' => 'merge', 'values' => [['feature_id' => $feature, 'value_id' => $small]]]]]);
$kx->check('products: merged attributes join', count($product($id)['attributes']) === 3, (string) json_encode($merge));
$slug = $send('products', [['key' => 'p', 'external_id' => (string) $id, 'groups' => ['seo'], 'seo' => ['link_rewrite' => ['en' => "new-$tag", 'bg' => 'не валиден'], 'preserve_link_rewrite' => false, 'redirect' => ['auto' => true]]]]);
$kx->check('products: a slug changes when asked', $slug['p']['link_rewrite'] === "new-$tag" && $slug['p']['warnings'] === ['link_rewrite_changed'] && preg_match('/\A[a-z0-9-]+\z/', $product($id)['link_rewrite']['bg']) === 1, (string) json_encode($slug));
$r2 = $send('products', [['key' => 'a', 'kx_id' => SignedClient::uuid(), 'groups' => ['content', 'identity'], 'content' => ['name' => ['en' => "Twin $tag"]], 'identity' => ['reference' => "T-$tag"]], ['key' => 'b', 'kx_id' => SignedClient::uuid(), 'groups' => ['content', 'identity'], 'content' => ['name' => ['en' => "Twin $tag"]], 'identity' => ['reference' => "T-$tag"]]]);
$amb = $send('products', [['key' => 'm', 'match_by' => ['reference'], 'groups' => ['content', 'identity'], 'content' => ['name' => ['en' => "Twin $tag"]], 'identity' => ['reference' => "T-$tag"]]]);
$kx->check('products: two matches → match_ambiguous with candidates, nothing created', ($amb['m']['code'] ?? '') === 'match_ambiguous' && $amb['m']['candidates'] === [$r2['a']['external_id'], $r2['b']['external_id']], (string) json_encode($amb));
$kx->check('products: a created product is inactive and at Home unless sent otherwise', $product((int) $r2['a']['external_id'])['active'] === false && $product((int) $r2['a']['external_id'])['category_ids'] === [(string) $home]);
(new Product((int) $r2['b']['external_id']))->delete();
$gone = $send('products', [['key' => 'b', 'kx_id' => $r2['b']['kx_id'], 'groups' => ['content'], 'content' => ['name' => ['en' => "Twin $tag"]]]]);
$kx->check('products: a kx_id whose product was deleted is created anew', $gone['b']['action'] === 'created' && $gone['b']['external_id'] !== $r2['b']['external_id'], (string) json_encode($gone));
$inline = $send('products', [['key' => 'a', 'external_id' => $r2['a']['external_id'], 'groups' => ['content', 'visibility'], 'content' => ['name' => ['en' => "Findme$tag"]], 'visibility' => ['active' => true]]], ['reindex' => 'inline']);
$kx->check('products: inline indexing', $value("SELECT indexed FROM {$p}product_shop WHERE id_product = {$r2['a']['external_id']} AND id_shop = $shop") === 1 && $value("SELECT COUNT(*) FROM {$p}search_index WHERE id_product = {$r2['a']['external_id']}") > 0, (string) json_encode($inline));

// The time budget: what does not fit is skipped, in order.
Configuration::updateGlobalValue('KX_TIME_BUDGET', '1');
$late = $kx->post('products', ['items' => [['key' => 'l', 'groups' => ['content'], 'content' => ['name' => ['en' => "Late $tag"]]]]]);
Configuration::updateGlobalValue('KX_TIME_BUDGET', '25');
$kx->check('products: out of time → skipped with continue', $late['status'] === 200 && $late['data']['continue'] === true && $late['data']['results'][0]['status'] === 'skipped' && $late['data']['results'][0]['code'] === 'time_budget' && $value("SELECT COUNT(*) FROM {$p}product_lang WHERE name = 'Late $tag'") === 0, $late['raw']);

// Variants.
$variantKx = SignedClient::uuid();
$group = ['key' => 'g-c', 'external_id' => null, 'kx_id' => SignedClient::uuid(), 'name' => ['en' => "Colour $tag", 'bg' => "Цвят $tag"], 'public_name' => ['en' => "Colour $tag"], 'group_type' => 'select',
    'values' => [['key' => 'red', 'name' => ['en' => 'Red']], ['key' => 'blue', 'name' => ['en' => 'Blue']]], ];
$groupB = ['key' => 'g-s', 'name' => ['en' => "Storage $tag"], 'values' => [['key' => '1t', 'name' => ['en' => '1 TB']]]];
$variants = ['key' => 'v', 'product_id' => (string) $id, 'option_groups' => [$group, $groupB], 'mode' => 'replace', 'combinations' => [
    ['key' => 'c1', 'kx_id' => $variantKx, 'values' => ['g-c:red', 'g-s:1t'], 'reference' => "R-$tag-R", 'price_impact' => '5.00', 'weight_impact' => '0.1', 'default' => true, 'image_keys' => ['img-9'], 'quantity_initial' => 4],
    ['key' => 'c2', 'values' => ['g-s:1t', 'g-c:blue'], 'reference' => "R-$tag-B", 'default' => false, 'image_keys' => []],
]];
$w = $send('variants', [$variants, ['key' => 'x', 'product_id' => '99999999', 'option_groups' => [], 'combinations' => []],
    ['key' => 'ref', 'product_id' => (string) $id, 'option_groups' => [$group], 'combinations' => [['key' => 'c', 'values' => ['g-c:green']]]],
    ['key' => 'dup', 'product_id' => (string) $id, 'option_groups' => [['key' => 'new', 'name' => ['en' => "Dropped $tag"], 'values' => [['key' => 'v', 'name' => ['en' => 'V']]]]], 'combinations' => [['key' => 'c', 'values' => ['new:v']], ['key' => 'd', 'values' => ['new:v']]]], ]);
$ids = $w['v']['combinations'] ?? [];
$kx->check('variants: groups, values and combinations created', ($w['v']['action'] ?? '') === 'updated' && count($w['v']['groups']) === 2 && count($w['v']['values']) === 3 && count($ids) === 2 && $w['v']['external_id'] === (string) $id && $w['v']['warnings'] === ['image_key_unknown'], (string) json_encode($w));
$kx->check('variants: refused items leave nothing', ($w['x']['code'] ?? '') === 'product_not_found' && ($w['ref']['code'] ?? '') === 'validation_failed' && ($w['dup']['code'] ?? '') === 'combination_duplicate' && $value("SELECT COUNT(*) FROM {$p}attribute_group_lang WHERE name = 'Dropped $tag'") === 0 && $value("SELECT COUNT(*) FROM {$p}product_attribute WHERE id_product = $id") === 2, (string) json_encode($w));
$read = $helpers['readAll']($kx, 'catalog/variants', ['product_id' => (string) $id]);
$one = $read[(int) ($ids['c1'] ?? 0)] ?? [];
$kx->check('variants: the read shows them', $one['reference'] === "R-$tag-R" && $one['price_impact'] === '5.000000' && $one['weight_impact'] === '0.100000' && $one['default'] === true && $one['quantity'] === 4 && count($one['option_values']) === 2 && $one['kx_id'] === $variantKx && $read[(int) $ids['c2']]['default'] === false && $product($id)['type'] === 'variable', (string) json_encode($read));
$kx->check('variants: content_hash is the product\'s', $w['v']['content_hash'] === $hash($id));
$w2 = $send('variants', [$variants]);
$kx->check('variants: the same item again → unchanged, the same hash', $w2['v']['action'] === 'unchanged' && $w2['v']['combinations'] === $ids && $w2['v']['content_hash'] === $w['v']['content_hash'], (string) json_encode($w2));
$renamed = $variants;
$renamed['option_groups'][0]['external_id'] = $w['v']['groups']['g-c'];
$renamed['option_groups'][0]['name'] = ['en' => 'Renamed'];
$renamed['combinations'] = [['key' => 'c2', 'external_id' => $ids['c2'], 'values' => ['g-c:blue', 'g-s:1t'], 'default' => true, 'mpn' => 'M2']];
$renamed['mode'] = 'merge';
$w3 = $send('variants', [$renamed]);
$kx->check('variants: merge keeps the others, moves the default, renames no group', $w3['v']['action'] === 'updated' && $value("SELECT COUNT(*) FROM {$p}product_attribute WHERE id_product = $id") === 2 && $value("SELECT id_product_attribute FROM {$p}product_attribute_shop WHERE id_product = $id AND id_shop = $shop AND default_on = 1") === (int) $ids['c2']
    && $value("SELECT COUNT(*) FROM {$p}attribute_group_lang WHERE name = 'Renamed'") === 0 && $text("SELECT reference FROM {$p}product_attribute WHERE id_product_attribute = {$ids['c2']}") === "R-$tag-B", (string) json_encode($w3));
$renamed['mode'] = 'replace';
$w4 = $send('variants', [$renamed]);
$kx->check('variants: replace deletes the combinations not listed', $w4['v']['action'] === 'updated' && $value("SELECT COUNT(*) FROM {$p}product_attribute WHERE id_product = $id") === 1 && $value("SELECT COUNT(*) FROM {$p}stock_available WHERE id_product = $id AND id_product_attribute = {$ids['c1']}") === 0, (string) json_encode($w4));
$w5 = $send('variants', [['key' => 'v', 'product_id' => (string) $id, 'option_groups' => [], 'combinations' => [], 'mode' => 'replace']]);
$kx->check('variants: none left → a simple product again', $w5['v']['action'] === 'updated' && $product($id)['type'] === 'simple' && $w5['v']['content_hash'] === $hash($id), (string) json_encode($w5));

// Redirects.
$visit = static function (string $path) use ($argv): array {
    $curl = curl_init(rtrim((string) $argv[1], '/').$path);
    $parts = parse_url((string) $argv[1]);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECT_TO => [$parts['host'].':'.($parts['port'] ?? 80).':'.$argv[2]]]);
    $answer = (string) curl_exec($curl);

    return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), preg_match('/^Location: (\S+)/mi', $answer, $m) === 1 ? $m[1] : ''];
};
$other = (int) $r2['a']['external_id'];
$otherUrl = (string) $inline['a']['url'];
[$status, $where] = $visit("/en/kx-old-$tag");
$kx->check('before any redirect the old path is a 404', $status === 404, "$status $where");
$s = $send('seo/redirects', [
    ['key' => 'p', 'entity' => 'product', 'external_id' => (string) $id, 'type' => '301-product', 'target_id' => (string) $other],
    ['key' => '410', 'entity' => 'product', 'external_id' => (string) $other, 'type' => '410'],
    ['key' => 's', 'entity' => 'product', 'type' => 'slug', 'path_from' => "/en/kx-old-$tag", 'target_id' => (string) $other],
    ['key' => 'root', 'entity' => 'product', 'type' => 'slug', 'path_from' => '/', 'target_id' => (string) $other],
    ['key' => 'loop', 'entity' => 'product', 'type' => 'slug', 'path_from' => (string) parse_url($otherUrl, PHP_URL_PATH), 'target_id' => (string) $other],
    ['key' => 'self', 'entity' => 'category', 'external_id' => $catA, 'type' => '301-category', 'target_id' => $catB],
    ['key' => 't', 'entity' => 'product', 'external_id' => (string) $id, 'type' => '301-product', 'target_id' => '99999999'],
]);
$kx->check('redirects: a product\'s own, with the hash of the read', $s['p']['action'] === 'updated' && $product($id)['redirect'] === ['type' => '301-product', 'target_id' => (string) $other] && $s['p']['content_hash'] === $hash($id), (string) json_encode($s));
$kx->check('redirects: 410 from PrestaShop 8, before it 404 with a warning', $product($other)['redirect']['type'] === ($modern ? '410' : '404') && $s['410']['warnings'] === ($modern ? [] : ['redirect_410_unsupported']), (string) json_encode($s));
$kx->check('redirects: refused ones', ($s['root']['field'] ?? '') === 'path_from' && ($s['loop']['code'] ?? '') === 'redirect_loop' && ($s['self']['code'] ?? '') === 'category_active' && ($s['t']['code'] ?? '') === 'target_not_found', (string) json_encode($s));
[$status, $where] = $visit("/en/kx-old-$tag?utm=1");
$kx->check('redirects: an old path is served', $s['s']['action'] === 'created' && $status === 301 && RedirectPath($where) === RedirectPath($otherUrl), "$status $where (want $otherUrl)");
$catUrl = (string) parse_url(Context::getContext()->link->getCategoryLink((int) $catA, null, $en), PHP_URL_PATH);
$off = $send('categories', [['key' => 'a', 'external_id' => $catA, 'name' => ['en' => "Cat A $tag"], 'active' => false]]);
$s = $send('seo/redirects', [['key' => 'c', 'entity' => 'category', 'external_id' => $catA, 'type' => '302-category', 'target_id' => $catB], ['key' => 'again', 'entity' => 'category', 'external_id' => $catA, 'type' => '302-category', 'target_id' => $catB]]);
[$status, $where] = $visit($catUrl);
$kx->check('redirects: an inactive category leads to its target', $s['c']['action'] === 'created' && $s['again']['action'] === 'unchanged' && $status === 302 && RedirectPath($where) === RedirectPath(Context::getContext()->link->getCategoryLink((int) $catB, null, $en)), "$status $where ".json_encode($s));
if ($modern) {
    $send('seo/redirects', [['key' => 'c', 'entity' => 'category', 'external_id' => $catA, 'type' => '410']]);
    [$status] = $visit($catUrl);
    $kx->check('redirects: 410 for a category', $status === 410, (string) $status);
}
$send('categories', [['key' => 'a', 'external_id' => $catA, 'name' => ['en' => "Cat A $tag"], 'active' => true]]);
[$status] = $visit($catUrl);
$kx->check('redirects: a category active again answers itself', $status === 200 && $value("SELECT COUNT(*) FROM {$p}kx_redirect WHERE path_from = '".pSQL($catUrl)."'") === 0, (string) $status);
$kx->check('redirects: the changed slug of seo.redirect.auto is kept', $value("SELECT COUNT(*) FROM {$p}kx_redirect WHERE path_from LIKE '%ssd-$tag%' AND target_type = 'product' AND target_id = $id AND code = 301") === 1);

// Redirects that would loop, chain or lead nowhere.
$link = Context::getContext()->link;
$codes = static function (array $results): array {
    return array_map(static function (array $result): string {
        return $result['code'] ?? $result['action'];
    }, $results);
};
$c = $send('categories', array_map(static function (string $key) use ($tag): array {
    return ['key' => $key, 'name' => ['en' => "Redirect $key $tag"], 'active' => $key === 'd'];
}, ['a', 'b', 'c', 'd']));
[$ra, $rb, $rc, $rd] = array_column($c, 'external_id');
$active = static function (string $id, string $name, bool $on) use ($send, $tag): void {
    $send('categories', [['key' => 'x', 'external_id' => $id, 'name' => ['en' => "Redirect $name $tag"], 'active' => $on]]);
};
$category = static function (string $key, string $from, string $to): array {
    return ['key' => $key, 'entity' => 'category', 'external_id' => $from, 'type' => '301-category', 'target_id' => $to];
};
$s = $send('seo/redirects', [$category('self', $ra, $ra), $category('inactive', $ra, $rb), $category('gone', $ra, '99999999'), $category('ok', $ra, $rd), $category('chain', $rb, $ra)]);
$kx->check('redirects: to itself, to an inactive, a missing and a redirected target are refused, to an active one kept', $codes($s) === ['self' => 'redirect_loop', 'inactive' => 'target_inactive', 'gone' => 'target_not_found', 'ok' => 'created', 'chain' => 'redirect_chain'], (string) json_encode($s));
$pathA = RedirectPath($link->getCategoryLink((int) $ra, null, $en));
[$status, $where] = $visit($pathA);
$kx->check('redirects: served while the target is active', $status === 301 && RedirectPath($where) === RedirectPath($link->getCategoryLink((int) $rd, null, $en)), "$status $where");
$active($rd, 'd', false);
$s = $send('seo/redirects', [$category('loop', $rd, $ra)]);
$kx->check('redirects: one that leads back through the target is a loop', $codes($s) === ['loop' => 'redirect_loop'], (string) json_encode($s));
[$status, $where] = $visit($pathA);
// From 9.1 PrestaShop itself sends an inactive category to its parent.
$kx->check('redirects: not served once the target is inactive', $where === '' ? $status === 404 : RedirectPath($where) === RedirectPath($link->getCategoryLink($home, null, $en)), "$status $where");
$new = static function (string $key, bool $on = true) use ($send, $tag): array {
    $r = $send('products', [['key' => 'p', 'groups' => ['identity', 'content', 'visibility'], 'identity' => ['reference' => "RD-$key-$tag"], 'content' => ['name' => ['en' => "Redirected $key $tag"]], 'visibility' => ['active' => $on]]]);

    return [(string) $r['p']['external_id'], RedirectPath((string) $r['p']['url'])];
};
[[$p1, $path1], [$p2, $path2], [$p3], [$p4]] = [$new('1'), $new('2'), $new('3'), $new('4', false)];
$own = static function (string $key, string $from, string $to, string $type = '301-product'): array {
    return ['key' => $key, 'entity' => 'product', 'external_id' => $from, 'type' => $type, 'target_id' => $to];
};
$s = $send('seo/redirects', [$own('1', $p1, $p2), $own('2', $p2, $p3), $own('back', $p2, $p1), $own('long', $p3, $p1), $own('self', $p3, $p3), $own('off', $p3, $p4), $own('cat', $p3, $rd, '302-category')]);
$kx->check('redirects: products, also active ones whose redirects would close a loop later', $codes($s) === ['1' => 'updated', '2' => 'updated', 'back' => 'redirect_loop', 'long' => 'redirect_loop', 'self' => 'redirect_loop', 'off' => 'target_inactive', 'cat' => 'target_inactive'], (string) json_encode($s));
$s = $send('products', [['key' => 'off', 'external_id' => $p3, 'groups' => ['seo'], 'seo' => ['redirect' => ['type' => '301-product', 'target_id' => $p4]]], ['key' => 'loop', 'external_id' => $p3, 'groups' => ['seo'], 'seo' => ['redirect' => ['type' => '302-product', 'target_id' => $p1]]]]);
$kx->check('products: seo.redirect follows the same rules', $codes($s) === ['off' => 'target_inactive', 'loop' => 'redirect_loop'] && $s['off']['field'] === 'seo.redirect.target_id', (string) json_encode($s));
$send('products', [['key' => 'p', 'external_id' => $p3, 'groups' => ['visibility'], 'visibility' => ['active' => false]]]);
$s = $send('products', [['key' => 'p', 'external_id' => $p2, 'groups' => ['seo', 'content'], 'content' => ['name' => ['en' => "Redirected two $tag"]], 'seo' => ['redirect' => ['type' => '301-product', 'target_id' => $p3]]]]);
$kx->check('products: a redirect they have already does not refuse them when its target is inactive', $codes($s) === ['p' => 'updated'], (string) json_encode($s));

// type slug: every page of the shop keeps its address.
$send('products', [['key' => 'p', 'external_id' => $p2, 'groups' => ['visibility'], 'visibility' => ['active' => true]]]);
$cms = $value("SELECT MIN(c.id_cms) FROM {$p}cms c INNER JOIN {$p}cms_shop s ON s.id_cms = c.id_cms AND s.id_shop = $shop WHERE c.active = 1");
$brandOn = $send('brands', [['key' => 'b', 'name' => "Slug $tag"]])['b']['external_id'];
$taken = [$path1, RedirectPath($link->getCategoryLink((int) $catB, null, $en)), RedirectPath($link->getCMSLink(new CMS($cms, $en), null, null, $en)), RedirectPath($link->getManufacturerLink(new Manufacturer((int) $brandOn, $en), null, $en)),
    '/', '/index.php', '/en/', '/en', '/bg/', '/admin-dev/', '/modules/x', '/module/kronixonconnect/api', '/en/module/kronixonconnect/api', ];
foreach ($db->executeS("SELECT DISTINCT url_rewrite FROM {$p}meta_lang WHERE id_lang = $en AND id_shop = $shop AND url_rewrite <> ''", true, false) as $row) {
    $taken[] = '/'.$row['url_rewrite'];
    $taken[] = '/en/'.$row['url_rewrite'];
}
$slug = static function (string $path, string $target) use ($send): string {
    $r = $send('seo/redirects', [['key' => 's', 'entity' => 'product', 'type' => 'slug', 'path_from' => $path, 'target_id' => $target]])['s'];

    return ($r['code'] ?? $r['action']).' '.($r['field'] ?? '');
};
$rows = $value("SELECT COUNT(*) FROM {$p}kx_redirect");
$answers = array_unique(array_map(static function (string $path) use ($slug, $p2): string {
    return $slug($path, $p2);
}, $taken));
$kx->check('redirects: the address of a page (product, category, CMS, brand, language, controller, folder) is slug_taken: '.count($taken), array_values($answers) === ['slug_taken path_from'] && in_array('/cart', $taken, true) && in_array('/en/order', $taken, true) && in_array('/en/login', $taken, true) && $value("SELECT COUNT(*) FROM {$p}kx_redirect") === $rows, (string) json_encode([$answers, $taken]));
[$status] = $visit($path1);
[$status2, $where] = $visit('/en/');
// From 9.x the default language has no prefix: /en/ leads to the home page.
$kx->check('redirects: and those pages answer', $status === 200 && ($status2 === 200 || RedirectPath($where) === '/'), "$status $status2 $where");
$kx->check('redirects: a path with dots is refused', $slug('/en/../admin-dev/', $p2) === 'validation_failed path_from' && $slug("/old/./$tag", $p2) === 'validation_failed path_from');
$kx->check('redirects: a slug to an inactive or missing target is refused', $slug("/en/gone-$tag", $p4) === 'target_inactive target_id' && $slug("/en/gone-$tag", '99999999') === 'target_not_found target_id');
$oldOwn = str_replace('redirected-2', 'an-old-name', $path2);
$kx->check('redirects: an old address of the target itself, of nobody, of an inactive entity is free', $oldOwn !== $path2 && $slug($oldOwn, $p2) === 'created ' && $slug("/en/gone-$tag", $p2) === 'created ' && $slug(RedirectPath($link->getCategoryLink((int) $rc, null, $en)), $p2) === 'created ');
[$status, $where] = $visit("/en/gone-$tag");
$kx->check('redirects: which is served', $status === 301 && strpos($where, "/$p2-") !== false, "$status $where");
// One row is one path, byte for byte, as for PrestaShop's router: neither case nor a trailing space is ignored.
$written = [$slug('/en/CART', $p2), $slug('/en/Cart', $p2), $slug('/en/Cart', $p2), $slug("/en/KX-$tag", $p2), $slug("/en/KX-$tag ", $p2)];
$served = [];
foreach (['/en/CART', '/en/Cart', '/EN/cart', '/en/cart%20', "/en/KX-$tag", "/en/kx-$tag", "/en/KX-$tag%20", "/en/KX-$tag%20%20"] as $path) {
    $served[$path] = $visit($path)[0];
}
$kx->check('redirects: /en/CART and /en/Cart are two paths, /EN/cart and /en/cart%20 none', $written === ['created ', 'created ', 'unchanged ', 'created ', 'created ']
    && $served === ['/en/CART' => 301, '/en/Cart' => 301, '/EN/cart' => 404, '/en/cart%20' => 404, "/en/KX-$tag" => 301, "/en/kx-$tag" => 404, "/en/KX-$tag%20" => 301, "/en/KX-$tag%20%20" => 404]
    && $value("SELECT COUNT(*) FROM {$p}kx_redirect WHERE path_from IN ('/en/CART', '/en/Cart', '/en/cart', '/en/KX-$tag', '/en/KX-$tag ')") === 4, (string) json_encode([$written, $served]));
$active($rc, 'c', true);
[$status] = $visit(RedirectPath($link->getCategoryLink((int) $rc, null, $en)));
$kx->check('redirects: a page the shop shows again at such a path wins', $status === 200, (string) $status);
[$last] = $new('5');
$slug("/en/last-$tag", $last);
(new Product((int) $last))->delete();
[$status, $where] = $visit("/en/last-$tag");
$kx->check('redirects: a deleted target is the shop\'s own 404', $status === 404 && $where === '', "$status $where");

// The flag that costs the storefront a lookup goes with the last row.
$flag = static function () use ($text, $p): string {
    return $text("SELECT value FROM {$p}configuration WHERE name = 'KX_REDIRECTS'");
};
$before = $flag();
$db->execute("DELETE FROM {$p}kx_redirect");
$send('seo/redirects', [['key' => 'x', 'entity' => 'product', 'external_id' => $p4, 'type' => '404']]);
$off = $flag();
$slug("/en/flag-$tag", $p2);
$kx->check('redirects: KX_REDIRECTS is 1 only while there are any', [$before, $off, $flag()] === ['1', '0', '1'], (string) json_encode([$before, $off, $flag()]));

// Tags: only mode replace removes.
$tags = static function (array $group, array $options = []) use ($send, $p1): string {
    $r = $send('products', [['key' => 'p', 'external_id' => $p1, 'groups' => ['tags'], 'tags' => $group]], $options)['p'];

    return ($r['code'] ?? $r['action']).' '.($r['field'] ?? '');
};
$have = static function () use ($product, $p1): array {
    return (array) $product((int) $p1)['tags'];
};
$steps = [[$tags(['mode' => 'replace', 'values' => ['en' => ["a$tag", "b$tag"], 'bg' => ["v$tag"]]]), $have()], [$tags(['values' => ['en' => ["c$tag"]]]), $have()], [$tags(['mode' => 'merge', 'values' => ['en' => ["d$tag", "a$tag"]]]), $have()],
    [$tags(['mode' => 'merge', 'values' => ['en' => ["d$tag"], 'bg' => []]]), $have()], [$tags(['values' => ['en' => []]]), $have()], [$tags(['mode' => 'replace', 'values' => ['en' => ["d$tag"]]]), $have()], ];
$all = ['en' => ["a$tag", "b$tag", "c$tag", "d$tag"], 'bg' => ["v$tag"]];
$kx->check('tags: no mode and merge add, replace replaces the languages sent', $steps === [['updated ', ['en' => ["a$tag", "b$tag"], 'bg' => ["v$tag"]]], ['updated ', ['en' => ["a$tag", "b$tag", "c$tag"], 'bg' => ["v$tag"]]], ['updated ', $all], ['unchanged ', $all], ['unchanged ', $all],
    ['updated ', ['en' => ["d$tag"], 'bg' => ["v$tag"]]], ], (string) json_encode($steps));
$kx->check('tags: strict_languages refuses a language the shop lacks', $tags(['mode' => 'replace', 'values' => ['fr' => ['x']]], ['strict_languages' => true]) === 'language_unknown tags.values.fr' && $tags(['values' => ['fr' => ['x']]]) === 'unchanged ');

// The member of the item PrestaShop refuses.
$s = $send('products', [['key' => 'name', 'groups' => ['content'], 'content' => ['name' => ['en' => 'ok', 'bg' => 'a<b>']]], ['key' => 'script', 'groups' => ['content'], 'content' => ['name' => ['en' => 'ok'], 'description' => ['en' => '<p onclick="x()">a</p><script>1</script>']]],
    ['key' => 'ean', 'groups' => ['content', 'identity'], 'content' => ['name' => ['en' => 'ok']], 'identity' => ['ean13' => 'abc']], ['key' => 'price', 'groups' => ['content', 'pricing'], 'content' => ['name' => ['en' => 'ok']], 'pricing' => ['price' => '-1']], ]);
$kx->check('products: a value PrestaShop refuses is named by its path', array_column($s, 'field') === ['content.name.bg', 'content.description.en', 'identity.ean13', 'pricing.price'], (string) json_encode($s));
foreach ([$p1, $p2, $p3, $p4] as $gone) {
    (new Product((int) $gone))->delete();
}
$db->execute("DELETE FROM {$p}kx_redirect");

// After multistore.php: a product two shops share, linked by shop 2 to entities of its own.
$shared = $value("SELECT MIN(id_product) FROM {$p}product_shop WHERE id_shop <> $shop AND id_product IN (SELECT id_product FROM {$p}product_attribute)");
if ($shared > 0) {
    $only2 = static function (string $table) use ($p, $shop): string {
        return "SELECT s.id_$table FROM {$p}{$table}_shop s WHERE s.id_shop <> $shop AND s.id_$table NOT IN (SELECT id_$table FROM {$p}{$table}_shop WHERE id_shop = $shop)";
    };
    $ofShop2 = static function () use ($db, $p, $shared, $shop, $only2): string {
        $rows = [];
        foreach (["{$p}category_product WHERE id_product = $shared AND id_category IN (".$only2('category').')', "{$p}feature_product WHERE id_product = $shared AND id_feature IN (".$only2('feature').')',
            "{$p}product_supplier WHERE id_product = $shared AND id_supplier IN (".$only2('supplier').')', "{$p}product_lang WHERE id_product = $shared AND id_shop <> $shop", "{$p}product_shop WHERE id_product = $shared AND id_shop <> $shop",
            "{$p}product_attribute_shop WHERE id_product = $shared AND id_shop <> $shop", "{$p}category_shop WHERE id_shop <> $shop", "{$p}feature_shop WHERE id_shop <> $shop", "{$p}manufacturer_shop WHERE id_shop <> $shop", ] as $from) {
            $rows[] = $db->executeS("SELECT * FROM $from", true, false);
        }

        return (string) json_encode($rows);
    };
    $before = $ofShop2();
    $m = $send('products', [['key' => 'p', 'external_id' => (string) $shared, 'groups' => ['content', 'categories', 'attributes', 'suppliers', 'pricing', 'visibility'], 'content' => ['name' => ['en' => "Shared $tag"]], 'categories' => ['ids' => [$catB], 'mode' => 'replace'],
        'attributes' => ['mode' => 'replace', 'values' => [['feature_id' => $feature, 'value_id' => $small]]], 'suppliers' => ['mode' => 'replace', 'rows' => []], 'pricing' => ['price' => '55.5'], 'visibility' => ['active' => false], ]]);
    $cat2 = $value($only2('category'));
    $brand2 = $value($only2('manufacturer'));
    $n = $send('products', [['key' => 'c', 'external_id' => (string) $shared, 'groups' => ['categories'], 'categories' => ['ids' => [(string) $cat2], 'mode' => 'merge']], ['key' => 'b', 'external_id' => (string) $shared, 'groups' => ['brand'], 'brand' => ['id' => (string) $brand2]]]);
    $o = $send('categories', [['key' => 'c', 'name' => ['en' => "One shop $tag"]], ['key' => 'p', 'name' => ['en' => 'X'], 'parent_external_id' => (string) $cat2]]);
    $q = $send('brands', [['key' => 'b', 'external_id' => (string) $brand2, 'name' => 'Not mine']]);
    $kx->check('multistore: the write changed shop 1 only', $m['p']['action'] === 'updated' && $product($shared)['name']['en'] === "Shared $tag" && $product($shared)['price'] === '55.500000' && $ofShop2() === $before, (string) json_encode($m).$before.$ofShop2());
    $kx->check('multistore: entities of shop 2 are not found', ($n['c']['code'] ?? '') === 'category_not_found' && ($n['b']['code'] ?? '') === 'brand_not_found' && ($o['p']['code'] ?? '') === 'parent_not_found' && ($q['b']['code'] ?? '') === 'not_found', (string) json_encode([$n, $o, $q]));
    $kx->check('multistore: a created category is in shop 1 only', $value("SELECT COUNT(*) FROM {$p}category_shop WHERE id_category = {$o['c']['external_id']}") === 1 && $value("SELECT id_shop FROM {$p}category_shop WHERE id_category = {$o['c']['external_id']}") === $shop);
}

// 100 products in one request, with the budget the platform counts on.
$many = [];
for ($i = 0; $i < 100; $i++) {
    $many[] = ['key' => "m$i", 'kx_id' => SignedClient::uuid(), 'content' => ['name' => ['en' => "Many $tag $i"]]] + $full;
    $many[$i]['identity'] = ['reference' => "M-$tag-$i"];
    $many[$i]['seo'] = ['meta_title' => ['en' => 'x']];
}
$t0 = microtime(true);
$bulk = $kx->post('products', ['items' => $many]);
$create = microtime(true) - $t0;
$t0 = microtime(true);
$noop = $kx->post('products', ['items' => $many]);
$kx->check('products: 100 in one request, then 100 unchanged', $bulk['data']['summary']['ok'] === 100 && $bulk['data']['continue'] === false && array_count_values(array_column($noop['data']['results'], 'action')) === ['unchanged' => 100], substr($bulk['raw'], 0, 600));
echo json_encode(['prestashop' => _PS_VERSION_, 'create_100_s' => round($create, 2), 'noop_100_s' => round(microtime(true) - $t0, 2)]), "\n";
// The checks that follow in the smoke run look at the shop's own catalog.
foreach (array_column($bulk['data']['results'], 'external_id') as $many) {
    (new Product((int) $many))->delete();
}

echo 'PRODUCT WRITES failures=', $kx->failures(), "\n";
exit($kx->failures() === 0 ? 0 : 1);

function RedirectPath(string $url): string
{
    return (string) parse_url($url, PHP_URL_PATH);
}
