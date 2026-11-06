<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Support;

use Kronixon\Connect\Shop\ShopInfo;

/** A fixed shop with the members PrestaShopInfo reads from a real one. */
final class FakeShop implements ShopInfo
{
    /** @var bool */
    public $databaseOk = true;

    public function shopId(): int
    {
        return 1;
    }

    public function shopName(): string
    {
        return "Example \x07Shop";
    }

    public function shopUrl(): string
    {
        return 'https://shop.example.com/';
    }

    public function apiUrl(): string
    {
        return 'https://shop.example.com/module/kronixonconnect/api';
    }

    public function timezone(): string
    {
        return 'Europe/Sofia';
    }

    public function multistore(): bool
    {
        return false;
    }

    public function multilang(): bool
    {
        return true;
    }

    public function imageFormats(): array
    {
        return ['jpg', 'webp'];
    }

    public function profile(): array
    {
        return [
            'platform' => 'prestashop',
            'version' => '9.1.5',
            'php' => '8.4.27',
            'mysql' => '10.11.19-MariaDB',
            'shop' => ['id' => 1, 'name' => 'Example Shop', 'url' => 'https://shop.example.com/', 'id_shop_group' => 1, 'multistore' => false,
                'shops' => [['id' => 1, 'name' => 'Example Shop', 'url' => 'https://shop.example.com/']], ],
            'languages' => [
                ['id' => 1, 'iso' => 'bg', 'locale' => 'bg-BG', 'name' => 'Bulgarian', 'default' => true, 'active' => true],
                ['id' => 2, 'iso' => 'en', 'locale' => 'en-US', 'name' => 'English', 'default' => false, 'active' => true],
            ],
            'currencies' => [['id' => 1, 'iso' => 'EUR', 'default' => true, 'rate' => '1.000000', 'active' => true]],
            'tax' => ['enabled' => true, 'prices_stored' => 'excl', 'display' => 'incl', 'tax_rules_groups' => [['id' => 1, 'name' => 'BG Standard (20%)']]],
            'stock' => ['advanced_stock_management' => false, 'locations' => [['key' => 'default', 'name' => 'Shop stock']], 'order_out_of_stock' => true],
            'counts' => ['products' => 500, 'combinations' => 120, 'categories' => 40, 'manufacturers' => 10, 'suppliers' => 3, 'features' => 8, 'feature_values' => 90, 'images' => 1500, 'tags' => 60],
            'modules' => ['ps_facetedsearch' => '3.13.0', 'smartblog' => null],
            'friendly_urls' => true,
            'maintenance_mode' => false,
        ];
    }

    public function databaseOk(): bool
    {
        return $this->databaseOk;
    }
}
