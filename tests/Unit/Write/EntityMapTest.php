<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Write;

use Kronixon\Connect\Tests\Support\CatalogDb;
use Kronixon\Connect\Write\EntityMap;
use Kronixon\Connect\Write\Item;
use Kronixon\Connect\Write\ItemError;
use PHPUnit\Framework\TestCase;

/**
 * Which entity an item names, and the links of kx_entity_map.
 */
final class EntityMapTest extends TestCase
{
    private const KX = '0192b2e4-2f7a-7c5b-8a1d-3e4f5a6b7c8d';

    private const OTHER = '0192b2e4-2f7a-7c5b-8a1d-3e4f5a6b7c8e';

    /** @var CatalogDb */
    private $db;

    /** @var EntityMap */
    private $map;

    protected function setUp(): void
    {
        $this->db = new CatalogDb();
        $this->map = new EntityMap($this->db);
    }

    public function test_external_id_then_kx_id_then_match_then_create(): void
    {
        $this->map->link('product', 7, self::KX);

        self::assertSame(5, $this->resolve(['external_id' => '5', 'kx_id' => self::KX], [5, 7], [9]), 'external_id first');
        self::assertSame(7, $this->resolve(['external_id' => null, 'kx_id' => strtoupper(self::KX)], [5, 7], [9]), 'then kx_id');
        self::assertSame(7, $this->resolve(['external_id' => '0', 'kx_id' => self::KX], [7], [9]), '"0" is none');
        self::assertSame(9, $this->resolve(['kx_id' => self::OTHER], [7], [9]), 'then match_by');
        self::assertNull($this->resolve(['kx_id' => self::OTHER], [7], []), 'else a create');
    }

    public function test_a_given_external_id_that_is_gone_is_never_a_create(): void
    {
        try {
            $this->resolve(['external_id' => '99', 'kx_id' => self::KX], [7], [9]);
            self::fail('resolved');
        } catch (ItemError $error) {
            self::assertSame('not_found', $error->itemCode());
            self::assertSame('external_id', $error->result()['field']);
        }
    }

    public function test_a_link_to_a_deleted_entity_is_forgotten(): void
    {
        $this->map->link('product', 7, self::KX);

        self::assertNull($this->resolve(['kx_id' => self::KX], [], []));
        self::assertNull($this->map->find('product', self::KX));
    }

    public function test_more_than_one_match_names_the_candidates(): void
    {
        try {
            $this->resolve([], [], [3, 4]);
            self::fail('resolved');
        } catch (ItemError $error) {
            self::assertSame('match_ambiguous', $error->itemCode());
            self::assertSame(['3', '4'], $error->result()['candidates']);
        }
    }

    public function test_a_link_is_replaced_from_either_side_and_keeps_the_entitys_flags(): void
    {
        $this->map->link('product', 7, self::KX);
        $this->db->execute('UPDATE ps_kx_entity_map SET flags = 1');
        $queries = $this->db->queries;
        $this->map->link('product', 7, self::KX);
        self::assertSame($queries + 1, $this->db->queries, 'the same link again only looks');

        $this->map->link('product', 7, self::OTHER);
        self::assertNull($this->map->find('product', self::KX));
        self::assertSame(7, $this->map->find('product', self::OTHER));
        self::assertSame('1', $this->db->rows('SELECT flags FROM ps_kx_entity_map')[0]['flags'], 'hidden_by_kx belongs to the product');

        $this->map->link('product', 8, self::OTHER);
        self::assertSame(8, $this->map->find('product', self::OTHER));
        self::assertCount(1, $this->db->rows('SELECT * FROM ps_kx_entity_map'));
        self::assertSame('0', $this->db->rows('SELECT flags FROM ps_kx_entity_map')[0]['flags']);
    }

    public function test_entity_types_do_not_mix(): void
    {
        $this->map->link('product', 7, self::KX);
        $this->map->link('category', 7, self::OTHER);

        self::assertNull($this->map->find('category', self::KX));
        self::assertSame(7, $this->map->find('category', self::OTHER));
        $this->map->link('brand', 1, null);
        self::assertCount(2, $this->db->rows('SELECT * FROM ps_kx_entity_map'));
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<int>  $existing
     * @param  list<int>  $matches
     */
    private function resolve(array $item, array $existing, array $matches): ?int
    {
        return $this->map->resolve(new Item($item), 'product', static function (int $id) use ($existing): bool {
            return in_array($id, $existing, true);
        }, static function () use ($matches): array {
            return $matches;
        });
    }
}
