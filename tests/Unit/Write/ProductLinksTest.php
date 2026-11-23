<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Write;

use Configuration;
use Context;
use Kronixon\Connect\Compat\Ps9;
use Kronixon\Connect\Redirect\Pages;
use Kronixon\Connect\Redirect\RedirectStore;
use Kronixon\Connect\Support\Log;
use Kronixon\Connect\Tests\Support\CatalogDb;
use Kronixon\Connect\Tests\Support\MemorySettings;
use Kronixon\Connect\Write\Item;
use Kronixon\Connect\Write\ItemError;
use Kronixon\Connect\Write\PendingWrites;
use Kronixon\Connect\Write\ProductRedirect;
use Kronixon\Connect\Write\ProductRelations;
use Kronixon\Connect\Write\RedirectTarget;
use Kronixon\Connect\Write\Shop;
use Kronixon\Connect\Write\Warnings;
use PHPUnit\Framework\TestCase;
use Product;
use Tag;

require_once dirname(__DIR__, 2).'/Support/prestashop-stubs.php';

/**
 * Tags by mode, and where a redirect may lead.
 */
final class ProductLinksTest extends TestCase
{
    /** @var CatalogDb */
    private $db;

    /** @var Warnings */
    private $warnings;

    protected function setUp(): void
    {
        $this->db = new CatalogDb();
        $this->warnings = new Warnings();
        Tag::$asked = [];
        Configuration::$values = ['PS_REWRITING_SETTINGS' => '1'];
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<array{0: string, 1: int, 2: list<string>}>}>
     */
    public function tagGroups(): iterable
    {
        yield 'no mode adds' => [['values' => ['en' => ['c']]], [['add', 1, ['c']]]];
        yield 'merge adds what is missing' => [['mode' => 'merge', 'values' => ['en' => ['A', 'd']]], [['add', 1, ['d']]]];
        yield 'nothing missing' => [['values' => ['en' => ['b']]], []];
        yield 'an empty list adds nothing' => [['values' => ['en' => []]], []];
        yield 'replace' => [['mode' => 'replace', 'values' => ['en' => ['c', 'a']]], [['delete', 1, []], ['add', 1, ['c', 'a']]]];
        yield 'replace by the same' => [['mode' => 'replace', 'values' => ['en' => ['b', 'a']]], []];
        yield 'replace by none' => [['mode' => 'replace', 'values' => ['en' => []]], [['delete', 1, []]]];
        yield 'a language not sent is left alone' => [['mode' => 'replace', 'values' => ['bg' => ['x']]], [['delete', 2, []], ['add', 2, ['x']]]];
    }

    /**
     * @dataProvider tagGroups
     *
     * @param  array<string, mixed>  $group
     * @param  list<array{0: string, 1: int, 2: list<string>}>  $asked
     */
    public function test_only_replace_removes_tags(array $group, array $asked): void
    {
        foreach (['a', 'b'] as $i => $name) {
            $this->db->insert('tag', ['id_tag' => $i + 1, 'id_lang' => 1, 'name' => $name]);
            $this->db->insert('product_tag', ['id_product' => 5, 'id_tag' => $i + 1, 'id_lang' => 1]);
        }
        $relations = new ProductRelations($this->shop());
        $item = new Item($group, 'tags');

        self::assertSame($asked !== [], $relations->tags(new Product(5), $item, $relations->wantedTags($item)));
        self::assertSame($asked, Tag::$asked);
    }

    public function test_tags_in_a_language_the_shop_lacks(): void
    {
        $item = new Item(['values' => ['fr' => ['x']]], 'tags');

        self::assertSame([], (new ProductRelations($this->shop()))->wantedTags($item));
        self::assertSame(['language_unknown'], $this->warnings->take());
        try {
            (new ProductRelations($this->shop(true)))->wantedTags($item);
            self::fail('accepted');
        } catch (ItemError $error) {
            self::assertSame(['language_unknown', 'tags.values.fr'], [$error->itemCode(), $error->field()]);
        }
    }

    public function test_where_a_category_redirect_may_lead(): void
    {
        foreach ([10, 11, 12] as $id) {
            $this->category($id, false);
        }
        $this->category(20, true);
        $this->db->product(5);

        self::assertSame('ok', $this->check('category', 10, 'category', 20));
        self::assertSame('ok', $this->check('category', 10, 'product', 5));
        self::assertSame('target_not_found', $this->check('category', 10, 'category', 99));
        self::assertSame('target_not_found', $this->check('category', 10, 'category', 0));
        self::assertSame('target_inactive', $this->check('category', 10, 'category', 11));
        self::assertSame('redirect_loop', $this->check('category', 10, 'category', 10));
        $this->redirect(10, 'category', 20);
        self::assertSame('redirect_chain', $this->check('category', 11, 'category', 10));
        self::assertSame('redirect_loop', $this->check('category', 20, 'category', 10), 'two steps');
        $this->redirect(11, 'category', 10);
        $this->redirect(12, 'category', 11);
        self::assertSame('redirect_loop', $this->check('category', 20, 'category', 12), 'four steps');
        self::assertSame('redirect_chain', $this->check('category', 21, 'category', 12));
    }

    public function test_a_loop_already_there_ends_the_search(): void
    {
        $this->category(10, false);
        $this->category(11, false);
        $this->redirect(10, 'category', 11);
        $this->redirect(11, 'category', 10);

        self::assertSame('redirect_chain', $this->check('category', 12, 'category', 10));
    }

    public function test_product_redirects_count_also_while_the_product_is_active(): void
    {
        $this->db->product(1, [], ['redirect_type' => '301-product', 'id_type_redirected' => 2]);
        $this->db->product(2, [], ['redirect_type' => '302-category', 'id_type_redirected' => 0, 'id_category_default' => 10]);
        $this->category(10, true);
        $this->redirect(10, 'product', 3);
        $this->db->product(3);
        $this->db->product(4, [], ['active' => 0]);

        self::assertSame('redirect_loop', $this->check('product', 3, 'product', 1), 'through the default category');
        self::assertSame('ok', $this->check('product', 5, 'product', 1), 'an active target answers with its own page');
        self::assertSame('target_inactive', $this->check('product', 5, 'product', 4));
        $this->db->execute("UPDATE ps_product_shop SET redirect_type = 'default' WHERE id_product = 2");
        self::assertSame('ok', $this->check('product', 3, 'product', 1));
        Configuration::$values['PS_PRODUCT_REDIRECTION_DEFAULT'] = '301-category';
        self::assertSame('redirect_loop', $this->check('product', 3, 'product', 1), 'the shop\'s default redirect');
    }

    public function test_a_products_own_redirect(): void
    {
        $this->db->product(1);
        $this->db->product(2, [], ['active' => 0]);
        $product = new Product(1);
        $set = function (array $redirect, bool $unchangedToo = false) use ($product): string {
            $fields = $this->shop()->fields($product);
            try {
                ProductRedirect::set($this->shop(), $fields, $product, new Item($redirect, 'seo.redirect'), 'type', 'target_id', $unchangedToo);
            } catch (ItemError $error) {
                return $error->itemCode().' '.$error->field();
            }

            return $fields->changed() ? 'changed' : 'same';
        };

        self::assertSame('target_inactive seo.redirect.target_id', $set(['type' => '301-product', 'target_id' => '2']));
        // The product has it by now (the stub keeps what was set): deactivated since.
        self::assertSame('same', $set(['type' => '301-product', 'target_id' => '2']));
        self::assertSame('target_inactive seo.redirect.target_id', $set(['type' => '301-product', 'target_id' => '2'], true));
        self::assertSame('target_not_found seo.redirect.target_id', $set(['type' => '302-product', 'target_id' => '0']));
        self::assertSame('redirect_loop seo.redirect.target_id', $set(['type' => '301-product', 'target_id' => '1']));
        self::assertSame('validation_failed seo.redirect.type', $set(['type' => '307']));
        self::assertSame('changed', $set(['type' => '404', 'target_id' => '2']), 'no target, nothing to check');
        self::assertSame('changed', $set(['type' => '301-category', 'target_id' => '0']), 'its own default category');
        $this->redirect(2, 'product', 1);
        self::assertSame('redirect_loop seo.redirect.target_id', $set(['type' => '302-category', 'target_id' => '0']));
    }

    private function check(string $entity, int $id, string $target, int $targetId): string
    {
        try {
            (new RedirectTarget($this->shop()))->check($entity, $id, $target, $targetId, 'target_id');
        } catch (ItemError $error) {
            self::assertSame('target_id', $error->field());

            return $error->itemCode();
        }

        return 'ok';
    }

    private function category(int $id, bool $active): void
    {
        $this->db->insert('category', ['id_category' => $id, 'id_parent' => 2, 'active' => (int) $active]);
        $this->db->link('category_shop', 'id_category', $id);
    }

    private function redirect(int $category, string $target, int $targetId): void
    {
        $this->db->insert('kx_redirect', ['id_shop' => 1, 'path_from' => "/$category-category", 'target_type' => $target, 'target_id' => $targetId, 'code' => 301]);
    }

    private function shop(bool $strict = false): Shop
    {
        $scope = $this->db->scope();
        $pages = new Pages($this->db, $scope, static function (): array {
            return [];
        }, '/', __DIR__);
        $pending = new PendingWrites($this->db, 1, new Log(static function (): void {
        }));

        return new Shop(new Context(), $scope, new Ps9(), $this->db, $this->db, new RedirectStore($this->db, new MemorySettings(), 1), $pages, $pending, $this->warnings, $strict);
    }
}
