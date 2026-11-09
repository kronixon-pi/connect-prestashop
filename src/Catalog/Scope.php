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

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * What every reader needs to know about the paired shop: its id and group (stock is the group's when
 * the group shares it), its languages (id_lang → iso), the time zone PrestaShop writes `date_*` in,
 * the currencies (id → ISO 4217), the product image types and the version differences the SQL
 * depends on. Built once per request (PrestaShopScope) and passed to the readers.
 */
final class Scope
{
    /** @var int */
    public $shopId;

    /** @var int */
    public $shopGroupId;

    /** @var bool */
    public $shareStock;

    /** @var array<int, string> id_lang => iso, in id order */
    public $languages;

    /** @var int */
    public $defaultLangId;

    /** @var DateTimeZone */
    public $timezone;

    /** @var array<int, string> id_currency => ISO code */
    public $currencies;

    /** @var list<string> image types used for products */
    public $imageTypes;

    /** @var bool `product_shop.unit_price` exists (PrestaShop 8.0+); before, price / unit_price_ratio */
    public $unitPriceColumn;

    /**
     * @param  array<int, string>  $languages
     * @param  array<int, string>  $currencies
     * @param  list<string>  $imageTypes
     */
    public function __construct(int $shopId, int $shopGroupId, bool $shareStock, array $languages, int $defaultLangId, DateTimeZone $timezone, array $currencies, array $imageTypes, bool $unitPriceColumn)
    {
        ksort($languages);
        $this->shopId = $shopId;
        $this->shopGroupId = $shopGroupId;
        $this->shareStock = $shareStock;
        $this->languages = $languages;
        $this->defaultLangId = $defaultLangId;
        $this->timezone = $timezone;
        $this->currencies = $currencies;
        $this->imageTypes = $imageTypes;
        $this->unitPriceColumn = $unitPriceColumn;
    }

    /** The `stock_available` rows of this shop: its own, or its group's when the group shares stock. */
    public function stockWhere(string $alias): string
    {
        return $this->shareStock
            ? $alias.'.id_shop_group = '.$this->shopGroupId.' AND '.$alias.'.id_shop = 0'
            : $alias.'.id_shop = '.$this->shopId;
    }

    /** A `date_*` of the database as ISO 8601 UTC; null for an empty or zero date. */
    public function utc(?string $local): ?string
    {
        if ($local === null || $local === '' || strncmp($local, '0000-00-00', 10) === 0) {
            return null;
        }
        try {
            $date = new DateTimeImmutable($local, $this->timezone);
        } catch (Exception $e) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /** An ISO 8601 UTC instant as the database writes it (the shop's time zone). */
    public function local(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone($this->timezone)->format('Y-m-d H:i:s');
    }
}
