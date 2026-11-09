<?php

declare(strict_types=1);
/**
 * Kronixon Connect for PrestaShop — test tooling, not part of the module zip.
 *
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *
 * Multistore on a real shop: turns multistore on, adds shop 2 in a second shop group and shares a
 * product with combinations between the shops. Shop 2 gets a category, a feature (and value), a
 * supplier, a brand and an image of its own. Checks, through the readers in process:
 *  - the product's brand and default supplier, set to shop 2's, read "0" in shop 1;
 *  - after shop 2 links the shared product to its category, feature value, supplier and (one of
 *    its combinations) to its image, shop 1's product and variant items, content_hash included,
 *    are unchanged and name none of them;
 *  - shop 2 reads them.
 * Changes the shop for good: run it last (scripts/ps-smoke.sh does). Run inside the shop container as
 * www-data. Prints one JSON line; exit 1 on any failed check.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = getenv('PS_DOMAIN') ?: 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
require $root.'/config/config.inc.php';

use Kronixon\Connect\Api\Request;
use Kronixon\Connect\Catalog\CatalogEndpoints;
use Kronixon\Connect\Catalog\PageQuery;
use Kronixon\Connect\Catalog\PrestaShopImageUrls;
use Kronixon\Connect\Catalog\PrestaShopScope;
use Kronixon\Connect\Catalog\PrestaShopSql;
use Kronixon\Connect\Compat\Version;

require_once dirname(__DIR__, 2).'/src/Autoloader.php';
Kronixon\Connect\Autoloader::register();

$db = Db::getInstance();
$p = _DB_PREFIX_;
$failures = [];
$run = static function (string $sql) use ($db): void {
    if (! $db->execute($sql, false)) {
        throw new RuntimeException($db->getMsgError().' in '.$sql);
    }
};
$value = static function (string $sql) use ($db): int {
    return (int) $db->getValue($sql, false);
};
/* INSERT … SELECT of one row of $table (alias t, matching $where) with the column expressions in $set. */
$copy = static function (string $table, string $where, array $set) use ($db, $p, $run): void {
    $columns = [];
    foreach ($db->executeS("SELECT column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '$p$table' ORDER BY ordinal_position", true, false) as $row) {
        $columns[] = (string) $row['c'];
    }
    $select = array_map(static function (string $c) use ($set): string {
        return $set[$c] ?? 't.`'.$c.'`';
    }, $columns);
    $run("INSERT INTO `$p$table` (`".implode('`, `', $columns).'`) SELECT '.implode(', ', $select)." FROM `$p$table` t WHERE $where LIMIT 1");
};
/* Every item of $endpoint for the shop of $context (the readers in process, page by page). */
$read = static function (Context $context, string $endpoint, string $params): array {
    $scope = PrestaShopScope::of($context, Version::select(_PS_VERSION_));
    $reader = CatalogEndpoints::readers(new PrestaShopSql(Db::getInstance(_PS_USE_SQL_SLAVE_), _DB_PREFIX_), new PrestaShopImageUrls($context->link))[$endpoint]($scope);
    $items = [];
    $cursor = '';
    do {
        $query = "path=catalog/$endpoint&limit=500&$params".($cursor === '' ? '' : "&cursor=$cursor");
        $page = $reader->read(PageQuery::from(Request::fromServer(['REQUEST_METHOD' => 'GET', 'QUERY_STRING' => $query], ''), $scope, $reader->maxLimit(), $reader->maxCursorVariant()));
        $answer = json_decode((string) json_encode($page->answer($reader->maxId(), gmdate('Y-m-d\TH:i:s\Z'))), true);
        $items = array_merge($items, $answer['data']);
        $cursor = (string) $answer['meta']['next_cursor'];
    } while ($answer['meta']['has_more']);

    return $items;
};
$contextOf = static function (int $shopId): Context {
    $context = Context::getContext()->cloneContext();
    $context->shop = new Shop($shopId);
    Shop::setContext(Shop::CONTEXT_SHOP, $shopId);
    $context->link = new Link();

    return $context;
};
$check = static function (string $name, $want, $got) use (&$failures): void {
    if ($want !== $got) {
        $failures[] = $name;
        fwrite(STDERR, "FAIL $name: expected ".json_encode($want).', got '.json_encode($got)."\n");
    }
};
$column = static function (array $items, string $key): array {
    return array_map('strval', array_column($items, $key));
};

// Shop 2 in a new group, every language of shop 1.
$group = $value("SELECT MAX(id_shop_group) FROM {$p}shop_group") + 1;
$shop2 = $value("SELECT MAX(id_shop) FROM {$p}shop") + 1;
Configuration::updateGlobalValue('PS_MULTISHOP_FEATURE_ACTIVE', 1);
$copy('shop_group', 't.id_shop_group = 1', ['id_shop_group' => (string) $group, 'name' => "'Group 2'"]);
$copy('shop', 't.id_shop = 1', ['id_shop' => (string) $shop2, 'id_shop_group' => (string) $group, 'name' => "'Shop 2'"]);
$copy('shop_url', 't.id_shop = 1 AND t.main = 1', ['id_shop_url' => 'NULL', 'id_shop' => (string) $shop2, 'domain' => "'shop$shop2.invalid'", 'domain_ssl' => "'shop$shop2.invalid'"]);
$run("INSERT INTO {$p}lang_shop (id_lang, id_shop) SELECT id_lang, $shop2 FROM {$p}lang_shop WHERE id_shop = 1");

// The shared product: the first one with combinations and images in shop 1.
$product = $value("SELECT MIN(pa.id_product) FROM {$p}product_attribute pa INNER JOIN {$p}image_shop i ON i.id_product = pa.id_product AND i.id_shop = 1");
$combination = $value("SELECT MIN(id_product_attribute) FROM {$p}product_attribute WHERE id_product = $product");
if ($product === 0) {
    fwrite(STDERR, "FAIL no product with combinations and images in shop 1\n");
    exit(1);
}
$copy('product_shop', "t.id_product = $product AND t.id_shop = 1", ['id_shop' => (string) $shop2]);
$run("INSERT INTO {$p}product_lang (id_product, id_shop, id_lang, name, link_rewrite) SELECT id_product, $shop2, id_lang, CONCAT(name, ' 2'), link_rewrite FROM {$p}product_lang WHERE id_product = $product AND id_shop = 1");
$run("INSERT INTO {$p}product_attribute_shop (id_product, id_product_attribute, id_shop, price, wholesale_price, weight, unit_price_impact, minimal_quantity)"
    ." SELECT id_product, id_product_attribute, $shop2, price, wholesale_price, weight, unit_price_impact, minimal_quantity FROM {$p}product_attribute_shop WHERE id_product = $product AND id_shop = 1");

// Shop 2's own entities.
$category = $value("SELECT MAX(id_category) FROM {$p}category") + 1;
$copy('category', 't.id_category = 2', ['id_category' => (string) $category, 'id_parent' => '2', 'level_depth' => '2', 'nleft' => '0', 'nright' => '0', 'is_root_category' => '0']);
$run("INSERT INTO {$p}category_shop (id_category, id_shop, position) VALUES ($category, $shop2, 0)");
$feature = $value("SELECT MAX(id_feature) FROM {$p}feature") + 1;
$featureValue = $value("SELECT MAX(id_feature_value) FROM {$p}feature_value") + 1;
$run("INSERT INTO {$p}feature (id_feature, position) VALUES ($feature, 0)");
$run("INSERT INTO {$p}feature_shop (id_feature, id_shop) VALUES ($feature, $shop2)");
$run("INSERT INTO {$p}feature_value (id_feature_value, id_feature, custom) VALUES ($featureValue, $feature, 0)");
$supplier = $value("SELECT MAX(id_supplier) FROM {$p}supplier") + 1;
$run("INSERT INTO {$p}supplier (id_supplier, name, date_add, date_upd, active) VALUES ($supplier, 'Shop 2 supplier', NOW(), NOW(), 1)");
$run("INSERT INTO {$p}supplier_shop (id_supplier, id_shop) VALUES ($supplier, $shop2)");
$brand = $value("SELECT MAX(id_manufacturer) FROM {$p}manufacturer") + 1;
$run("INSERT INTO {$p}manufacturer (id_manufacturer, name, date_add, date_upd, active) VALUES ($brand, 'Shop 2 brand', NOW(), NOW(), 1)");
$run("INSERT INTO {$p}manufacturer_shop (id_manufacturer, id_shop) VALUES ($brand, $shop2)");
$image = $value("SELECT MAX(id_image) FROM {$p}image") + 1;
$run("INSERT INTO {$p}image (id_image, id_product, position, cover) VALUES ($image, $product, 99, NULL)");
$run("INSERT INTO {$p}image_shop (id_product, id_image, id_shop, cover) VALUES ($product, $image, $shop2, NULL)");
Shop::cacheShops(true);

// The brand and the default supplier are product columns, shared by every shop: set them to shop 2's.
$run("UPDATE {$p}product SET id_manufacturer = $brand, id_supplier = $supplier WHERE id_product = $product");
$shop1 = $contextOf(1);
$products = $read($shop1, 'products', "ids=$product&fields=full");
$check('shop 1: brand and default supplier of shop 2', ['0', '0'], [$products[0]['brand_id'] ?? null, $products[0]['supplier_id'] ?? null]);
$before = [$products[0]['content_hash'] ?? null, hash('sha256', (string) json_encode([$products, $read($shop1, 'variants', "product_id=$product")]))];

// Shop 2 links the shared product to its own entities.
$run("INSERT INTO {$p}category_product (id_category, id_product, position) VALUES ($category, $product, 0)");
$run("INSERT INTO {$p}feature_product (id_feature, id_product, id_feature_value) VALUES ($feature, $product, $featureValue)");
$run("INSERT INTO {$p}product_supplier (id_product, id_product_attribute, id_supplier, product_supplier_reference, product_supplier_price_te, id_currency) VALUES ($product, 0, $supplier, 'S2', 1, 0)");
$run("INSERT INTO {$p}product_attribute_image (id_product_attribute, id_image) VALUES ($combination, $image)");

$products = $read($shop1, 'products', "ids=$product&fields=full");
$variants = $read($shop1, 'variants', "product_id=$product");
$check(
    'shop 1: content_hash and sha256 of the product and variant items unchanged by shop 2',
    $before,
    [$products[0]['content_hash'] ?? null, hash('sha256', (string) json_encode([$products, $variants]))]
);
$check("shop 1: category $category of shop 2", false, in_array((string) $category, $products[0]['category_ids'], true));
$check("shop 1: feature $feature of shop 2", false, in_array((string) $feature, $column($products[0]['attributes'], 'feature_id'), true));
$check("shop 1: supplier $supplier of shop 2", false, in_array((string) $supplier, $column($products[0]['suppliers'], 'supplier_id'), true));
$check("shop 1: image $image of shop 2 on combination $combination", false, in_array((string) $image, array_merge([], ...array_column($variants, 'image_ids')), true));

$shop2Context = $contextOf($shop2);
$products = $read($shop2Context, 'products', "ids=$product&fields=full");
$variants = $read($shop2Context, 'variants', "product_id=$product");
$check('shop 2: the shared product', [(string) $product], $column($products, 'external_id'));
$check('shop 2: its category', [(string) $category], $products[0]['category_ids'] ?? null);
$check('shop 2: its feature', [(string) $feature], $column($products[0]['attributes'] ?? [], 'feature_id'));
$check('shop 2: its supplier', [(string) $supplier], $column($products[0]['suppliers'] ?? [], 'supplier_id'));
$check('shop 2: its brand and default supplier', [(string) $brand, (string) $supplier], [$products[0]['brand_id'] ?? null, $products[0]['supplier_id'] ?? null]);
$byId = array_column($variants, 'image_ids', 'external_id');
$check("shop 2: its image on combination $combination", [(string) $image], $byId[(string) $combination] ?? null);

echo json_encode(['multistore' => $failures === [] ? 'ok' : 'failed', 'shop' => $shop2, 'product' => $product, 'combination' => $combination,
    'category' => $category, 'feature' => $feature, 'supplier' => $supplier, 'brand' => $brand, 'image' => $image, 'failures' => $failures, ]), "\n";
exit($failures === [] ? 0 : 1);
