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

namespace Kronixon\Connect\Write;

use Kronixon\Connect\Catalog\Scope;
use Kronixon\Connect\Support\Log;

/**
 * `POST stock/bulk` (module/stock-bulk.json, Spec §6.22): the available quantity of a product without
 * combinations (`variant_id` "0") or of a combination, in the paired shop's stock (its group's when the
 * group shares stock). PrestaShop keeps one stock per shop, so `location` is always `default` and
 * `capabilities.stock_locations` is false. `expected_quantity` guards the quantity.
 *
 * `mode: safe` (the default) goes through StockAvailable::setQuantity() as the back office does:
 * `actionUpdateQuantity`, a stock movement, the product's total of its combinations. `mode: fast`
 * updates `stock_available` directly (quantity, physical quantity = reserved + quantity), sets the
 * product's total once per chunk and runs `actionUpdateQuantity` per row only with `fire_hooks: true`;
 * a row without a stock row yet goes the safe way. Either way `actionKxBulkStockUpdated` runs once a
 * request with the changed product ids.
 *
 * `visibility_policy`: `restore_if_hidden` shows a product hidden from everywhere (`visibility: none`)
 * once it has stock again; `hide_when_zero` hides a product linked to the platform (a `kx_id`) when
 * its total quantity is 0 and shows again only those it hid (`kx_entity_map.flags` bit 1), with the
 * visibility they had (`both`, `catalog` or `search`, bits 2–3). When the totals or the visibility
 * step after a chunk's commit fails, the rows of its products carry `totals_not_updated`; sending
 * them again sets both.
 * `available_now`/`available_later` are texts of the product (POST products, `content`): ignored here
 * with a warning.
 */
final class StockBulk extends Bulk
{
    /** `kx_entity_map.flags`: the product was hidden by `hide_when_zero`. */
    private const HIDDEN_BY_KX = 1;

    /** `kx_entity_map.flags` bits 2–3: the visibility the product had before, an index of VISIBILITIES. */
    private const VISIBILITY_BITS = 6;

    private const VISIBILITIES = ['both', 'catalog', 'search'];

    /** @var callable(int, int, int, int): void StockAvailable::setQuantity() */
    private $setQuantity;

    /** @var callable(int, int, int, int): void StockAvailable::setProductOutOfStock() */
    private $setOutOfStock;

    /** @var callable(): void PrestaShop's stock caches */
    private $flush;

    /** @var string */
    private $mode = 'safe';

    /** @var string */
    private $visibility = 'none';

    /** @var bool */
    private $fireHooks = false;

    /** @var array<int, array{visibility: string, variants: int, flags: int|null}> */
    private $products = [];

    /** @var array<int, int> combination id => product id */
    private $variants = [];

    /** @var list<int> the `id_stock_available` of the chunk's products, ascending (locate()) */
    private $stockIds = [];

    /** @var array<string, array{id: int, quantity: int, out_of_stock: int}> `product:variant` => stock row */
    private $stock = [];

    /** @var int the chunk's longest wait for a row lock */
    private $wait = 1;

    /** @var array<int, list<Warnings>> product id => the warnings of its rows in the chunk */
    private $rowsOf = [];

    /** @var array<int, true> products with a combination written fast in the chunk */
    private $totals = [];

    /**
     * @param  callable(string, array<string, mixed>): void  $hook
     * @param  callable(int, int, int, int): void  $setQuantity  product, combination, quantity, shop
     * @param  callable(int, int, int, int): void  $setOutOfStock  product, policy, shop, combination
     * @param  callable(): void  $flush
     */
    public function __construct(Database $db, Scope $scope, PendingWrites $pending, Log $log, bool $defaultShop, callable $hook, callable $setQuantity, callable $setOutOfStock, callable $flush)
    {
        parent::__construct($db, $scope, $pending, $log, $defaultShop, $hook);
        $this->setQuantity = $setQuantity;
        $this->setOutOfStock = $setOutOfStock;
        $this->flush = $flush;
    }

    protected function options(Item $options): void
    {
        $this->mode = $options->choice('mode', ['safe', 'fast']) ?? 'safe';
        $this->visibility = $options->choice('visibility_policy', ['none', 'restore_if_hidden', 'hide_when_zero']) ?? 'none';
        $this->fireHooks = $options->bool('fire_hooks') ?? false;
    }

    protected function parse(Item $row): array
    {
        $quantity = $row->integer('quantity');
        if ($quantity === null) {
            throw ItemError::invalid($row->at('quantity'));
        }
        $policy = $row->int('out_of_stock');
        if ($policy !== null && $policy > 2) {
            throw ItemError::invalid($row->at('out_of_stock'));
        }
        $expected = $row->integer('expected_quantity');
        $shop = $row->integer('shop_id');
        if ($shop !== null && $shop !== $this->scope->shopId) {
            throw new ItemError('shop_mismatch', 'shop_id is not the paired shop', $row->at('shop_id'));
        }

        return [
            'quantity' => $quantity,
            'out_of_stock' => $policy,
            'expected' => $expected,
            'location' => $row->string('location'),
            'texts' => $row->object('available_now') !== null || $row->object('available_later') !== null,
        ];
    }

    protected function locate(array $rows): void
    {
        $products = [];
        foreach ($rows as $row) {
            $products[] = (int) $row['product'];
        }
        // Every stock row of the chunk's products, their totals included. `stock_available` has no index
        // that reaches them by product in the order of their ids: a locking read by `id_product` is
        // planned as a scan of the whole table on a small shop (434 and 1 134 stock rows, MariaDB
        // 10.11) and locks every row it reads, so the chunk locks them by primary key (load()).
        $this->stockIds = [];
        foreach ($this->db->rows('SELECT sa.id_stock_available FROM '.$this->db->table('stock_available').' sa WHERE '.$this->scope->stockWhere('sa')
            .' AND sa.id_product IN ('.self::in($products).')') as $stock) {
            $this->stockIds[] = (int) $stock['id_stock_available'];
        }
        sort($this->stockIds);
    }

    protected function load(array $rows, int $waitSeconds): void
    {
        $products = [];
        $variants = [];
        foreach ($rows as $row) {
            $products[] = (int) $row['product'];
            if ($row['variant'] !== 0) {
                $variants[] = (int) $row['variant'];
            }
        }
        $ids = self::in($products);
        // First, before any plain read starts the transaction's snapshot: the rows locate() found, locked
        // by primary key in ascending order (a PRIMARY range: only these rows). An order that commits
        // while this waits is in what is read (and in what StockAvailable::setQuantity() reads after
        // it); one that comes later waits for the commit. A row created in between is not locked, as a
        // row that does not exist yet. PrestaShop 1.7.8's order statement scans every stock row in this
        // same order, so the two cannot deadlock, but it waits for any chunk that holds any stock row.
        // 9.x updates an order's rows in one statement in the order of its lines: when that is not the
        // order of their ids, the statement and this read can deadlock; the server rolls one back (on
        // MariaDB this read, which Bulk::chunk() then runs once more; MySQL may roll back the order's
        // statement instead, which PrestaShop does not repeat).
        $this->stock = [];
        // Every later write of these rows goes by primary key too (quantity(), outOfStock(), the totals
        // in committed()), as StockAvailable::setQuantity() does: the order's statement reaches a row
        // through the `id_product` index, its entry first, so a chunk that held the row and then wrote
        // it through that index would wait for the entry the order holds (a deadlock, 9.x).
        $this->wait = $waitSeconds;
        if ($this->stockIds !== []) {
            foreach ($this->db->lockRows('SELECT sa.id_stock_available, sa.id_product, sa.id_product_attribute, sa.quantity, sa.out_of_stock FROM '.$this->db->table('stock_available').' sa'
                .' WHERE sa.id_stock_available IN ('.self::in($this->stockIds).') ORDER BY sa.id_stock_available', $waitSeconds) as $stock) {
                $this->stock[(int) $stock['id_product'].':'.(int) $stock['id_product_attribute']] = ['id' => (int) $stock['id_stock_available'], 'quantity' => (int) $stock['quantity'], 'out_of_stock' => (int) $stock['out_of_stock']];
            }
        }
        $this->products = [];
        foreach ($this->db->rows('SELECT ps.id_product, ps.visibility, (SELECT COUNT(*) FROM '.$this->db->table('product_attribute').' pa WHERE pa.id_product = ps.id_product) AS variants, m.flags FROM '
            .$this->db->table('product_shop').' ps LEFT JOIN '.$this->db->table('kx_entity_map')." m ON m.entity_type = 'product' AND m.external_id = CAST(ps.id_product AS CHAR)"
            .' WHERE ps.id_shop = '.$this->scope->shopId.' AND ps.id_product IN ('.$ids.')') as $product) {
            $this->products[(int) $product['id_product']] = ['visibility' => (string) $product['visibility'], 'variants' => (int) $product['variants'], 'flags' => $product['flags'] === null ? null : (int) $product['flags']];
        }
        $this->variants = [];
        if ($variants !== []) {
            foreach ($this->db->rows('SELECT pa.id_product_attribute, pa.id_product FROM '.$this->db->table('product_attribute').' pa INNER JOIN '.$this->db->table('product_attribute_shop')
                .' pas ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = '.$this->scope->shopId.' WHERE pa.id_product_attribute IN ('.self::in($variants).')') as $variant) {
                $this->variants[(int) $variant['id_product_attribute']] = (int) $variant['id_product'];
            }
        }
        $this->rowsOf = [];
        $this->totals = [];
    }

    protected function row(array $row): array
    {
        $productId = (int) $row['product'];
        $variantId = (int) $row['variant'];
        /** @var Warnings $warnings */
        $warnings = $row['warnings'];
        $this->product($this->products, $productId);
        if ($variantId === 0 && $this->products[$productId]['variants'] > 0) {
            throw ItemError::invalid('variant_id', 'is "0" of a product with combinations: send their quantities');
        }
        if ($variantId !== 0 && ($this->variants[$variantId] ?? null) !== $productId) {
            throw ItemError::missing('variant_id');
        }
        if ($row['location'] !== null && $row['location'] !== 'default') {
            $warnings->add('location_ignored');
        }
        if ($row['texts']) {
            $warnings->add('available_texts_ignored');
        }
        $this->rowsOf[$productId][] = $warnings;
        $stock = $this->stock[$productId.':'.$variantId] ?? null;
        $current = $stock === null ? 0 : $stock['quantity'];
        $quantity = (int) $row['quantity'];
        $policy = $row['out_of_stock'];
        $result = ['previous_quantity' => $current];
        if ($quantity !== $current && $row['expected'] !== null && $row['expected'] !== $current) {
            return ['status' => 'conflict', 'code' => 'quantity_changed', 'current_quantity' => $current] + $result;
        }
        if ($this->mode === 'fast' && $variantId !== 0) {
            // The product's total of its combinations, also for an unchanged row: a repeat after
            // `totals_not_updated` sets it.
            $this->totals[$productId] = true;
        }
        $changed = false;
        if ($quantity !== $current || $stock === null) {
            $this->quantity($productId, $variantId, $quantity, $stock === null ? null : $stock['id']);
            $changed = true;
        }
        if ($policy !== null && ($stock === null || $policy !== $stock['out_of_stock'])) {
            $this->outOfStock($productId, $variantId, $policy, $stock !== null);
            $changed = true;
        }

        return ['status' => 'ok', 'action' => $changed ? 'updated' : 'unchanged'] + $result;
    }

    protected function committed(array $products): void
    {
        $touched = array_keys($this->rowsOf);
        if ($this->totals !== [] || ($this->visibility !== 'none' && $touched !== [])) {
            $this->db->begin();
            try {
                $this->totals();
                if ($this->visibility !== 'none') {
                    $this->visibility($touched);
                }
                $this->db->commit();
            } catch (\Throwable $error) {
                $this->db->rollback();
                $this->log->warning('write', 'Totals or visibility after a stock chunk failed', ['exception' => get_class($error)]);
                // The quantities are committed; the platform sends the rows again (`unchanged`, then
                // the totals and the visibility follow).
                foreach (array_unique(array_merge(array_keys($this->totals), $this->visibility !== 'none' ? $touched : [])) as $id) {
                    $this->warn((int) $id, 'totals_not_updated');
                }
            }
        }
    }

    protected function finish(): array
    {
        $ids = array_keys($this->changed);
        if ($ids !== []) {
            ($this->flush)();
            $this->announce('actionKxBulkStockUpdated', ['ids' => $ids]);
        }

        return [];
    }

    /**
     * The total of the combinations of each product written fast, in the product's row: its stock rows
     * the chunk knew, locked again by primary key in ascending order, summed, and the total written by
     * primary key (a combination created since the chunk read its rows is not in the sum).
     */
    private function totals(): void
    {
        $ids = [];
        foreach ($this->stock as $key => $stock) {
            if (isset($this->totals[(int) $key])) {
                $ids[] = $stock['id'];
            }
        }
        if ($ids === []) {
            return;
        }
        sort($ids);
        $sums = [];
        $totalRows = [];
        foreach ($this->db->lockRows('SELECT sa.id_stock_available, sa.id_product, sa.id_product_attribute, sa.quantity FROM '.$this->db->table('stock_available').' sa'
            .' WHERE sa.id_stock_available IN ('.self::in($ids).') ORDER BY sa.id_stock_available', $this->wait) as $row) {
            $product = (int) $row['id_product'];
            if ((int) $row['id_product_attribute'] === 0) {
                $totalRows[$product] = (int) $row['id_stock_available'];
            } else {
                $sums[$product] = ($sums[$product] ?? 0) + (int) $row['quantity'];
            }
        }
        foreach ($totalRows as $product => $id) {
            $this->db->bulk('UPDATE '.$this->db->table('stock_available').' SET quantity = '.($sums[$product] ?? 0).' WHERE id_stock_available = '.$id);
        }
    }

    private function quantity(int $productId, int $variantId, int $quantity, ?int $stockId): void
    {
        if ($this->mode === 'safe' || $stockId === null) {
            ($this->setQuantity)($productId, $variantId, $quantity, $this->scope->shopId);

            return;
        }
        $this->db->bulk('UPDATE '.$this->db->table('stock_available').' SET quantity = '.$quantity.', physical_quantity = reserved_quantity + '.$quantity
            .' WHERE id_stock_available = '.$stockId);
        if ($this->fireHooks) {
            $this->announce('actionUpdateQuantity', ['id_product' => $productId, 'id_product_attribute' => $variantId, 'quantity' => $quantity, 'id_shop' => $this->scope->shopId]);
        }
    }

    /**
     * The out-of-stock policy as StockAvailable::setProductOutOfStock() sets it (every stock row of the
     * product for variant "0", no hook), by primary key on the rows the chunk holds; a row the shop did
     * not have yet goes through PrestaShop, which creates it.
     */
    private function outOfStock(int $productId, int $variantId, int $policy, bool $exists): void
    {
        if (! $exists) {
            ($this->setOutOfStock)($productId, $policy, $this->scope->shopId, $variantId);

            return;
        }
        $ids = [];
        foreach ($this->stock as $key => $stock) {
            if ($variantId === 0 ? (int) $key === $productId : $key === $productId.':'.$variantId) {
                $ids[] = $stock['id'];
            }
        }
        $this->db->bulk('UPDATE '.$this->db->table('stock_available').' SET out_of_stock = '.$policy.' WHERE id_stock_available IN ('.self::in($ids).')');
    }

    /**
     * @param  list<int>  $products
     */
    private function visibility(array $products): void
    {
        $totals = [];
        foreach ($this->db->rows('SELECT sa.id_product, sa.quantity FROM '.$this->db->table('stock_available').' sa WHERE '.$this->scope->stockWhere('sa')
            .' AND sa.id_product IN ('.self::in($products).') AND sa.id_product_attribute = 0') as $row) {
            $totals[(int) $row['id_product']] = (int) $row['quantity'];
        }
        foreach ($products as $id) {
            $product = $this->products[$id];
            $total = $totals[$id] ?? 0;
            $hidden = $product['visibility'] === 'none';
            $ours = $product['flags'] !== null && ($product['flags'] & self::HIDDEN_BY_KX) !== 0;
            if ($total > 0 && $hidden && ($this->visibility === 'restore_if_hidden' || $ours)) {
                // What the product had before `hide_when_zero` hid it; `both` for one hidden in the shop.
                $before = $ours ? self::VISIBILITIES[((int) $product['flags'] & self::VISIBILITY_BITS) >> 1] : 'both';
                $this->show($id, $before, $ours ? (int) $product['flags'] & ~(self::HIDDEN_BY_KX | self::VISIBILITY_BITS) : null, 'visibility_restored');
            } elseif ($total <= 0 && ! $hidden && $this->visibility === 'hide_when_zero') {
                if ($product['flags'] === null) {
                    $this->warn($id, 'not_hidden_unlinked');
                } else {
                    $code = (int) array_search($product['visibility'], self::VISIBILITIES, true);
                    $this->show($id, 'none', ($product['flags'] & ~self::VISIBILITY_BITS) | self::HIDDEN_BY_KX | ($code << 1), 'visibility_hidden');
                }
            }
        }
    }

    /** The product's visibility (and its search index mark), with the flags of its link when given. */
    private function show(int $id, string $visibility, ?int $flags, string $warning): void
    {
        $this->update('product', 'id_product', $id, ['visibility' => $this->db->quote($visibility), 'indexed' => '0', 'date_upd' => 'NOW()']);
        if ($flags !== null) {
            $this->db->execute('UPDATE '.$this->db->table('kx_entity_map').' SET flags = '.$flags." WHERE entity_type = 'product' AND external_id = ".$this->db->quote((string) $id));
        }
        $this->changed[$id] = true;
        $this->warn($id, $warning);
    }

    private function warn(int $id, string $code): void
    {
        foreach ($this->rowsOf[$id] ?? [] as $warnings) {
            $warnings->add($code);
        }
    }
}
