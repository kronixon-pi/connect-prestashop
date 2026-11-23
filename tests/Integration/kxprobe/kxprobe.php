<?php

/**
 * Kronixon Connect for PrestaShop — test tooling, not part of the module zip.
 *
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *
 * A third-party module that behaves badly on demand (failed-writes.php): its hooks commit the running
 * transaction, as ps_facetedsearch does by truncating a table, and then fail as /tmp/kxprobe.json says
 * (`{"add": "error", "delete": "exception"}`) for objects whose name or reference holds BOOM. It also
 * stands on actionObjectAddAfter, so that ps-smoke.sh can check that kronixonconnect, installed after
 * it, still runs first there in every shop.
 *
 * For products, categories and brands whose name holds SEEN it writes what each hook of their create and
 * update sees to /tmp/kxprobe-seen.log, as a feed or ERP module would: one line per hook, with the
 * object's `active` and the stored row's (`product_shop` of the shop for a product). For the products
 * listed in /tmp/kxprobe-stock it writes what each stock hook sees there, with the row as another
 * database session reads it (bulk-race.php: the hooks of a bulk run after its commit).
 */
class Kxprobe extends Module
{
    public const SEEN_LOG = '/tmp/kxprobe-seen.log';

    /** The products whose stock hooks are written to SEEN_LOG (bulk-race.php). */
    public const STOCK_WATCH = '/tmp/kxprobe-stock';

    private const HOOKS = [
        'actionProductSave', 'actionProductAdd', 'actionObjectCategoryAddAfter', 'actionObjectManufacturerAddAfter', 'actionObjectFeatureAddAfter',
        'actionObjectCombinationAddAfter', 'actionObjectProductDeleteBefore', 'actionObjectCategoryDeleteBefore', 'actionObjectManufacturerDeleteBefore',
        'actionObjectFeatureDeleteBefore', 'actionObjectCombinationDeleteBefore', 'actionObjectAddAfter',
        'actionProductUpdate', 'actionObjectProductAddAfter', 'actionObjectProductUpdateAfter', 'actionObjectCategoryUpdateAfter', 'actionCategoryUpdate',
        'actionObjectManufacturerUpdateAfter', 'actionObjectImageAddAfter', 'actionObjectImageDeleteBefore',
        'actionUpdateQuantity', 'actionObjectStockAvailableUpdateAfter', 'actionObjectStockAvailableAddAfter',
    ];

    /** The hooks in every shop: an install of an older copy of this probe lacks the newer ones. */
    public function registerAll()
    {
        return $this->registerHook(self::HOOKS, Shop::getShops(true, null, true));
    }

    public function __construct()
    {
        $this->name = 'kxprobe';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'test';
        parent::__construct();
        $this->displayName = 'kxprobe';
        $this->description = 'Fails on demand.';
    }

    public function install()
    {
        return Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS '._DB_PREFIX_.'kxprobe (id INT)') && parent::install() && $this->registerHook(self::HOOKS);
    }

    public function uninstall()
    {
        return parent::uninstall() && Db::getInstance()->execute('DROP TABLE IF EXISTS '._DB_PREFIX_.'kxprobe');
    }

    private function fail($rule, $object)
    {
        $text = '';
        foreach (['name', 'reference', 'legend'] as $field) {
            $text .= ' '.(isset($object->{$field}) ? implode(' ', (array) $object->{$field}) : '');
        }
        $modes = json_decode((string) @file_get_contents('/tmp/kxprobe.json'), true);
        if (strpos($text, 'BOOM') === false || empty($modes[$rule])) {
            return;
        }
        if ($modes[$rule] === 'kill-open') {
            // Dies with the item's transaction still open: nothing it wrote is committed.
            exec('kill -9 '.(int) getmypid());
        }
        if (strpos($rule, 'delete') !== 0) {
            Db::getInstance()->execute('TRUNCATE '._DB_PREFIX_.'kxprobe');
        }
        if ($modes[$rule] === 'error') {
            throw new TypeError('probe');
        }
        if ($modes[$rule] === 'exception') {
            throw new RuntimeException('probe');
        }
        if ($modes[$rule] === 'kill') {
            exec('kill -9 '.(int) getmypid());
        }
        exit;
    }

    private function seen($hook, $object)
    {
        $tables = ['Product' => 'product_shop', 'Category' => 'category', 'Manufacturer' => 'manufacturer'];
        if (! is_object($object) || ! isset($tables[get_class($object)]) || strpos(implode(' ', (array) $object->name), 'SEEN') === false) {
            return;
        }
        $table = $tables[get_class($object)];
        $key = $table === 'product_shop' ? 'id_product' : 'id_'.$table;
        $shop = $table === 'product_shop' ? ' AND id_shop = '.(int) Context::getContext()->shop->id : '';
        // Db::getValue() adds LIMIT 1 itself.
        $row = Db::getInstance()->getValue('SELECT active FROM '._DB_PREFIX_.$table.' WHERE '.$key.' = '.(int) $object->id.$shop, false);
        file_put_contents(self::SEEN_LOG, $hook.' '.$table.' '.(int) $object->id.' object='.(int) $object->active.' row='.(int) $row."\n", FILE_APPEND);
    }

    public function hookActionProductSave($params)
    {
        $this->seen('actionProductSave', $params['product'] ?? null);
        $this->fail('add', $params['product'] ?? null);
    }

    public function hookActionProductAdd($params)
    {
        $this->seen('actionProductAdd', $params['product'] ?? null);
        $this->fail('added', $params['product'] ?? null);
    }

    public function hookActionProductUpdate($params)
    {
        $this->seen('actionProductUpdate', $params['product'] ?? null);
    }

    public function hookActionObjectProductAddAfter($params)
    {
        $this->seen('actionObjectProductAddAfter', $params['object']);
    }

    public function hookActionObjectProductUpdateAfter($params)
    {
        $this->seen('actionObjectProductUpdateAfter', $params['object']);
        $this->fail('updated', $params['object']);
    }

    public function hookActionObjectCategoryUpdateAfter($params)
    {
        $this->seen('actionObjectCategoryUpdateAfter', $params['object']);
    }

    public function hookActionCategoryUpdate($params)
    {
        $this->seen('actionCategoryUpdate', $params['category'] ?? null);
    }

    public function hookActionObjectManufacturerUpdateAfter($params)
    {
        $this->seen('actionObjectManufacturerUpdateAfter', $params['object']);
    }

    public function hookActionObjectCategoryAddAfter($params)
    {
        $this->seen('actionObjectCategoryAddAfter', $params['object']);
        $this->fail('add', $params['object']);
    }

    public function hookActionObjectManufacturerAddAfter($params)
    {
        $this->seen('actionObjectManufacturerAddAfter', $params['object']);
        $this->fail('add', $params['object']);
    }

    public function hookActionObjectFeatureAddAfter($params)
    {
        $this->fail('add', $params['object']);
    }

    public function hookActionObjectCombinationAddAfter($params)
    {
        $this->fail('add', $params['object']);
    }

    public function hookActionObjectProductDeleteBefore($params)
    {
        $this->fail('delete', $params['object']);
    }

    public function hookActionObjectCategoryDeleteBefore($params)
    {
        $this->fail('delete', $params['object']);
    }

    public function hookActionObjectManufacturerDeleteBefore($params)
    {
        $this->fail('delete', $params['object']);
    }

    public function hookActionObjectFeatureDeleteBefore($params)
    {
        $this->fail('delete', $params['object']);
    }

    public function hookActionObjectCombinationDeleteBefore($params)
    {
        $this->fail('delete', $params['object']);
    }

    public function hookActionObjectImageAddAfter($params)
    {
        $this->fail('add', $params['object']);
    }

    public function hookActionObjectImageDeleteBefore($params)
    {
        $this->fail('delete', $params['object']);
    }

    /** Nothing: installed before kronixonconnect it stands first here until kronixonconnect moves itself. */
    public function hookActionObjectAddAfter($params)
    {
    }

    public function hookActionUpdateQuantity($params)
    {
        $this->stockSeen('actionUpdateQuantity', (int) $params['id_product'], (int) $params['id_product_attribute'], (int) $params['quantity'], isset($params['id_shop']) ? (int) $params['id_shop'] : null);
    }

    public function hookActionObjectStockAvailableUpdateAfter($params)
    {
        $object = $params['object'];
        $this->stockSeen('actionObjectStockAvailableUpdateAfter', (int) $object->id_product, (int) $object->id_product_attribute, (int) $object->quantity, null);
    }

    public function hookActionObjectStockAvailableAddAfter($params)
    {
        $object = $params['object'];
        $this->stockSeen('actionObjectStockAvailableAddAfter', (int) $object->id_product, (int) $object->id_product_attribute, (int) $object->quantity, null);
    }

    /**
     * For the products listed in STOCK_WATCH (ids, comma separated), what a stock hook gets and what
     * another database session reads of the row meanwhile: the value a feed module that reads in a
     * connection of its own (or a queue worker) would see, which is the committed one.
     */
    private function stockSeen($hook, $product, $variant, $quantity, $shop)
    {
        $watched = explode(',', trim((string) @file_get_contents(self::STOCK_WATCH)));
        if (! in_array((string) $product, $watched, true)) {
            return;
        }
        $context = (int) Context::getContext()->shop->id;
        $other = new PDO('mysql:host='._DB_SERVER_.';dbname='._DB_NAME_, _DB_USER_, _DB_PASSWD_);
        $committed = $other->query('SELECT quantity FROM '._DB_PREFIX_.'stock_available WHERE id_product = '.$product.' AND id_product_attribute = '.$variant.' AND id_shop = '.$context)->fetchColumn();
        file_put_contents(self::SEEN_LOG, $hook.' stock '.$product.':'.$variant.' quantity='.$quantity.' committed='.(int) $committed.' shop='.$context.' id_shop='.($shop === null ? '-' : $shop)."\n", FILE_APPEND);
    }
}
