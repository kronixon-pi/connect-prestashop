<?php

declare(strict_types=1);
/**
 * Kronixon Connect for PrestaShop — test tooling, not part of the module zip.
 *
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *
 * Store events on a real shop (scripts/ps-smoke.sh, inside the shop container as www-data, after
 * pair.php): what the back office, an order and other modules change is recorded in kx_outbox, what the
 * platform writes is not; rows of one entity are one event; a module that throws after ours, a failed
 * insert and a rolled-back transaction; GET events and POST events/ack; the time an order spends in our
 * hook. One line per check; exit 1 on any failure.
 *
 *   php events-capture.php <shop base URL> <apache address, e.g. 127.0.0.1:80>
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

use Kronixon\Connect\Install\Hooks;
use Kronixon\Connect\Outbox\CatalogEnricher;
use Kronixon\Connect\Outbox\Events;
use Kronixon\Connect\Outbox\OutboxStore;
use Kronixon\Connect\Outbox\Recorder;

$module = Module::getInstanceByName('kronixonconnect');
$kx = new SignedClient((string) ($argv[1] ?? ''), (string) ($argv[2] ?? ''));
$db = Db::getInstance();
$p = _DB_PREFIX_;
$helpers = kxShopHelpers($db, $p);
[$value, $text] = [$helpers['value'], $helpers['text']];
Configuration::updateGlobalValue('KX_RATE_LIMIT', '100000');
Configuration::deleteByName(Recorder::GAP);
// A heartbeat just sent: a pull (GET events) would record a due one among the rows this script counts.
Configuration::updateGlobalValue(Kronixon\Connect\Outbox\Flusher::HEARTBEAT_AT, (string) time());
$context = Context::getContext();
$shop = (int) $context->shop->id;
$lang = (int) Configuration::get('PS_LANG_DEFAULT');
// Hooks as in the back office: an employee that is not loaded, so no module is left out.
$employee = new Employee();
$employee->id = 0;
$context->employee = $employee;
Cache::clean('hook_module_exec_list_*');
$tag = substr(SignedClient::uuid(), 0, 8);
$sqlDb = new Kronixon\Connect\Write\ShopDatabase($db, $p);
$store = new OutboxStore($sqlDb);
$events = new Events(new CatalogEnricher($sqlDb, Kronixon\Connect\Catalog\PrestaShopScope::of($context, Kronixon\Connect\Compat\Version::select(_PS_VERSION_)), new Kronixon\Connect\Catalog\PrestaShopImageUrls($context->link)));
$clear = static function () use ($db, $p): void {
    $db->execute("DELETE FROM {$p}kx_outbox");
};
// The events the pending rows are when they are sent: type:external_id => event.
$built = static function () use ($store, $events): array {
    $out = [];
    foreach ($events->build($store->pending(100000), true) as $entry) {
        if ($entry['event'] !== null) {
            $payload = (array) $entry['event']['payload'];
            $out[$entry['event']['type'].':'.$entry['event']['external_id'].(isset($payload['variant_id']) ? ':'.$payload['variant_id'] : '')] = $entry['event'];
        }
    }

    return $out;
};
$rowCount = static function () use ($value, $p): int {
    return $value("SELECT COUNT(*) FROM {$p}kx_outbox");
};

$caps = $kx->get('capabilities')['data']['capabilities'] ?? [];
$kx->check('capabilities announce events.pull', ($caps['events.pull'] ?? null) === true, (string) json_encode($caps));
$kx->check('kx_outbox has the 0.6.0 shape', $value("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '{$p}kx_outbox' AND column_name IN ('entity_key', 'batch_id')") === 2);
$first = [];
foreach (Hooks::FIRST as $hook) {
    $id = (int) Hook::getIdByName($hook);
    if ($id > 0 && $module->isRegisteredInHook($hook)) {
        $position = $value("SELECT hm.position FROM {$p}hook_module hm WHERE hm.id_hook = $id AND hm.id_shop = $shop AND hm.id_module = ".(int) $module->id);
        if ($position !== 1) {
            $first[] = "$hook=$position";
        }
    }
}
$kx->check('the module runs first on every change hook', $first === [], implode(', ', $first));

// The platform's own writes record nothing.
$clear();
$made = $kx->post('products', ['items' => [['key' => 'a', 'kx_id' => SignedClient::uuid(), 'groups' => ['content', 'visibility', 'pricing'],
    'content' => ['name' => ['en' => "Events $tag"]], 'visibility' => ['active' => true], 'pricing' => ['price' => '10'], 'stock_initial' => ['quantity' => 50]]]]);
$a = (int) ($made['data']['results'][0]['external_id'] ?? 0);
$kx->post('stock/bulk', ['mode' => 'safe', 'rows' => [['product_id' => (string) $a, 'variant_id' => '0', 'quantity' => 40]]]);
$kx->post('prices/bulk', ['rows' => [['product_id' => (string) $a, 'variant_id' => '0', 'price' => '11']]]);
$kx->check('the platform\'s writes (product, stock, price) record no event', $a > 0 && $rowCount() === 0, $rowCount().' rows; '.(string) json_encode($made));

// A back-office edit: one event with the content hash GET catalog/products reads.
$product = new Product($a);
$product->name[$lang] = "Events $tag edited";
$product->update();
$now = $built();
$read = $kx->get('catalog/products', ['ids' => (string) $a])['data']['data'][0] ?? [];
$kx->check('a back-office edit is one product.updated event', array_keys($now) === ["product.updated:$a"], (string) json_encode(array_keys($now)));
$kx->check('… with the content_hash of GET catalog/products and the kx_id', ($now["product.updated:$a"]['payload']['content_hash'] ?? null) === ($read['content_hash'] ?? 'x')
    && ($now["product.updated:$a"]['kx_id'] ?? null) === ($read['kx_id'] ?? 'x'), (string) json_encode([$now, $read['content_hash'] ?? null]));

// A hundred edits of one product: one event.
$clear();
for ($i = 0; $i < 100; $i++) {
    $product = new Product($a);
    $product->reference = "EV-$tag-$i";
    $product->update();
}
$now = $built();
$kx->check('100 edits of one product are one event', array_keys($now) === ["product.updated:$a"] && $rowCount() >= 100, $rowCount().' rows, '.json_encode(array_keys($now)));

// A price changed in the back office.
$clear();
$product = new Product($a);
$product->price = 12.5;
$product->update();
$now = $built();
$kx->check('a price edit is price.updated with the new price', ($now["price.updated:$a:0"]['payload']['price'] ?? null) === '12.500000' && isset($now["product.updated:$a"]), (string) json_encode($now));
$clear();
$product = new Product($a);
$product->reference = "EV-$tag-same";
$product->update();
$kx->check('an edit that keeps the price is no price event', ! isset($built()["price.updated:$a:0"]));

// Inside a transaction the row is the change's: a rollback takes both. A module hooked to a product save
// may commit the transaction (ps_facetedsearch truncates a table): then both stay. Never one alone.
$clear();
$db->execute('START TRANSACTION');
$brand = new Manufacturer((int) $value("SELECT MIN(id_manufacturer) FROM {$p}manufacturer"));
$brandName = $brand->name;
$brand->name = "Rolled $tag";
$brand->update();
$inside = $rowCount();
$db->execute('ROLLBACK');
$kx->check('a change rolled back takes its event along', $inside === 1 && $rowCount() === 0 && $text("SELECT name FROM {$p}manufacturer WHERE id_manufacturer = ".(int) $brand->id) === $brandName, "inside $inside, after ".$rowCount());
$clear();
$db->execute('START TRANSACTION');
$product = new Product($a);
$product->reference = "EV-$tag-rolled";
$product->update();
$db->execute('ROLLBACK');
$kept = $text("SELECT reference FROM {$p}product WHERE id_product = $a") === "EV-$tag-rolled";
$kx->check('a product save in a transaction: its event stays exactly when the change does ('.($kept ? 'a hook committed it' : 'rolled back').')', $kept === isset($built()["product.updated:$a"]), $rowCount().' rows');

// Stock as an order changes it (StockAvailable), then a real order (PaymentModule::validateOrder).
$clear();
StockAvailable::updateQuantity($a, 0, -3, $shop);
$now = $built();
$kx->check('a stock change is stock.updated with the quantity', ($now["stock.updated:$a:0"]['payload']['quantity'] ?? null) === 37, (string) json_encode($now));

// A storefront request has the front container (FrontController::buildContainer()); the cart needs it on 9.x.
if (! isset($context->container) && class_exists('PrestaShop\PrestaShop\Adapter\ContainerBuilder')) {
    $context->container = PrestaShop\PrestaShop\Adapter\ContainerBuilder::getContainer('front', false);
}
$order = static function (int $productId, int $quantity) use ($context, $shop, $lang, $tag): ?int {
    $customer = new Customer();
    $customer->firstname = 'Kx';
    $customer->lastname = 'Probe';
    $customer->email = 'kx-'.$tag.'-'.mt_rand().'@example.com';
    $customer->passwd = md5((string) mt_rand());
    $customer->id_shop = $shop;
    $customer->add();
    $address = new Address();
    $address->id_customer = (int) $customer->id;
    $address->firstname = 'Kx';
    $address->lastname = 'Probe';
    $address->address1 = '1 Test Street';
    $address->city = 'Sofia';
    $address->postcode = '1000';
    $address->id_country = (int) Country::getByIso('BG');
    $address->alias = 'kx';
    $address->add();
    $cart = new Cart();
    $cart->id_customer = (int) $customer->id;
    $cart->id_address_delivery = (int) $address->id;
    $cart->id_address_invoice = (int) $address->id;
    $cart->id_lang = $lang;
    $cart->id_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
    $cart->id_shop = $shop;
    $cart->id_shop_group = (int) $context->shop->id_shop_group;
    $cart->secure_key = $customer->secure_key;
    $cart->add();
    $context->cart = $cart;
    $context->customer = $customer;
    $context->currency = new Currency((int) $cart->id_currency);
    $cart->updateQty($quantity, $productId);
    $payment = Module::getInstanceByName('ps_wirepayment');
    if (! $payment instanceof PaymentModule) {
        return null;
    }
    $payment->validateOrder((int) $cart->id, (int) Configuration::get('PS_OS_BANKWIRE'), (float) $cart->getOrderTotal(true, Cart::BOTH), 'Bank wire', null, [], null, false, $customer->secure_key);

    return (int) $payment->currentOrder;
};
$clear();
$before = $value("SELECT quantity FROM {$p}stock_available WHERE id_product = $a AND id_product_attribute = 0 AND id_shop = $shop");
$orderId = $order($a, 2);
$after = $value("SELECT quantity FROM {$p}stock_available WHERE id_product = $a AND id_product_attribute = 0 AND id_shop = $shop");
$now = $built();
$kx->check(
    'an order is a stock.updated event of the ordered product',
    $orderId > 0 && $after === $before - 2 && ($now["stock.updated:$a:0"]['payload']['quantity'] ?? null) === $after,
    "order $orderId, $before → $after, ".json_encode($now)
);

// The time an order spends in the module's stock hook: orders with and without it registered, in turns
// (the same shop, the same product), medians of 12 each; and the rows one order records.
$timed = static function () use ($order, $a): float {
    $start = microtime(true);
    $order($a, 1);

    return (microtime(true) - $start) * 1000;
};
$median = static function (array $values): float {
    sort($values);

    return (float) $values[intdiv(count($values), 2)];
};
$timed();
[$with, $without, $perOrder] = [[], [], []];
for ($round = 0; $round < 12; $round++) {
    $rowsBefore = $rowCount();
    $with[] = $timed();
    $perOrder[] = $rowCount() - $rowsBefore;
    $module->unregisterHook('actionUpdateQuantity');
    Cache::clean('hook_module_exec_list_*');
    $without[] = $timed();
    $module->registerHook('actionUpdateQuantity');
    Hooks::stayFirst($module);
    Cache::clean('hook_module_exec_list_*');
}
$recorder = new Recorder($sqlDb, Kronixon\Connect\Services::settings(), new Kronixon\Connect\Support\SystemClock());
$start = microtime(true);
for ($i = 0; $i < 200; $i++) {
    $recorder->record(new Kronixon\Connect\Outbox\Change('stock.updated', (string) $a, ['variant_id' => '0', 'quantity' => $i], '0'));
}
$insert = (microtime(true) - $start) / 200 * 1000;
printf("  time  order (median of 12): %.1f ms with the hook, %.1f ms without; %d outbox row(s) per order; one recorded change %.2f ms\n", $median($with), $median($without), $median($perOrder), $insert);
$kx->check('an order of one line records one stock row', $median($perOrder) === 1.0, json_encode($perOrder));
$kx->check('a recorded change costs at most 3 ms', $insert <= 3.0, sprintf('%.2f ms', $insert));

// Other entities of the back office.
$clear();
$category = new Category();
$category->name = [$lang => "Ev cat $tag"];
$category->link_rewrite = [$lang => "ev-cat-$tag"];
$category->id_parent = (int) Configuration::get('PS_HOME_CATEGORY');
$category->active = true;
$category->add();
$category->name[$lang] = "Ev cat $tag 2";
$category->update();
$brand = new Manufacturer((int) $value("SELECT MIN(id_manufacturer) FROM {$p}manufacturer"));
$brand->update();
$combination = new Combination();
$combination->id_product = $a;
$combination->price = 1.5;
$combination->add();
$combination->price = 2.5;
$combination->update();
$priced = $built()["price.updated:$a:".(int) $combination->id]['payload']['price'] ?? null;
$kx->check('a combination\'s price edit is price.updated with the variant\'s price', $priced === '15.000000', (string) $priced);
$featureClass = 'FeatureValue';
$featureValue = new $featureClass();
$featureValue->id_feature = (int) $value("SELECT MIN(id_feature) FROM {$p}feature");
$featureValue->value = [$lang => "ev-$tag"];
$featureValue->add();
$image = new Image();
$image->id_product = $a;
$image->position = Image::getHighestPosition($a) + 1;
$image->cover = false;
$image->add();
$imageId = (int) $image->id;
$image->delete();
$combinationId = (int) $combination->id;
$combination->delete();
$categoryId = (int) $category->id;
$category->delete();
$now = $built();
$expected = ["category.created:$categoryId", "category.updated:$categoryId", "category.deleted:$categoryId", 'brand.updated:'.(int) $brand->id,
    "combination.created:$combinationId", "combination.updated:$combinationId", "combination.deleted:$combinationId",
    'attribute_value.created:'.(int) $featureValue->id, "image.created:$imageId", "image.deleted:$imageId", ];
$missing = array_values(array_diff($expected, array_keys($now)));
$kx->check('categories, brands, combinations, feature values and images are events', $missing === [], 'missing '.json_encode($missing).' of '.json_encode(array_keys($now)));
$featureValue->delete();

// A module that throws after ours: the change is saved and its event recorded.
$clear();
$probe = Module::getInstanceByName('kxprobe');
if ($probe instanceof Module && Module::isInstalled('kxprobe')) {
    file_put_contents('/tmp/kxprobe.json', (string) json_encode(['updated' => 'exception']));
    $product = new Product($a);
    $product->name[$lang] = "Events $tag BOOM";
    $thrown = false;
    try {
        $product->update();
    } catch (Throwable $e) {
        $thrown = true;
    }
    @unlink('/tmp/kxprobe.json');
    $kx->check(
        'a module that throws after ours: the change is saved and its event recorded ('.(_PS_VERSION_ < '9' ? 'thrown' : 'caught by PrestaShop').')',
        $text("SELECT name FROM {$p}product_lang WHERE id_product = $a AND id_lang = $lang AND id_shop = $shop") === "Events $tag BOOM" && isset($built()["product.updated:$a"]),
        $thrown ? 'thrown' : 'not thrown'
    );
} else {
    echo "  skip a module that throws (kxprobe not installed)\n";
}

// Our own insert fails: the change is saved, the gap is kept for the next flush.
$clear();
$db->execute("ALTER TABLE {$p}kx_outbox ADD CONSTRAINT kx_probe_fail CHECK (type <> 'brand.updated')");
$brand = new Manufacturer((int) $value("SELECT MIN(id_manufacturer) FROM {$p}manufacturer"));
$brand->name = "Brand $tag";
$saved = $brand->update();
$db->execute("ALTER TABLE {$p}kx_outbox DROP CONSTRAINT kx_probe_fail");
$kx->check('a failed insert of ours: the change is saved and the gap kept', $saved && $text("SELECT name FROM {$p}manufacturer WHERE id_manufacturer = ".(int) $brand->id) === "Brand $tag"
    && $rowCount() === 0 && (int) Configuration::getGlobalValue(Recorder::GAP) > 0);
Configuration::deleteByName(Recorder::GAP);

// GET events and POST events/ack.
$clear();
for ($i = 0; $i < 3; $i++) {
    StockAvailable::updateQuantity($a, 0, 1, $shop);
}
$page = $kx->get('events', ['limit' => '2']);
$ids = array_column($page['data']['data'] ?? [], 'event_id');
$rest = $kx->get('events', ['limit' => '2', 'cursor' => (string) ($page['data']['meta']['next_cursor'] ?? '')]);
$kx->check('GET events walks the pending rows', $page['status'] === 200 && count($ids) === 2 && ($page['data']['meta']['has_more'] ?? false) === true && count($rest['data']['data'] ?? []) === 1, $page['raw']);
$ack = $kx->post('events/ack', ['ids' => $ids]);
$kx->check('POST events/ack marks them sent', ($ack['data']['acknowledged'] ?? null) === 2 && $value("SELECT COUNT(*) FROM {$p}kx_outbox WHERE status = 'sent'") === 2, $ack['raw']);
$kx->check('… and GET events lists only the rest', count($kx->get('events')['data']['data'] ?? []) === 1);

$clear();
exit($kx->failures() === 0 ? 0 : 1);
