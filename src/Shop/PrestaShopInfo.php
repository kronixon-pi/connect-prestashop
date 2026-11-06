<?php

/**
 * Kronixon Connect for PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.
 * It is also available at https://opensource.org/licenses/AFL-3.0
 *
 * @author    Kronixon
 * @copyright 2026 Kronixon
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

declare(strict_types=1);

namespace Kronixon\Connect\Shop;

use Configuration;
use Context;
use Currency;
use Db;
use Group;
use Kronixon\Connect\Compat\CompatInterface;
use Kronixon\Connect\Protocol;
use Language;
use Shop;
use TaxRulesGroup;
use Throwable;

/**
 * ShopInfo read from PrestaShop core classes whose API is the same on 1.7.8–9.x. The counts are
 * plain COUNT(*) on primary keys (GET profile only; GET health never counts).
 */
final class PrestaShopInfo implements ShopInfo
{
    private const COUNTS = [
        'products' => 'product',
        'combinations' => 'product_attribute',
        'categories' => 'category',
        'manufacturers' => 'manufacturer',
        'suppliers' => 'supplier',
        'features' => 'feature',
        'feature_values' => 'feature_value',
        'images' => 'image',
        'tags' => 'tag',
    ];

    /** @var Context */
    private $context;

    /** @var CompatInterface */
    private $compat;

    public function __construct(Context $context, CompatInterface $compat)
    {
        $this->context = $context;
        $this->compat = $compat;
    }

    public function shopId(): int
    {
        return (int) $this->context->shop->id;
    }

    public function shopName(): string
    {
        $name = (string) Configuration::get('PS_SHOP_NAME');

        return $name !== '' ? $name : (string) $this->context->shop->name;
    }

    public function shopUrl(): string
    {
        return rtrim((string) $this->context->shop->getBaseURL(true), '/').'/';
    }

    public function apiUrl(): string
    {
        return (string) $this->context->link->getModuleLink(Protocol::MODULE_NAME, 'api', [], true);
    }

    public function timezone(): string
    {
        $zone = (string) Configuration::get('PS_TIMEZONE');

        return $zone !== '' ? $zone : date_default_timezone_get();
    }

    public function multistore(): bool
    {
        return (bool) Shop::isFeatureActive();
    }

    public function multilang(): bool
    {
        return count(Language::getLanguages(true)) > 1;
    }

    public function imageFormats(): array
    {
        $formats = ['jpg'];
        if ($this->compat->hasImageFormatSetting()) {
            foreach (preg_split('/[\s,]+/', strtolower((string) Configuration::get('PS_IMAGE_FORMAT'))) ?: [] as $format) {
                if (in_array($format, ['png', 'webp', 'avif'], true) && ! in_array($format, $formats, true)) {
                    $formats[] = $format;
                }
            }
        }

        return $formats;
    }

    public function profile(): array
    {
        $db = Db::getInstance();
        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $languages = [];
        foreach (Language::getLanguages(false) as $lang) {
            $languages[] = [
                'id' => (int) $lang['id_lang'],
                'iso' => strtolower((string) $lang['iso_code']),
                'locale' => (string) ($lang['locale'] ?? $lang['language_code'] ?? $lang['iso_code']),
                'name' => (string) $lang['name'],
                'default' => (int) $lang['id_lang'] === $defaultLang,
                'active' => (bool) $lang['active'],
            ];
        }
        $defaultCurrency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        $currencies = [];
        foreach (Currency::getCurrencies(false, false) as $currency) {
            $currencies[] = [
                'id' => (int) $currency['id_currency'],
                'iso' => strtoupper((string) $currency['iso_code']),
                'default' => (int) $currency['id_currency'] === $defaultCurrency,
                'rate' => sprintf('%.6f', (float) $currency['conversion_rate']),
                'active' => (bool) $currency['active'],
            ];
        }
        $taxGroups = [];
        foreach (TaxRulesGroup::getTaxRulesGroups(true) as $group) {
            $taxGroups[] = ['id' => (int) $group['id_tax_rules_group'], 'name' => (string) $group['name']];
        }
        $counts = [];
        foreach (self::COUNTS as $name => $table) {
            $counts[$name] = (int) $db->getValue('SELECT COUNT(*) FROM `'._DB_PREFIX_.$table.'`', false);
        }
        $modules = ['ps_facetedsearch' => null, 'smartblog' => null];
        foreach ($db->executeS('SELECT `name`, `version` FROM `'._DB_PREFIX_.'module` WHERE `name` IN (\'ps_facetedsearch\', \'smartblog\')') ?: [] as $row) {
            $modules[(string) $row['name']] = (string) $row['version'];
        }
        $shops = [];
        foreach (Shop::getShops(true) as $shop) {
            $shops[] = ['id' => (int) $shop['id_shop'], 'name' => (string) $shop['name'], 'url' => $this->shopBase($shop)];
        }

        return [
            'platform' => 'prestashop',
            'version' => _PS_VERSION_,
            'php' => PHP_VERSION,
            'mysql' => (string) $db->getVersion(),
            'shop' => [
                'id' => $this->shopId(),
                'name' => $this->shopName(),
                'url' => $this->shopUrl(),
                'id_shop_group' => (int) $this->context->shop->id_shop_group,
                'multistore' => $this->multistore(),
                'shops' => $shops,
            ],
            'languages' => $languages,
            'currencies' => $currencies,
            'tax' => [
                'enabled' => (bool) Configuration::get('PS_TAX'),
                'prices_stored' => 'excl',
                'display' => (int) Group::getDefaultPriceDisplayMethod() === 1 ? 'excl' : 'incl',
                'tax_rules_groups' => $taxGroups,
            ],
            'stock' => [
                'advanced_stock_management' => (bool) Configuration::get('PS_ADVANCED_STOCK_MANAGEMENT'),
                'locations' => [['key' => 'default', 'name' => 'Shop stock']],
                'order_out_of_stock' => (bool) Configuration::get('PS_ORDER_OUT_OF_STOCK'),
            ],
            'counts' => $counts,
            'modules' => $modules,
            'friendly_urls' => (bool) Configuration::get('PS_REWRITING_SETTINGS'),
            'maintenance_mode' => ! (bool) Configuration::get('PS_SHOP_ENABLE'),
        ];
    }

    public function databaseOk(): bool
    {
        try {
            return (string) Db::getInstance()->getValue('SELECT 1', false) === '1';
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $shop  a row of Shop::getShops()
     */
    private function shopBase(array $shop): string
    {
        $ssl = (bool) Configuration::get('PS_SSL_ENABLED');
        $domain = (string) ($ssl ? ($shop['domain_ssl'] ?? $shop['domain']) : $shop['domain']);

        return ($ssl ? 'https://' : 'http://').$domain.'/'.ltrim((string) ($shop['uri'] ?? '/'), '/');
    }
}
