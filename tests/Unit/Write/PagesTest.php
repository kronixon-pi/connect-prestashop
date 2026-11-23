<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Write;

use Kronixon\Connect\Redirect\Pages;
use Kronixon\Connect\Tests\Support\CatalogDb;
use PHPUnit\Framework\TestCase;

/**
 * Which page answers at a path: routes as PrestaShop's dispatcher keeps them, languages en (1) and bg (2).
 */
final class PagesTest extends TestCase
{
    private const ROUTES = [
        'module' => ['regexp' => '#^/module/(?P<module>[_a-zA-Z0-9-]+)(/(?P<controller>[_a-zA-Z0-9-]+))?$#u'],
        'cms_rule' => ['regexp' => '#^/content/(?P<id_cms>[0-9]+)\-([_a-zA-Z0-9\pL-]*)$#u'],
        'manufacturer_rule' => ['regexp' => '#^/brand/(?P<id_manufacturer>[0-9]+)\-([_a-zA-Z0-9\pL-]*)$#u'],
        'product_rule' => ['regexp' => '#^/(?P<id_product>[0-9]+)\-([_a-zA-Z0-9\pL-]*)\.html$#u'],
        'category_rule' => ['regexp' => '#^/(?P<id_category>[0-9]+)\-([_a-zA-Z0-9\pL-]*)$#u'],
    ];

    /** @var CatalogDb */
    private $db;

    protected function setUp(): void
    {
        $this->db = new CatalogDb();
        $this->db->product(5);
        $this->db->product(6, [], ['active' => 0]);
        $this->db->insert('product', ['id_product' => 7]);
        $this->db->insert('product_shop', ['id_product' => 7, 'id_shop' => 2]);
        $this->db->insert('category', ['id_category' => 5, 'id_parent' => 2]);
        $this->db->link('category_shop', 'id_category', 5);
        $this->db->insert('category', ['id_category' => 6, 'id_parent' => 2, 'active' => 0]);
        $this->db->link('category_shop', 'id_category', 6);
        $this->db->insert('cms', ['id_cms' => 3]);
        $this->db->link('cms_shop', 'id_cms', 3);
        $this->db->insert('cms_lang', ['id_cms' => 3, 'id_lang' => 1, 'id_shop' => 1, 'link_rewrite' => 'about-us']);
        $this->db->insert('manufacturer', ['id_manufacturer' => 4, 'name' => 'B', 'active' => 1]);
        $this->db->link('manufacturer_shop', 'id_manufacturer', 4);
    }

    /**
     * @return iterable<string, array{string, array{0: string, 1: int}|null}>
     */
    public function paths(): iterable
    {
        yield 'an active product' => ['/5-any-name.html', ['product', 5]];
        yield 'with the language' => ['/bg/5-обувки.html', ['product', 5]];
        yield 'an inactive product' => ['/6-x.html', null];
        yield 'a product of another shop' => ['/7-x.html', null];
        yield 'a product nobody has' => ['/99-x.html', null];
        yield 'an active category' => ['/en/5-x', ['category', 5]];
        yield 'an inactive category' => ['/6-x', null];
        yield 'a CMS page' => ['/content/3-about-us', ['cms', 3]];
        yield 'a brand' => ['/en/brand/4-b', ['manufacturer', 4]];
        yield 'a controller' => ['/cart', ['page', 0]];
        yield 'in its language' => ['/bg/количка', ['page', 0]];
        yield 'not in another' => ['/en/количка', null];
        yield 'without one' => ['/количка', ['page', 0]];
        yield 'of a language the shop lacks' => ['/fr/cart', ['page', 0]];
        yield 'a module' => ['/module/any/api', ['page', 0]];
        yield 'home' => ['/', ['page', 0]];
        yield 'a language' => ['/en/', ['page', 0]];
        yield 'without the slash' => ['/bg', ['page', 0]];
        yield 'index.php' => ['/index.php', ['page', 0]];
        yield 'a folder of the installation' => ['/Unit/', ['page', 0]];
        yield 'a file' => ['/bootstrap.php', ['page', 0]];
        yield 'nothing' => ['/old-page.html', null];
        yield 'a picture' => ['/5-old.jpg', null];
    }

    /**
     * @dataProvider paths
     *
     * @param  array{0: string, 1: int}|null  $page
     */
    public function test_the_page_at_a_path(string $path, ?array $page): void
    {
        self::assertSame($page, $this->pages()->at($path));
    }

    public function test_a_shop_in_a_folder(): void
    {
        $pages = $this->pages('/shop/');

        self::assertSame(['product', 5], $pages->at('/shop/5-x.html'));
        self::assertSame(['page', 0], $pages->at('/shop/en/cart'));
        self::assertNull($pages->at('/5-x.html'), 'not this shop');
        self::assertFalse($pages->inside('/5-x.html'));
        self::assertTrue($pages->inside('/shop/5-x.html'));
    }

    public function test_dots_name_no_place(): void
    {
        foreach (['/a/../admin', '/./a', '/..', '/a/.'] as $path) {
            self::assertFalse($this->pages()->inside($path), $path);
            self::assertNull($this->pages()->at($path));
        }
        self::assertTrue($this->pages()->inside('/a..b/.well'));
    }

    public function test_a_rule_without_the_id_is_matched_by_the_slug(): void
    {
        $pages = $this->pages('/', ['cms_rule' => ['regexp' => '#^/pages/([_a-zA-Z0-9\pL-]*)\.html$#u']]);

        self::assertSame(['cms', 3], $pages->at('/pages/about-us.html'));
        self::assertNull($pages->at('/pages/other.html'));
        $this->db->execute('UPDATE ps_cms SET active = 0');
        self::assertNull($pages->at('/pages/about-us.html'));
    }

    public function test_whether_an_entity_is_shown(): void
    {
        $pages = $this->pages();

        self::assertTrue($pages->active('product', 5));
        self::assertFalse($pages->active('product', 6));
        self::assertNull($pages->active('product', 7), 'another shop');
        self::assertTrue($pages->active('category', 5));
        self::assertFalse($pages->active('category', 6));
        self::assertNull($pages->active('category', 99));
    }

    /**
     * @param  array<string, array<string, string>>  $own
     */
    private function pages(string $baseUri = '/', array $own = []): Pages
    {
        return new Pages($this->db, $this->db->scope(), static function () use ($own): array {
            return [
                1 => $own + self::ROUTES + ['cart' => ['regexp' => '#^/cart$#u']],
                2 => $own + self::ROUTES + ['cart' => ['regexp' => '#^/количка$#u']],
            ];
        }, $baseUri, dirname(__DIR__, 2));
    }
}
