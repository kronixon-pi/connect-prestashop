<?php

declare(strict_types=1);
/**
 * Kronixon Connect for PrestaShop — test tooling, not part of the module zip.
 *
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *
 * Deterministic store fixture of Spec §12 for a fresh shop: 2 languages (en + bg), 30 categories,
 * 20 brands, 3 suppliers, 15 features × 200 values, 2 attribute groups, 500 products (40 with
 * combinations), 800 small images, tags, supplier references and stock. The same --seed gives the
 * same catalog on every PrestaShop version; the summary's digest proves it. SmartBlog posts of the
 * Spec fixture wait for the blog (Phase 2).
 *
 * Run inside the shop container as www-data (≈ 2–4 min):
 *   php modules/kronixonconnect/tests/fixtures/generate.php [--seed=20261105]
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$options = getopt('', ['seed::']);
$seed = isset($options['seed']) ? (int) $options['seed'] : 20261105;
$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = getenv('PS_DOMAIN') ?: 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
define('_PS_ADMIN_DIR_', $root.'/admin-dev');

require $root.'/config/config.inc.php';

/**
 * xorshift32: the same sequence on PHP 7.4–8.5 (mt_rand's seeding is not part of the contract).
 */
final class KxFixtureRandom
{
    /** @var int */
    private $state;

    public function __construct(int $seed)
    {
        $this->state = ($seed & 0xFFFFFFFF) ?: 0x9E3779B9;
    }

    public function next(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->state = $x & 0xFFFFFFFF;

        return $this->state;
    }

    public function int(int $min, int $max): int
    {
        return $min + ($this->next() % ($max - $min + 1));
    }

    /**
     * @param  list<mixed>  $items
     * @return list<mixed>
     */
    public function pickDistinct(array $items, int $count): array
    {
        $picked = [];
        $pool = $items;
        for ($i = 0; $i < $count && $pool !== []; $i++) {
            $index = $this->next() % count($pool);
            $picked[] = $pool[$index];
            array_splice($pool, $index, 1);
        }

        return $picked;
    }
}

final class KxFixture
{
    /** @var KxFixtureRandom */
    private $random;

    /** @var int */
    private $en;

    /** @var int */
    private $bg;

    /** @var array<string, int> */
    private $counts = [];

    /** @var float */
    private $started;

    public function __construct(int $seed)
    {
        $this->random = new KxFixtureRandom($seed);
        $this->started = microtime(true);
    }

    /**
     * @return array<string, mixed>
     */
    public function run(int $seed): array
    {
        if (Configuration::getGlobalValue('KX_FIXTURE_SEED') !== false) {
            throw new RuntimeException('This shop already holds a fixture (KX_FIXTURE_SEED is set).');
        }
        Context::getContext()->employee = new Employee(1);
        $this->en = (int) Configuration::get('PS_LANG_DEFAULT');
        $this->bg = $this->bulgarian();

        $categories = $this->categories();
        $brands = $this->brands();
        $suppliers = $this->suppliers();
        $features = $this->features();
        $options = $this->attributeGroups();
        $this->products($categories, $brands, $suppliers, $features, $options);
        Category::regenerateEntireNtree();

        Configuration::updateGlobalValue('KX_FIXTURE_SEED', (string) $seed);

        return [
            'seed' => $seed,
            'prestashop' => _PS_VERSION_,
            'php' => PHP_VERSION,
            'counts' => $this->counts,
            'digest' => hash('sha256', implode("\n", $this->storedCatalog())),
            'ms' => (int) round((microtime(true) - $this->started) * 1000),
        ];
    }

    private function bulgarian(): int
    {
        $id = (int) Language::getIdByIso('bg');
        if ($id > 0) {
            return $id;
        }
        $language = new Language;
        $language->name = 'Bulgarian (Български)';
        $language->iso_code = 'bg';
        $language->locale = 'bg-BG';
        $language->language_code = 'bg-bg';
        $language->date_format_lite = 'd.m.Y';
        $language->date_format_full = 'd.m.Y H:i:s';
        $language->is_rtl = false;
        $language->active = true;
        // only_add: the full add() rebuilds translations through the Symfony container, which a CLI
        // script does not boot on PrestaShop 9; the _lang rows are copied from English instead.
        if (! $language->add(true, false, true)) {
            throw new RuntimeException('Could not add the bg language.');
        }
        Language::copyLanguageData($this->en, (int) $language->id);
        Language::resetCache();

        return (int) $language->id;
    }

    /**
     * @return list<int> the 25 leaf categories
     */
    private function categories(): array
    {
        $home = (int) Configuration::get('PS_HOME_CATEGORY');
        $leaves = [];
        for ($t = 1; $t <= 5; $t++) {
            $top = $this->category($home, sprintf('Department %d', $t), sprintf('Отдел %d', $t));
            for ($c = 1; $c <= 5; $c++) {
                $leaves[] = $this->category($top, sprintf('Category %d.%d', $t, $c), sprintf('Категория %d.%d', $t, $c));
            }
        }
        $this->counts['categories'] = 30;

        return $leaves;
    }

    private function category(int $parent, string $en, string $bg): int
    {
        $category = new Category;
        $category->id_parent = $parent;
        $category->active = true;
        $category->name = $this->lang($en, $bg);
        $category->link_rewrite = $this->lang(Tools::str2url($en), Tools::str2url($en));
        $category->description = $this->lang('<p>'.$en.'</p>', '<p>'.$bg.'</p>');
        $category->doNotRegenerateNTree = true;
        $this->save($category, 'category '.$en);

        return (int) $category->id;
    }

    /**
     * @return list<int>
     */
    private function brands(): array
    {
        $ids = [];
        for ($i = 1; $i <= 20; $i++) {
            $brand = new Manufacturer;
            $brand->name = sprintf('Brand %02d', $i);
            $brand->active = true;
            $brand->description = $this->lang('Brand description', 'Описание на марката');
            $this->save($brand, 'brand');
            $ids[] = (int) $brand->id;
        }
        $this->counts['brands'] = count($ids);

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function suppliers(): array
    {
        $ids = [];
        for ($i = 1; $i <= 3; $i++) {
            $supplier = new Supplier;
            $supplier->name = sprintf('Supplier %d', $i);
            $supplier->active = true;
            $this->save($supplier, 'supplier');
            $ids[] = (int) $supplier->id;
        }
        $this->counts['suppliers'] = count($ids);

        return $ids;
    }

    /**
     * @return array<int, list<int>> feature id => value ids
     */
    private function features(): array
    {
        $features = [];
        $values = 0;
        for ($f = 1; $f <= 15; $f++) {
            $feature = new Feature;
            $feature->name = $this->lang(sprintf('Feature %02d', $f), sprintf('Характеристика %02d', $f));
            $this->save($feature, 'feature');
            $features[(int) $feature->id] = [];
            for ($v = 1; $v <= 200; $v++) {
                $value = new FeatureValue;
                $value->id_feature = (int) $feature->id;
                $value->custom = false;
                $value->value = $this->lang(sprintf('Value %02d-%03d', $f, $v), sprintf('Стойност %02d-%03d', $f, $v));
                $this->save($value, 'feature value');
                $features[(int) $feature->id][] = (int) $value->id;
                $values++;
            }
        }
        $this->counts['features'] = count($features);
        $this->counts['feature_values'] = $values;

        return $features;
    }

    /**
     * @return array{0: list<int>, 1: list<int>} colour value ids, size value ids
     */
    private function attributeGroups(): array
    {
        $class = class_exists('ProductAttribute') ? 'ProductAttribute' : 'Attribute';
        $groups = [];
        foreach ([['Colour', 'Цвят', true, ['Red' => '#E74C3C', 'Green' => '#27AE60', 'Blue' => '#2980B9', 'Black' => '#000000', 'White' => '#FFFFFF']], ['Size', 'Размер', false, ['S' => '', 'M' => '', 'L' => '', 'XL' => '']]] as [$en, $bg, $isColor, $values]) {
            $group = new AttributeGroup;
            $group->name = $this->lang($en, $bg);
            $group->public_name = $this->lang($en, $bg);
            $group->group_type = $isColor ? 'color' : 'select';
            $group->is_color_group = $isColor;
            $this->save($group, 'attribute group');
            $ids = [];
            foreach ($values as $name => $color) {
                $value = new $class;
                $value->id_attribute_group = (int) $group->id;
                $value->name = $this->lang($name, $name);
                $value->color = $color;
                $this->save($value, 'attribute value');
                $ids[] = (int) $value->id;
            }
            $groups[] = $ids;
        }
        $this->counts['attribute_groups'] = 2;
        $this->counts['attribute_values'] = count($groups[0]) + count($groups[1]);

        return [$groups[0], $groups[1]];
    }

    /**
     * @param  list<int>  $categories
     * @param  list<int>  $brands
     * @param  list<int>  $suppliers
     * @param  array<int, list<int>>  $features
     * @param  array{0: list<int>, 1: list<int>}  $options
     */
    private function products(array $categories, array $brands, array $suppliers, array $features, array $options): void
    {
        $currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        $imageTypes = ImageType::getImagesTypes('products');
        $images = 0;
        $combinations = 0;
        $withCombinations = 0;
        for ($i = 1; $i <= 500; $i++) {
            $reference = sprintf('KX-%04d', $i);
            $price = sprintf('%d.%02d', $this->random->int(5, 1999), $this->random->int(0, 99));
            $quantity = $this->random->int(0, 250);
            $productCategories = $this->random->pickDistinct($categories, $this->random->int(1, 2));

            $product = new Product;
            $product->name = $this->lang(sprintf('Fixture product %03d', $i), sprintf('Тестов продукт %03d', $i));
            $product->link_rewrite = $this->lang(sprintf('fixture-product-%03d', $i), sprintf('testov-produkt-%03d', $i));
            $product->description_short = $this->lang('<p>Short description '.$i.'</p>', '<p>Кратко описание '.$i.'</p>');
            $product->description = $this->lang('<p>Description of fixture product '.$i.'.</p>', '<p>Описание на тестов продукт '.$i.'.</p>');
            $product->reference = $reference;
            $product->ean13 = $this->ean13($i);
            $product->mpn = sprintf('MPN-%05d', $i * 7);
            $product->price = $price;
            $product->wholesale_price = sprintf('%.2f', (float) $price * 0.6);
            $product->id_tax_rules_group = 0;
            $product->id_manufacturer = $brands[$this->random->int(0, count($brands) - 1)];
            $product->id_supplier = $suppliers[$this->random->int(0, count($suppliers) - 1)];
            $product->id_category_default = (int) $productCategories[0];
            $product->weight = sprintf('%.3f', $this->random->int(50, 20000) / 1000);
            $product->active = true;
            $product->visibility = 'both';
            $product->indexed = 0;
            $this->save($product, 'product '.$reference);
            $id = (int) $product->id;

            $product->addToCategories($productCategories);
            foreach ($this->random->pickDistinct(array_keys($features), $this->random->int(3, 6)) as $featureId) {
                $valueIds = $features[$featureId];
                Product::addFeatureProductImport($id, (int) $featureId, $valueIds[$this->random->int(0, count($valueIds) - 1)]);
            }
            Tag::addTags($this->en, $id, ['fixture', 'group-'.($i % 10)]);
            Tag::addTags($this->bg, $id, ['тест', 'група-'.($i % 10)]);

            $productSupplier = new ProductSupplier;
            $productSupplier->id_product = $id;
            $productSupplier->id_product_attribute = 0;
            $productSupplier->id_supplier = (int) $product->id_supplier;
            $productSupplier->product_supplier_reference = 'S-'.$reference;
            $productSupplier->product_supplier_price_te = $product->wholesale_price;
            $productSupplier->id_currency = $currency;
            $this->save($productSupplier, 'product supplier');
            StockAvailable::setQuantity($id, 0, $quantity);

            if ($i % 12 === 0 && $withCombinations < 40) {
                $combinations += $this->combinations($id, $reference, $options);
                $withCombinations++;
            }

            foreach ($i <= 300 ? [1, 2] : [1] as $position) {
                $this->image($id, $position, $i, $imageTypes);
                $images++;
            }
        }
        $this->counts['products'] = 500;
        $this->counts['products_with_combinations'] = $withCombinations;
        $this->counts['combinations'] = $combinations;
        $this->counts['images'] = $images;
    }

    /**
     * @param  array{0: list<int>, 1: list<int>}  $options
     */
    private function combinations(int $productId, string $reference, array $options): int
    {
        $count = $this->random->int(2, 6);
        $pairs = [];
        foreach ($options[0] as $colour) {
            foreach ($options[1] as $size) {
                $pairs[] = [$colour, $size];
            }
        }
        $made = 0;
        foreach ($this->random->pickDistinct($pairs, $count) as $n => $pair) {
            $combination = new Combination;
            $combination->id_product = $productId;
            $combination->reference = $reference.'-'.($n + 1);
            $combination->price = sprintf('%d.00', $this->random->int(0, 20));
            $combination->default_on = $n === 0 ? 1 : null;
            $combination->minimal_quantity = 1;
            $this->save($combination, 'combination');
            $combination->setAttributes($pair);
            StockAvailable::setQuantity($productId, (int) $combination->id, $this->random->int(0, 40));
            $made++;
        }
        Product::updateDefaultAttribute($productId);

        return $made;
    }

    /**
     * @param  list<array<string, mixed>>  $types
     */
    private function image(int $productId, int $position, int $i, array $types): void
    {
        $image = new Image;
        $image->id_product = $productId;
        $image->position = $position;
        $image->cover = $position === 1;
        $image->legend = $this->lang(sprintf('KX-%04d - %d', $i, $position), sprintf('KX-%04d - %d', $i, $position));
        $this->save($image, 'image');
        $base = $image->getPathForCreation();

        $gd = imagecreatetruecolor(160, 160);
        $color = imagecolorallocate($gd, ($i * 37) % 256, ($i * 71 + $position * 40) % 256, ($i * 113) % 256);
        imagefilledrectangle($gd, 0, 0, 159, 159, $color);
        imagefilledrectangle($gd, 40, 40, 119, 119, imagecolorallocate($gd, 255, 255, 255));
        imagejpeg($gd, $base.'.jpg', 80);
        unset($gd); // imagedestroy() is a no-op since PHP 8.0 and deprecated in 8.5
        foreach ($types as $type) {
            ImageManager::resize($base.'.jpg', $base.'-'.$type['name'].'.jpg', (int) $type['width'], (int) $type['height']);
        }
    }

    private function ean13(int $i): string
    {
        $digits = sprintf('380%09d', 100000000 + $i);
        $sum = 0;
        for ($n = 0; $n < 12; $n++) {
            $sum += (int) $digits[$n] * ($n % 2 === 0 ? 1 : 3);
        }

        return $digits.((10 - $sum % 10) % 10);
    }

    /**
     * @return array<int, string>
     */
    private function lang(string $en, string $bg): array
    {
        $values = [];
        foreach (Language::getLanguages(false) as $language) {
            $values[(int) $language['id_lang']] = (int) $language['id_lang'] === $this->bg ? $bg : $en;
        }

        return $values;
    }

    private function save(ObjectModel $object, string $what): void
    {
        if (! $object->add()) {
            throw new RuntimeException('Could not create '.$what.'.');
        }
    }

    /**
     * The digest input: the fixture as the shop stored it, read back from the database by natural
     * keys (references, names, language iso codes), never by ids, so it compares across versions.
     * Covers categories (names in every language, parent), brands, suppliers, features and their
     * values, and per KX-* product: price, weight, EAN, brand, supplier, stock, name and link_rewrite
     * in every language, categories, default category, features, combinations (reference, price,
     * default, attributes, stock), images (count, position, cover) and tags in every language.
     *
     * @return list<string>
     */
    private function storedCatalog(): array
    {
        $p = _DB_PREFIX_;
        $shop = (int) Configuration::get('PS_SHOP_DEFAULT');
        $en = $this->en;
        $iso = [];
        foreach (Language::getLanguages(false) as $language) {
            $iso[(int) $language['id_lang']] = (string) $language['iso_code'];
        }
        $byLang = static function (array $rows, string $field) use ($iso): string {
            $out = [];
            foreach ($rows as $row) {
                $out[] = ($iso[(int) $row['id_lang']] ?? '?').'='.$row[$field];
            }
            sort($out, SORT_STRING);

            return implode(';', $out);
        };
        $map = function (string $sql, string $key, string $value): array {
            $out = [];
            foreach ($this->rows($sql) as $row) {
                $out[(int) $row[$key]] = (string) $row[$value];
            }

            return $out;
        };
        $names = static function (array $ids, array $map): string {
            $out = [];
            foreach ($ids as $id) {
                $out[] = $map[(int) $id] ?? '?';
            }
            sort($out, SORT_STRING);

            return implode(',', $out);
        };

        $categoryName = $map("SELECT id_category, name FROM {$p}category_lang WHERE id_lang = {$en} AND id_shop = {$shop}", 'id_category', 'name');
        $brandName = $map("SELECT id_manufacturer, name FROM {$p}manufacturer", 'id_manufacturer', 'name');
        $supplierName = $map("SELECT id_supplier, name FROM {$p}supplier", 'id_supplier', 'name');
        $featureName = $map("SELECT id_feature, name FROM {$p}feature_lang WHERE id_lang = {$en}", 'id_feature', 'name');
        $valueName = $map("SELECT id_feature_value, value FROM {$p}feature_value_lang WHERE id_lang = {$en}", 'id_feature_value', 'value');
        $attributeName = $map("SELECT id_attribute, name FROM {$p}attribute_lang WHERE id_lang = {$en}", 'id_attribute', 'name');
        $attributeGroup = $map("SELECT id_attribute, id_attribute_group FROM {$p}attribute", 'id_attribute', 'id_attribute_group');
        $groupName = $map("SELECT id_attribute_group, name FROM {$p}attribute_group_lang WHERE id_lang = {$en}", 'id_attribute_group', 'name');

        $lines = [];
        foreach ($this->rows("SELECT c.id_category, c.id_parent FROM {$p}category c JOIN {$p}category_lang cl ON cl.id_category = c.id_category AND cl.id_lang = {$en} AND cl.id_shop = {$shop} WHERE cl.name LIKE 'Department %' OR cl.name LIKE 'Category %'") as $c) {
            $id = (int) $c['id_category'];
            $lines[] = implode('|', ['category', $categoryName[$id], 'parent='.($categoryName[(int) $c['id_parent']] ?? '?'),
                $byLang($this->rows("SELECT id_lang, name FROM {$p}category_lang WHERE id_category = {$id} AND id_shop = {$shop}"), 'name')]);
        }
        foreach ($this->rows("SELECT fv.id_feature, fv.id_feature_value FROM {$p}feature_value fv JOIN {$p}feature_lang fl ON fl.id_feature = fv.id_feature AND fl.id_lang = {$en} WHERE fl.name LIKE 'Feature %'") as $v) {
            $lines[] = 'feature_value|'.$featureName[(int) $v['id_feature']].'='.$valueName[(int) $v['id_feature_value']];
        }
        $brands = array_values(array_filter($brandName, static function (string $name): bool {
            return strpos($name, 'Brand ') === 0;
        }));
        $suppliers = array_values(array_filter($supplierName, static function (string $name): bool {
            return strpos($name, 'Supplier ') === 0;
        }));
        sort($brands, SORT_STRING);
        sort($suppliers, SORT_STRING);
        $lines[] = 'brands|'.implode(',', $brands);
        $lines[] = 'suppliers|'.implode(',', $suppliers);

        foreach ($this->rows("SELECT id_product, reference, price, weight, ean13, id_manufacturer, id_supplier, id_category_default FROM {$p}product WHERE reference LIKE 'KX-%'") as $product) {
            $id = (int) $product['id_product'];
            $ref = (string) $product['reference'];
            $lines[] = implode('|', ['product', $ref, $product['price'], $product['weight'], $product['ean13'],
                $brandName[(int) $product['id_manufacturer']] ?? '-', $supplierName[(int) $product['id_supplier']] ?? '-',
                'default='.($categoryName[(int) $product['id_category_default']] ?? '?'),
                'stock='.StockAvailable::getQuantityAvailableByProduct($id, 0, $shop)]);
            $lang = $this->rows("SELECT id_lang, name, link_rewrite FROM {$p}product_lang WHERE id_product = {$id} AND id_shop = {$shop}");
            $lines[] = $ref.'|name|'.$byLang($lang, 'name');
            $lines[] = $ref.'|link_rewrite|'.$byLang($lang, 'link_rewrite');
            $lines[] = $ref.'|categories|'.$names(array_column($this->rows("SELECT id_category FROM {$p}category_product WHERE id_product = {$id}"), 'id_category'), $categoryName);
            $features = [];
            foreach ($this->rows("SELECT id_feature, id_feature_value FROM {$p}feature_product WHERE id_product = {$id}") as $f) {
                $features[] = $featureName[(int) $f['id_feature']].'='.$valueName[(int) $f['id_feature_value']];
            }
            sort($features, SORT_STRING);
            $lines[] = $ref.'|features|'.implode(',', $features);
            foreach ($this->rows("SELECT id_product_attribute, reference, price, default_on FROM {$p}product_attribute WHERE id_product = {$id}") as $combination) {
                $ipa = (int) $combination['id_product_attribute'];
                $attributes = [];
                foreach ($this->rows("SELECT id_attribute FROM {$p}product_attribute_combination WHERE id_product_attribute = {$ipa}") as $a) {
                    $attributes[] = $groupName[(int) $attributeGroup[(int) $a['id_attribute']]].'='.$attributeName[(int) $a['id_attribute']];
                }
                sort($attributes, SORT_STRING);
                $lines[] = implode('|', ['combination', $combination['reference'], $combination['price'], 'default='.(int) $combination['default_on'],
                    implode(',', $attributes), 'stock='.StockAvailable::getQuantityAvailableByProduct($id, $ipa, $shop)]);
            }
            $images = [];
            foreach ($this->rows("SELECT position, cover FROM {$p}image WHERE id_product = {$id}") as $image) {
                $images[] = (int) $image['position'].((int) $image['cover'] === 1 ? ':cover' : '');
            }
            sort($images, SORT_STRING);
            $lines[] = $ref.'|images|'.count($images).'|'.implode(',', $images);
            $lines[] = $ref.'|tags|'.$byLang($this->rows("SELECT t.id_lang, t.name FROM {$p}product_tag pt JOIN {$p}tag t ON t.id_tag = pt.id_tag WHERE pt.id_product = {$id}"), 'name');
        }
        sort($lines, SORT_STRING);

        return $lines;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        $rows = Db::getInstance()->executeS($sql);
        if (! is_array($rows)) {
            throw new RuntimeException('Fixture digest query failed.');
        }

        return array_values($rows);
    }
}

try {
    $summary = (new KxFixture($seed))->run($seed);
} catch (Throwable $e) {
    fwrite(STDERR, 'fixture failed: '.$e->getMessage()."\n");
    exit(1);
}
echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
