<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Write;

use Category;
use Combination;
use Kronixon\Connect\Api\Budget;
use Kronixon\Connect\Support\Log;
use Kronixon\Connect\Tests\Support\CatalogDb;
use Kronixon\Connect\Tests\Support\FixedClock;
use Kronixon\Connect\Write\EntityMap;
use Kronixon\Connect\Write\Item;
use Kronixon\Connect\Write\ItemError;
use Kronixon\Connect\Write\PendingWrites;
use ObjectModel;
use PHPUnit\Framework\TestCase;
use Product;
use TypeError;

require_once dirname(__DIR__, 2).'/Support/prestashop-stubs.php';

/**
 * kx_pending: a half-written object is found and removed whatever happened to the item, the clean-up or
 * the request that wrote it, and nothing finds or repeats it before that.
 */
final class PendingWritesTest extends TestCase
{
    private const KX = '0192b2e4-2f7a-7c5b-8a1d-3e4f5a6b7c8d';

    /** @var CatalogDb */
    private $db;

    /** @var PendingWrites */
    private $pending;

    /** @var list<array<string, string|null>> */
    private $logged = [];

    protected function setUp(): void
    {
        $this->db = new CatalogDb();
        $this->pending = $this->request();
        ObjectModel::$deleted = [];
        ObjectModel::$onDelete = [$this, 'delete'];
    }

    protected function tearDown(): void
    {
        ObjectModel::$onDelete = null;
        ObjectModel::$deleted = [];
    }

    public function test_a_rollback_takes_the_object_and_its_mark(): void
    {
        $product = $this->stored();
        $this->db->begin();
        self::assertTrue($this->pending->add($product));
        self::assertSame(['77'], $this->marks());
        $this->db->rollback();

        self::assertTrue($this->pending->undo());
        self::assertSame(['add'], $product->calls);
        self::assertSame([], $this->marks());
    }

    public function test_a_complete_item_leaves_no_mark_and_is_never_undone(): void
    {
        $product = $this->stored();
        $this->db->begin();
        $this->pending->add($product);
        $this->pending->seal();
        $this->db->commit();
        $this->pending->keep();

        self::assertSame([], $this->marks());
        self::assertTrue($this->pending->undo());
        $this->request()->recover($this->budget());
        self::assertSame([], ObjectModel::$deleted);
        self::assertSame(['77'], $this->ids('product'));
    }

    public function test_an_add_that_stores_commits_and_throws_is_undone(): void
    {
        $product = $this->stored(true);
        $this->db->begin();
        try {
            $this->pending->add($product);
            self::fail('added');
        } catch (TypeError $error) {
            self::assertSame(['77'], $this->marks(), 'committed with the object');
        }
        $this->map()->link('product', 77, self::KX);

        self::assertTrue($this->pending->undo());
        self::assertSame(['add', 'delete'], $product->calls);
        self::assertSame([[], []], [$this->marks(), $this->ids('product')]);
        self::assertNull($this->map()->find('product', self::KX), 'the link goes with the object');
    }

    public function test_the_hook_marks_only_what_this_request_is_adding(): void
    {
        $marks = null;
        $product = $this->stored();
        $product->onAdd = function (ObjectModel $object) use (&$marks): void {
            $other = new Product(5);
            PendingWrites::added($other);
            PendingWrites::added(null);
            PendingWrites::added($object);
            $marks = $this->marks();
        };
        $this->pending->add($product);
        PendingWrites::added(new Product(6));

        self::assertSame(['77'], $marks, 'marked before any hook of its own kind runs');
        self::assertSame(['77'], $this->marks());
    }

    public function test_one_that_cannot_be_removed_does_not_stop_the_others(): void
    {
        $category = new Category();
        $category->onAdd = function (): void {
            $this->db->insert('category', ['id_category' => 77, 'id_parent' => 2]);
        };
        $this->pending->add($category);
        $product = $this->stored();
        $this->pending->add($product);
        ObjectModel::$onDelete = function (ObjectModel $object): void {
            if ($object instanceof Product) {
                throw new TypeError('hook');
            }
            $this->delete($object);
        };

        self::assertFalse($this->pending->undo());
        self::assertSame([['77'], []], [$this->ids('product'), $this->ids('category')]);
        self::assertTrue($this->pending->isLeft('product', 77));
        self::assertFalse($this->pending->isLeft('category', 77));
        self::assertTrue($this->pending->claims(strtoupper(self::KX)));
        self::assertSame(['TypeError'], array_map(static function (array $row): string {
            return json_decode((string) $row['context'], true)['exception'];
        }, $this->logged));

        // Nothing adopts it, creates it again or is linked to it.
        foreach ([['external_id' => '77'], ['kx_id' => self::KX], ['match_by' => ['reference']]] as $data) {
            try {
                $this->map()->resolve(new Item($data), 'product', static function (): bool {
                    return true;
                }, static function (): array {
                    return [77];
                });
                self::fail('resolved by '.key($data));
            } catch (ItemError $error) {
                self::assertSame(['cleanup_failed', key($data)], [$error->itemCode(), $error->field()]);
            }
        }

        // The next batch tries again, first in vain.
        $this->pending->recover($this->budget());
        self::assertTrue($this->pending->isLeft('product', 77));
        ObjectModel::$onDelete = [$this, 'delete'];
        $this->pending->recover($this->budget());
        self::assertFalse($this->pending->isLeft('product', 77));
        self::assertSame([[], []], [$this->ids('product'), $this->marks()]);
    }

    public function test_a_delete_that_removes_nothing_is_not_a_removal(): void
    {
        $this->pending->add($this->stored());
        ObjectModel::$onDelete = null;

        self::assertFalse($this->pending->undo());
        self::assertTrue($this->pending->isLeft('product', 77));
    }

    public function test_what_a_dead_request_left_goes_before_the_next_batch(): void
    {
        $this->dead();

        $next = $this->request();
        $next->recover($this->budget());

        self::assertSame([[], []], [$this->ids('product'), $this->marks()]);
        self::assertFalse($next->isLeft('product', 77));
    }

    public function test_without_time_left_it_stays_out_of_reach(): void
    {
        $this->dead();
        $clock = new FixedClock(1790772000.0);
        $budget = new Budget($clock, 25);
        $clock->advance(24.5);

        $next = $this->request();
        $next->recover($budget);

        self::assertSame(['77'], $this->ids('product'));
        self::assertTrue($next->isLeft('product', 77));
    }

    public function test_another_shops_marks_are_its_own(): void
    {
        $this->dead();

        $this->request(2)->recover($this->budget());

        self::assertSame([['77'], ['77']], [$this->ids('product'), $this->marks()]);
    }

    public function test_an_object_that_came_to_the_id_later_is_never_deleted(): void
    {
        $this->dead();
        $this->db->execute("UPDATE ps_product SET date_add = '".date('Y-m-d H:i:s', time() + 3600)."'");

        $this->request()->recover($this->budget());

        self::assertSame([], ObjectModel::$deleted);
        self::assertSame([['77'], []], [$this->ids('product'), $this->marks()]);
    }

    public function test_a_category_that_has_children_since_is_kept(): void
    {
        $this->db->insert('category', ['id_category' => 77, 'id_parent' => 2, 'date_add' => date('Y-m-d H:i:s')]);
        $this->db->insert('category', ['id_category' => 78, 'id_parent' => 77]);
        $this->mark('Category', 'category', 77);

        $this->request()->recover($this->budget());

        self::assertSame([], ObjectModel::$deleted);
        self::assertSame([['77', '78'], []], [$this->ids('category'), $this->marks()]);
    }

    public function test_what_one_item_left_goes_last_first(): void
    {
        $this->db->insert('product_attribute', ['id_product_attribute' => 77, 'id_product' => 1]);
        $this->db->insert('category', ['id_category' => 77, 'id_parent' => 2, 'date_add' => date('Y-m-d H:i:s')]);
        $this->mark('Category', 'category', 77);
        $this->mark('Combination', 'product_attribute', 77);

        $this->request()->recover($this->budget());

        self::assertSame([Combination::class, Category::class], array_map('get_class', ObjectModel::$deleted));
        self::assertSame([], $this->marks());
    }

    public function test_a_removal_cut_short_after_the_objects_row_is_finished(): void
    {
        $this->mark('Product', 'product', 77, 1);
        $this->mark('Product', 'product', 78);

        $this->request()->recover($this->budget());

        self::assertSame([77], array_map(static function (ObjectModel $object): ?int {
            return $object->id;
        }, ObjectModel::$deleted), 'one that was never being removed was rolled back or deleted by somebody else');
        self::assertSame([], $this->marks());
    }

    public function test_a_class_that_is_no_object_model_is_left_alone(): void
    {
        $this->mark('ArrayObject', 'product', 77);
        $this->mark('NoSuchClass', 'product', 78);

        $next = $this->request();
        $next->recover($this->budget());

        self::assertTrue($next->isLeft('product', 77));
        self::assertSame([], ObjectModel::$deleted);
    }

    /** The stub's delete(): the object's row goes. */
    public function delete(ObjectModel $object): void
    {
        $definition = ObjectModel::getDefinition($object);
        $this->db->delete($definition['table'], $definition['primary'].' = '.(int) $object->id);
    }

    /** A product whose add() stores its row; $throws: a hook commits and the next one fails. */
    private function stored(bool $throws = false): Product
    {
        $product = new Product();
        $product->onAdd = function (ObjectModel $object) use ($throws): void {
            $this->db->insert('product', ['id_product' => 77, 'date_add' => date('Y-m-d H:i:s')]);
            PendingWrites::added($object);
            if ($throws) {
                $this->db->commit();
                throw new TypeError('hook');
            }
        };

        return $product;
    }

    /** A request that ended after a hook had committed what it was creating. */
    private function dead(): void
    {
        $this->pending->add($this->stored());
    }

    private function mark(string $class, string $table, int $id, int $removing = 0): void
    {
        $this->db->insert('kx_pending', ['id_shop' => 1, 'class' => $class, 'entity' => $table, 'id_object' => $id, 'removing' => $removing, 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function request(int $shopId = 1): PendingWrites
    {
        $pending = new PendingWrites($this->db, $shopId, new Log(function (array $row): void {
            $this->logged[] = $row;
        }));
        $pending->item(new Item(['key' => 'a', 'kx_id' => self::KX]));

        return $pending;
    }

    private function map(): EntityMap
    {
        return new EntityMap($this->db, $this->pending);
    }

    private function budget(): Budget
    {
        return new Budget(new FixedClock(1790772000.0), 25);
    }

    /**
     * @return list<string|null>
     */
    private function marks(): array
    {
        return array_column($this->db->rows('SELECT id_object FROM ps_kx_pending ORDER BY id_object'), 'id_object');
    }

    /**
     * @return list<string|null>
     */
    private function ids(string $table): array
    {
        return array_column($this->db->rows('SELECT id_'.$table.' AS id FROM ps_'.$table.' ORDER BY 1'), 'id');
    }
}
