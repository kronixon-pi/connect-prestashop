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

namespace Kronixon\Connect\Catalog;

use Configuration;
use Context;
use Currency;
use DateTimeZone;
use Exception;
use ImageType;
use Kronixon\Connect\Compat\CompatInterface;
use Language;

/**
 * The Scope of the shop in the request's Context (cached PrestaShop lookups, no catalog query).
 */
final class PrestaShopScope
{
    private function __construct()
    {
    }

    public static function of(Context $context, CompatInterface $compat): Scope
    {
        $shop = $context->shop;
        $languages = [];
        foreach (Language::getLanguages(false, (int) $shop->id) as $lang) {
            $languages[(int) $lang['id_lang']] = strtolower((string) $lang['iso_code']);
        }
        $currencies = [];
        foreach (Currency::getCurrencies(false, false) as $currency) {
            $currencies[(int) $currency['id_currency']] = strtoupper((string) $currency['iso_code']);
        }
        $types = [];
        foreach (ImageType::getImagesTypes('products') as $type) {
            $types[] = (string) $type['name'];
        }
        try {
            $zone = new DateTimeZone((string) Configuration::get('PS_TIMEZONE') ?: date_default_timezone_get());
        } catch (Exception $e) {
            $zone = new DateTimeZone(date_default_timezone_get());
        }
        $group = $shop->getGroup();

        return new Scope(
            (int) $shop->id,
            (int) $shop->id_shop_group,
            (bool) $group->share_stock,
            $languages,
            (int) Configuration::get('PS_LANG_DEFAULT'),
            $zone,
            $currencies,
            $types,
            $compat->hasUnitPriceColumn(),
            (int) $shop->id_category,
            $compat->hasFeatureValuePosition()
        );
    }
}
