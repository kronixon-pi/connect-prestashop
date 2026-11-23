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
 */
class Kxprobe extends Module
{
    private const HOOKS = [
        'actionProductSave', 'actionProductAdd', 'actionObjectCategoryAddAfter', 'actionObjectManufacturerAddAfter', 'actionObjectFeatureAddAfter',
        'actionObjectCombinationAddAfter', 'actionObjectProductDeleteBefore', 'actionObjectCategoryDeleteBefore', 'actionObjectManufacturerDeleteBefore',
        'actionObjectFeatureDeleteBefore', 'actionObjectCombinationDeleteBefore', 'actionObjectAddAfter',
    ];

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
        foreach (['name', 'reference'] as $field) {
            $text .= ' '.(isset($object->{$field}) ? implode(' ', (array) $object->{$field}) : '');
        }
        $modes = json_decode((string) @file_get_contents('/tmp/kxprobe.json'), true);
        if (strpos($text, 'BOOM') === false || empty($modes[$rule])) {
            return;
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

    public function hookActionProductSave($params)
    {
        $this->fail('add', $params['product'] ?? null);
    }

    public function hookActionProductAdd($params)
    {
        $this->fail('added', $params['product'] ?? null);
    }

    public function hookActionObjectCategoryAddAfter($params)
    {
        $this->fail('add', $params['object']);
    }

    public function hookActionObjectManufacturerAddAfter($params)
    {
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

    /** Nothing: installed before kronixonconnect it stands first here until kronixonconnect moves itself. */
    public function hookActionObjectAddAfter($params)
    {
    }
}
