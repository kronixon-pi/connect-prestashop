<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Support;

use DateTimeZone;
use Kronixon\Connect\Catalog\ImageUrls;
use Kronixon\Connect\Catalog\Scope;
use Kronixon\Connect\Catalog\Sql;
use PDO;

/**
 * The PrestaShop tables the catalog readers read, in SQLite (columns of 1.7.8–9.x the readers use;
 * decimals as TEXT, as MySQL returns them), behind the module's Sql interface. Counts the statements,
 * so a test can prove a page costs a fixed number of queries.
 */
final class CatalogDb implements Sql, ImageUrls
{
    private const TABLES = [
        'product' => 'id_product INTEGER PRIMARY KEY, id_supplier INT DEFAULT 0, id_manufacturer INT DEFAULT 0, id_category_default INT DEFAULT 2,
            id_tax_rules_group INT DEFAULT 1, reference TEXT DEFAULT \'\', supplier_reference TEXT DEFAULT \'\', ean13 TEXT DEFAULT \'\',
            upc TEXT DEFAULT \'\', mpn TEXT DEFAULT \'\', isbn TEXT DEFAULT \'\', price TEXT DEFAULT \'0.000000\', wholesale_price TEXT DEFAULT \'0.000000\',
            weight TEXT DEFAULT \'0.000000\', width TEXT DEFAULT \'0.000000\', height TEXT DEFAULT \'0.000000\', depth TEXT DEFAULT \'0.000000\',
            is_virtual INT DEFAULT 0, cache_is_pack INT DEFAULT 0, product_type TEXT DEFAULT \'standard\', active INT DEFAULT 1',
        'product_shop' => 'id_product INT, id_shop INT, id_category_default INT DEFAULT 2, id_tax_rules_group INT DEFAULT 1, price TEXT DEFAULT \'0.000000\',
            wholesale_price TEXT DEFAULT \'0.000000\', unity TEXT DEFAULT \'\', unit_price_ratio TEXT DEFAULT \'0.000000\', unit_price TEXT DEFAULT \'0.000000\',
            active INT DEFAULT 1, visibility TEXT DEFAULT \'both\', available_for_order INT DEFAULT 1, show_price INT DEFAULT 1, `condition` TEXT DEFAULT \'new\',
            online_only INT DEFAULT 0, redirect_type TEXT DEFAULT \'404\', id_type_redirected INT DEFAULT 0, cache_default_attribute INT DEFAULT 0,
            date_add TEXT DEFAULT \'2026-09-01 10:00:00\', date_upd TEXT DEFAULT \'2026-09-01 10:00:00\', PRIMARY KEY (id_product, id_shop)',
        'product_lang' => 'id_product INT, id_shop INT, id_lang INT, name TEXT DEFAULT \'\', description TEXT DEFAULT \'\', description_short TEXT DEFAULT \'\',
            link_rewrite TEXT DEFAULT \'\', meta_title TEXT DEFAULT \'\', meta_description TEXT DEFAULT \'\', available_now TEXT DEFAULT \'\',
            available_later TEXT DEFAULT \'\', PRIMARY KEY (id_product, id_shop, id_lang)',
        'category_product' => 'id_category INT, id_product INT, position INT DEFAULT 0, PRIMARY KEY (id_category, id_product)',
        'stock_available' => 'id_stock_available INTEGER PRIMARY KEY, id_product INT, id_product_attribute INT, id_shop INT DEFAULT 1, id_shop_group INT DEFAULT 0,
            quantity INT DEFAULT 0, physical_quantity INT DEFAULT 0, reserved_quantity INT DEFAULT 0, depends_on_stock INT DEFAULT 0, out_of_stock INT DEFAULT 2',
        'feature_product' => 'id_feature INT, id_product INT, id_feature_value INT, PRIMARY KEY (id_feature, id_product, id_feature_value)',
        'feature_value' => 'id_feature_value INTEGER PRIMARY KEY, id_feature INT, custom INT DEFAULT 0, position INT DEFAULT 0',
        'feature_value_lang' => 'id_feature_value INT, id_lang INT, value TEXT, PRIMARY KEY (id_feature_value, id_lang)',
        'feature' => 'id_feature INTEGER PRIMARY KEY, position INT DEFAULT 0',
        'feature_lang' => 'id_feature INT, id_lang INT, name TEXT, PRIMARY KEY (id_feature, id_lang)',
        'feature_shop' => 'id_feature INT, id_shop INT, PRIMARY KEY (id_feature, id_shop)',
        'attribute_group' => 'id_attribute_group INTEGER PRIMARY KEY, is_color_group INT DEFAULT 0, group_type TEXT DEFAULT \'select\', position INT DEFAULT 0',
        'attribute_group_lang' => 'id_attribute_group INT, id_lang INT, name TEXT, public_name TEXT, PRIMARY KEY (id_attribute_group, id_lang)',
        'attribute_group_shop' => 'id_attribute_group INT, id_shop INT, PRIMARY KEY (id_attribute_group, id_shop)',
        'attribute_lang' => 'id_attribute INT, id_lang INT, name TEXT, PRIMARY KEY (id_attribute, id_lang)',
        'attribute_shop' => 'id_attribute INT, id_shop INT, PRIMARY KEY (id_attribute, id_shop)',
        'category' => 'id_category INTEGER PRIMARY KEY, id_parent INT, level_depth INT DEFAULT 0, active INT DEFAULT 1, date_upd TEXT DEFAULT \'2026-09-01 10:00:00\'',
        'category_lang' => 'id_category INT, id_shop INT, id_lang INT, name TEXT DEFAULT \'\', link_rewrite TEXT DEFAULT \'\', description TEXT DEFAULT \'\',
            meta_title TEXT DEFAULT \'\', meta_description TEXT DEFAULT \'\', PRIMARY KEY (id_category, id_shop, id_lang)',
        'category_shop' => 'id_category INT, id_shop INT, position INT DEFAULT 0, PRIMARY KEY (id_category, id_shop)',
        'manufacturer' => 'id_manufacturer INTEGER PRIMARY KEY, name TEXT, active INT DEFAULT 1, date_upd TEXT DEFAULT \'2026-09-01 10:00:00\'',
        'manufacturer_lang' => 'id_manufacturer INT, id_lang INT, description TEXT DEFAULT \'\', short_description TEXT DEFAULT \'\', meta_title TEXT DEFAULT \'\',
            meta_description TEXT DEFAULT \'\', PRIMARY KEY (id_manufacturer, id_lang)',
        'manufacturer_shop' => 'id_manufacturer INT, id_shop INT, PRIMARY KEY (id_manufacturer, id_shop)',
        'supplier' => 'id_supplier INTEGER PRIMARY KEY, name TEXT, active INT DEFAULT 1, date_upd TEXT DEFAULT \'2026-09-01 10:00:00\'',
        'supplier_lang' => 'id_supplier INT, id_lang INT, description TEXT DEFAULT \'\', meta_title TEXT DEFAULT \'\', meta_description TEXT DEFAULT \'\',
            PRIMARY KEY (id_supplier, id_lang)',
        'supplier_shop' => 'id_supplier INT, id_shop INT, PRIMARY KEY (id_supplier, id_shop)',
        'tag' => 'id_tag INTEGER PRIMARY KEY, id_lang INT, name TEXT',
        'product_tag' => 'id_product INT, id_tag INT, id_lang INT, PRIMARY KEY (id_product, id_tag)',
        'product_supplier' => 'id_product_supplier INTEGER PRIMARY KEY, id_product INT, id_product_attribute INT DEFAULT 0, id_supplier INT,
            product_supplier_reference TEXT DEFAULT \'\', product_supplier_price_te TEXT DEFAULT \'0.000000\', id_currency INT DEFAULT 0',
        'image' => 'id_image INTEGER PRIMARY KEY, id_product INT, position INT DEFAULT 0, cover INT NULL',
        'image_shop' => 'id_product INT, id_image INT, id_shop INT, cover INT NULL, PRIMARY KEY (id_image, id_shop)',
        'image_lang' => 'id_image INT, id_lang INT, legend TEXT DEFAULT \'\', PRIMARY KEY (id_image, id_lang)',
        'product_attribute' => 'id_product_attribute INTEGER PRIMARY KEY, id_product INT, reference TEXT DEFAULT \'\', ean13 TEXT DEFAULT \'\', mpn TEXT DEFAULT \'\'',
        'product_attribute_shop' => 'id_product INT, id_product_attribute INT, id_shop INT, price TEXT DEFAULT \'0.000000\', wholesale_price TEXT DEFAULT \'0.000000\',
            weight TEXT DEFAULT \'0.000000\', default_on INT NULL, minimal_quantity INT DEFAULT 1, available_date TEXT NULL, PRIMARY KEY (id_product_attribute, id_shop)',
        'product_attribute_combination' => 'id_attribute INT, id_product_attribute INT, PRIMARY KEY (id_attribute, id_product_attribute)',
        'product_attribute_image' => 'id_product_attribute INT, id_image INT, PRIMARY KEY (id_product_attribute, id_image)',
        'attribute' => 'id_attribute INTEGER PRIMARY KEY, id_attribute_group INT, color TEXT DEFAULT \'\', position INT DEFAULT 0',
        'specific_price' => 'id_specific_price INTEGER PRIMARY KEY, id_specific_price_rule INT DEFAULT 0, id_cart INT DEFAULT 0, id_product INT,
            id_shop INT DEFAULT 0, id_shop_group INT DEFAULT 0, id_currency INT DEFAULT 0, id_country INT DEFAULT 0, id_group INT DEFAULT 0,
            id_customer INT DEFAULT 0, id_product_attribute INT DEFAULT 0, price TEXT DEFAULT \'-1.000000\', from_quantity INT DEFAULT 1,
            reduction TEXT DEFAULT \'0.000000\', reduction_tax INT DEFAULT 1, reduction_type TEXT DEFAULT \'amount\',
            `from` TEXT DEFAULT \'0000-00-00 00:00:00\', `to` TEXT DEFAULT \'0000-00-00 00:00:00\'',
        'kx_entity_map' => 'id_kx_entity_map INTEGER PRIMARY KEY, entity_type TEXT, external_id TEXT, kx_id TEXT',
        'kx_image_map' => 'id_image INTEGER PRIMARY KEY, id_product INT, kx_key TEXT, sha256 TEXT',
    ];

    /** @var PDO */
    public $pdo;

    /** @var int statements run through rows() */
    public $queries = 0;

    /** @var bool 1.7.8: no product_shop.unit_price */
    public $legacy = false;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (self::TABLES as $name => $columns) {
            $this->pdo->exec('CREATE TABLE ps_'.$name.' ('.$columns.')');
        }
        $this->pdo->exec('CREATE INDEX ix_pa_product ON ps_product_attribute (id_product, id_product_attribute)');
        $this->pdo->exec('CREATE UNIQUE INDEX ix_stock ON ps_stock_available (id_product, id_product_attribute, id_shop, id_shop_group)');
    }

    public function rows(string $sql): array
    {
        $this->queries++;
        $rows = [];
        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            return [];
        }
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // mysqli returns text; PDO SQLite returns integers on PHP 8.1+.
            $rows[] = array_map(static function ($value): ?string {
                return $value === null ? null : (string) $value;
            }, $row);
        }

        return $rows;
    }

    public function table(string $name): string
    {
        return '`ps_'.$name.'`';
    }

    public function url(int $imageId, string $slug, ?string $type): string
    {
        return 'https://shop.example.com/'.$imageId.($type === null ? '' : '-'.$type).'/'.($slug !== '' ? $slug : 'image').'.jpg';
    }

    public function brandLogo(int $brandId): ?string
    {
        return $brandId === 12 ? 'https://shop.example.com/img/m/12.jpg' : null;
    }

    /** The scope of shop 1: en (1) and bg (2), EUR, Europe/Sofia, Home = category 2. */
    public function scope(): Scope
    {
        return new Scope(1, 1, false, [1 => 'en', 2 => 'bg'], 1, new DateTimeZone('Europe/Sofia'), [1 => 'EUR', 2 => 'BGN'], ['small_default', 'large_default'], ! $this->legacy, 2, ! $this->legacy);
    }

    /**
     * @param  array<string, int|string|null>  $row
     */
    public function insert(string $table, array $row): void
    {
        $columns = array_map(static function (string $c): string {
            return '`'.$c.'`';
        }, array_keys($row));
        $statement = $this->pdo->prepare('INSERT INTO ps_'.$table.' ('.implode(', ', $columns).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')');
        $statement->execute(array_values($row));
    }

    public function delete(string $table, string $where): void
    {
        $this->pdo->exec('DELETE FROM ps_'.$table.' WHERE '.$where);
    }

    /**
     * A plain product in shop 1 with names in both languages and a stock row.
     *
     * @param  array<string, int|string|null>  $product  columns of `product`
     * @param  array<string, int|string|null>  $shop  columns of `product_shop`
     */
    public function product(int $id, array $product = [], array $shop = [], int $quantity = 5): void
    {
        $this->insert('product', $product + ['id_product' => $id]);
        $this->insert('product_shop', $shop + ['id_product' => $id, 'id_shop' => 1, 'price' => '10.000000']);
        foreach ([1 => 'Product', 2 => 'Продукт'] as $lang => $name) {
            $this->insert('product_lang', ['id_product' => $id, 'id_shop' => 1, 'id_lang' => $lang, 'name' => $name.' '.$id, 'link_rewrite' => 'product-'.$id]);
        }
        $this->insert('category_product', ['id_category' => 2, 'id_product' => $id]);
        $this->insert('stock_available', ['id_product' => $id, 'id_product_attribute' => 0, 'quantity' => $quantity]);
    }

    /** Products $from … $from + $count − 1 in one transaction. */
    public function products(int $from, int $count): void
    {
        $this->pdo->beginTransaction();
        for ($id = $from; $id < $from + $count; $id++) {
            $this->product($id);
        }
        $this->pdo->commit();
    }

    /**
     * A small catalog with every kind of product: 1 simple with everything, 2 variable (combinations
     * 10 and 11, images 900–902), 3 a pack, 4 virtual from 1.7.8's flags (no product_type), 5 inactive.
     */
    public function demo(): void
    {
        $this->product(1, ['reference' => 'ABC-1', 'ean13' => '3800123456789', 'mpn' => 'X1', 'supplier_reference' => 'S-1', 'id_manufacturer' => 12,
            'id_supplier' => 3, 'weight' => '1.200000'], ['price' => '99.990000', 'wholesale_price' => '80.000000', 'id_category_default' => 45,
            'unit_price_ratio' => '2.000000', 'unit_price' => '49.995000', 'unity' => 'kg', 'date_add' => '2026-09-01 11:00:00', 'date_upd' => '2026-09-30 15:40:00'], 7);
        $this->insert('category_product', ['id_category' => 45, 'id_product' => 1]);
        $this->pdo->exec("UPDATE ps_product_lang SET description_short = '<p>NVMe</p>', link_rewrite = 'ssd-512-gb', meta_title = 'SSD' WHERE id_product = 1");
        $this->insert('feature_value', ['id_feature_value' => 310, 'id_feature' => 7, 'custom' => 0]);
        $this->insert('feature_value', ['id_feature_value' => 311, 'id_feature' => 8, 'custom' => 1]);
        $this->insert('feature_product', ['id_feature' => 8, 'id_product' => 1, 'id_feature_value' => 311]);
        $this->insert('feature_product', ['id_feature' => 7, 'id_product' => 1, 'id_feature_value' => 310]);
        foreach ([[40, 1, 'nvme'], [41, 1, 'SSD'], [42, 2, 'ссд']] as [$tag, $lang, $name]) {
            $this->insert('tag', ['id_tag' => $tag, 'id_lang' => $lang, 'name' => $name]);
            $this->insert('product_tag', ['id_product' => 1, 'id_tag' => $tag, 'id_lang' => $lang]);
        }
        $this->insert('product_supplier', ['id_product' => 1, 'id_supplier' => 3, 'product_supplier_reference' => 'S-1', 'product_supplier_price_te' => '80.000000', 'id_currency' => 1]);
        $this->insert('product_supplier', ['id_product' => 1, 'id_supplier' => 2, 'product_supplier_price_te' => '81.000000', 'id_currency' => 0]);
        $this->insert('kx_entity_map', ['entity_type' => 'product', 'external_id' => '1', 'kx_id' => '0192b2e4-2f7a-7c5b-8a1d-3e4f5a6b7c8d']);

        $this->product(2, ['reference' => 'TS'], ['price' => '20.000000', 'cache_default_attribute' => 10], 0);
        $this->pdo->exec("UPDATE ps_product SET product_type = 'combinations' WHERE id_product = 2");
        foreach ([[900, 2, 1], [901, 1, null], [902, 3, null]] as [$image, $position, $cover]) {
            $this->insert('image', ['id_image' => $image, 'id_product' => 2, 'position' => $position, 'cover' => $cover]);
            $this->insert('image_shop', ['id_image' => $image, 'id_product' => 2, 'id_shop' => 1, 'cover' => $cover]);
            $this->insert('image_lang', ['id_image' => $image, 'id_lang' => 1, 'legend' => 'T-shirt '.$position]);
        }
        $this->insert('kx_image_map', ['id_image' => 900, 'id_product' => 2, 'kx_key' => 'f-1', 'sha256' => str_repeat('ab', 32)]);
        foreach ([[1, 2, '#ff0000'], [2, 2, '#0000ff'], [3, 1, '']] as [$attribute, $group, $color]) {
            $this->insert('attribute', ['id_attribute' => $attribute, 'id_attribute_group' => $group, 'color' => $color]);
        }
        foreach ([[10, '5.000000', 1, [1, 3], [901]], [11, '-2.500000', null, [2, 3], []]] as [$variant, $impact, $default, $attributes, $images]) {
            $this->insert('product_attribute', ['id_product_attribute' => $variant, 'id_product' => 2, 'reference' => 'TS-'.$variant]);
            $this->insert('product_attribute_shop', ['id_product_attribute' => $variant, 'id_product' => 2, 'id_shop' => 1, 'price' => $impact, 'default_on' => $default]);
            foreach ($attributes as $attribute) {
                $this->insert('product_attribute_combination', ['id_attribute' => $attribute, 'id_product_attribute' => $variant]);
            }
            foreach ($images as $image) {
                $this->insert('product_attribute_image', ['id_product_attribute' => $variant, 'id_image' => $image]);
            }
            $this->insert('stock_available', ['id_product' => 2, 'id_product_attribute' => $variant, 'quantity' => $variant, 'physical_quantity' => $variant + 1, 'reserved_quantity' => 1]);
        }
        $this->insert('specific_price', ['id_specific_price' => 5001, 'id_product' => 2, 'reduction' => '0.100000', 'reduction_type' => 'percentage']);
        $this->insert('specific_price', ['id_specific_price' => 5002, 'id_product' => 2, 'id_product_attribute' => 11, 'price' => '15.000000', 'from_quantity' => 3, 'from' => '2026-10-01 00:00:00']);
        $this->insert('specific_price', ['id_specific_price' => 5003, 'id_product' => 2, 'id_cart' => 77, 'reduction' => '1.000000']);

        $this->product(3, [], [], 1);
        $this->pdo->exec("UPDATE ps_product SET product_type = 'pack', cache_is_pack = 1 WHERE id_product = 3");
        $this->product(4, ['is_virtual' => 1, 'product_type' => ''], [], 0);
        $this->product(5, [], ['active' => 0, 'date_upd' => '2026-08-01 10:00:00'], 0);
    }

    /**
     * The taxonomy around demo(): categories Root 1, Home 2, 45, 46 (inactive); brands 12, 13 (no name),
     * 14 (not in the shop); suppliers 2, 3; features 2, 7, 8 with values 310–312 (311 custom); attribute
     * groups 1 (select) and 2 (color) with values 1–3; tags 43 (a language the shop lacks) and 44 (empty).
     */
    public function taxonomy(): void
    {
        foreach ([[1, 0, 0, 'Root', 0], [2, 1, 1, 'Home', 0], [45, 2, 2, 'SSD', 3], [46, 45, 3, 'NVMe', 0]] as [$id, $parent, $level, $name, $position]) {
            $this->insert('category', ['id_category' => $id, 'id_parent' => $parent, 'level_depth' => $level, 'active' => $id === 46 ? 0 : 1]);
            $this->insert('category_shop', ['id_category' => $id, 'id_shop' => 1, 'position' => $position]);
            foreach ([1 => $name, 2 => $name.' bg'] as $lang => $text) {
                $this->insert('category_lang', ['id_category' => $id, 'id_shop' => 1, 'id_lang' => $lang, 'name' => $text, 'link_rewrite' => strtolower($name)]);
            }
        }
        foreach ([[12, 'Samsung', true], [13, '', true], [14, 'Elsewhere', false]] as [$id, $name, $inShop]) {
            $this->insert('manufacturer', ['id_manufacturer' => $id, 'name' => $name]);
            $this->insert('manufacturer_lang', ['id_manufacturer' => $id, 'id_lang' => 1, 'short_description' => 'About '.$id]);
            if ($inShop) {
                $this->insert('manufacturer_shop', ['id_manufacturer' => $id, 'id_shop' => 1]);
            }
        }
        foreach ([2 => 'Distributor B', 3 => 'Distributor A'] as $id => $name) {
            $this->insert('supplier', ['id_supplier' => $id, 'name' => $name, 'active' => $id === 3 ? 1 : 0]);
            $this->insert('supplier_shop', ['id_supplier' => $id, 'id_shop' => 1]);
            $this->insert('supplier_lang', ['id_supplier' => $id, 'id_lang' => 2, 'description' => 'Доставчик']);
        }
        foreach ([2 => 'Material', 7 => 'Capacity', 8 => 'Notes'] as $id => $name) {
            $this->insert('feature', ['id_feature' => $id, 'position' => $id]);
            $this->insert('feature_shop', ['id_feature' => $id, 'id_shop' => 1]);
            $this->insert('feature_lang', ['id_feature' => $id, 'id_lang' => 1, 'name' => $name]);
        }
        $this->insert('feature_value', ['id_feature_value' => 312, 'id_feature' => 7, 'position' => 1]);
        foreach ([310 => '512 GB', 311 => 'Quiet', 312 => '1 TB'] as $id => $value) {
            $this->insert('feature_value_lang', ['id_feature_value' => $id, 'id_lang' => 1, 'value' => $value]);
        }
        foreach ([1 => ['Size', 'select', 0], 2 => ['Color', 'color', 1]] as $id => [$name, $type, $color]) {
            $this->insert('attribute_group', ['id_attribute_group' => $id, 'group_type' => $type, 'is_color_group' => $color, 'position' => $id - 1]);
            $this->insert('attribute_group_shop', ['id_attribute_group' => $id, 'id_shop' => 1]);
            $this->insert('attribute_group_lang', ['id_attribute_group' => $id, 'id_lang' => 1, 'name' => $name, 'public_name' => $name.'!']);
        }
        foreach ([1 => 'Red', 2 => 'Blue', 3 => 'M'] as $id => $name) {
            $this->insert('attribute_shop', ['id_attribute' => $id, 'id_shop' => 1]);
            $this->insert('attribute_lang', ['id_attribute' => $id, 'id_lang' => 1, 'name' => $name]);
        }
        $this->insert('tag', ['id_tag' => 43, 'id_lang' => 3, 'name' => 'nicht']);
        $this->insert('tag', ['id_tag' => 44, 'id_lang' => 1, 'name' => '']);
    }

    /** Combination $id of product $productId in shop 1, with one option value and a stock row. */
    public function variant(int $id, int $productId = 1): void
    {
        $this->insert('product_attribute', ['id_product_attribute' => $id, 'id_product' => $productId]);
        $this->insert('product_attribute_shop', ['id_product_attribute' => $id, 'id_product' => $productId, 'id_shop' => 1, 'price' => '1.000000']);
        $this->insert('product_attribute_combination', ['id_attribute' => 1, 'id_product_attribute' => $id]);
        $this->insert('stock_available', ['id_product' => $productId, 'id_product_attribute' => $id, 'quantity' => 1]);
    }

    public function deleteVariant(int $id): void
    {
        foreach (['product_attribute', 'product_attribute_shop', 'product_attribute_combination', 'stock_available'] as $table) {
            $this->delete($table, 'id_product_attribute = '.$id);
        }
    }

    /** Image $id of product $productId in shop 1. */
    public function image(int $id, int $productId = 1): void
    {
        $this->insert('image', ['id_image' => $id, 'id_product' => $productId, 'position' => 1]);
        $this->insert('image_shop', ['id_image' => $id, 'id_product' => $productId, 'id_shop' => 1]);
    }

    public function deleteImage(int $id): void
    {
        $this->delete('image', 'id_image = '.$id);
        $this->delete('image_shop', 'id_image = '.$id);
    }

    public function deleteProduct(int $id): void
    {
        foreach (['product', 'product_shop', 'product_lang', 'category_product', 'stock_available', 'product_attribute', 'product_attribute_shop'] as $table) {
            $this->delete($table, 'id_product = '.$id);
        }
    }
}
