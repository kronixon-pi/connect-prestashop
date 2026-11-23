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
 * Both modes write `stock_available` by primary key inside the chunk's transaction, and nothing else
 * runs in it: the quantity, the out-of-stock policy, the product's total of its combinations, and a
 * stock row the shop does not have yet (created by its unique key). `mode: safe` (the default) writes
 * what StockAvailable::setQuantity() writes, the quantity alone, and after the commit runs the hooks
 * that setQuantity() runs, for each row whose quantity changed and for the product's total row, with
 * the rows as committed: `actionObjectUpdateBefore`/`After` and `actionObjectStockAvailableUpdate*`
 * (`Add*` for a row it created) with the StockAvailable, then `actionUpdateQuantity` with the
 * parameters of the shop's version. It records no stock movement (nor does setQuantity() outside the
 * back office). `mode: fast` also sets `physical_quantity = reserved_quantity + quantity` and, with
 * `fire_hooks: true`, runs `actionUpdateQuantity` per changed row after the commit. Either way
 * `actionKxBulkStockUpdated` runs once a request with the changed product ids.
 *
 * `visibility_policy`: `restore_if_hidden` shows a product hidden from everywhere (`visibility: none`)
 * once it has stock again; `hide_when_zero` hides a product linked to the platform (a `kx_id`) when
 * its total quantity is 0 and shows again only those it hid (`kx_entity_map.flags` bit 1), with the
 * visibility they had (`both`, `catalog` or `search`, bits 2–3). When the visibility step after a
 * chunk's commit fails, the rows of its products carry `totals_not_updated`; sending them again sets it.
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

    /** @var callable(int): object the stock row as PrestaShop's StockAvailable, read after the commit */
    private $stockObject;

    /** @var callable(list<int>): void PrestaShop's stock caches, and the color lists of the products given */
    private $flush;

    /** @var array<int, true> products with a combination whose quantity reached or left 0 (their color list) */
    private $colors = [];

    /** @var bool `actionUpdateQuantity` carries `delta_quantity` and `id_shop` (PrestaShop 8.1+) */
    private $hookShop;

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

    /** @var array<string, array{id: int, quantity: int, out_of_stock: int}> `product:variant` => stock row, as written so far */
    private $stock = [];

    /** @var array<int, list<Warnings>> product id => the warnings of its rows in the chunk */
    private $rowsOf = [];

    /** @var array<int, true> products with a combination row in the chunk: their total is set */
    private $totals = [];

    /** @var list<array{id: int, product: int, variant: int, quantity: int, delta: int|null, created: bool, sent: bool}> the quantities the chunk changed, in order */
    private $written = [];

    /**
     * @param  callable(string, array<string, mixed>): void  $hook
     * @param  callable(int): object  $stockObject  `new StockAvailable($id)`
     * @param  callable(list<int>): void  $flush
     */
    public function __construct(Database $db, Scope $scope, PendingWrites $pending, Log $log, bool $defaultShop, callable $hook, callable $stockObject, callable $flush, bool $hookShop)
    {
        parent::__construct($db, $scope, $pending, $log, $defaultShop, $hook);
        $this->stockObject = $stockObject;
        $this->flush = $flush;
        $this->hookShop = $hookShop;
    }

    protected function options(Item $options): void
    {
        $this->mode = $options->choice('mode', ['safe', 'fast']) ?? 'safe';
        $this->visibility = $options->choice('visibility_policy', ['none', 'restore_if_hidden', 'hide_when_zero']) ?? 'none';
        $this->fireHooks = $options->bool('fire_hooks') ?? false;
        $this->colors = [];
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
        // while this waits is in what is read; one that comes later waits for the commit. A row created
        // in between is not locked, as a row that does not exist yet. PrestaShop 1.7.8's order
        // statement scans every stock row in this same order, so the two cannot deadlock, but it waits
        // for any chunk that holds any stock row. 9.x updates an order's rows in one statement in the
        // order of its lines: when that is not the order of their ids, the statement and this read can
        // deadlock; the server rolls one back (on MariaDB this read, which Bulk::chunk() then runs once
        // more; MySQL may roll back the order's statement instead, which PrestaShop does not repeat).
        $this->stock = [];
        // Every later write of these rows goes by primary key too (quantity(), outOfStock(), totals()):
        // the order's statement reaches a row through the `id_product` index, its entry first, so a
        // chunk that held the row and then wrote it through that index would wait for the entry the
        // order holds (a deadlock, 9.x).
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
        $this->written = [];
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
        if ($variantId !== 0) {
            // The product's total of its combinations, also for an unchanged row: a repeat sets it.
            $this->totals[$productId] = true;
        }
        if ($stock === null) {
            $this->create($productId, $variantId, $quantity, $policy, true);

            return ['status' => 'ok', 'action' => 'updated'] + $result;
        }
        $changed = false;
        if ($quantity !== $current) {
            $this->quantity($productId, $variantId, $quantity, $stock, true);
            $changed = true;
        }
        if ($policy !== null && $policy !== $stock['out_of_stock']) {
            $this->outOfStock($productId, $variantId, $policy);
            $changed = true;
        }

        return ['status' => 'ok', 'action' => $changed ? 'updated' : 'unchanged'] + $result;
    }

    /**
     * The total of the combinations of each product with a combination row in the chunk, in the
     * product's row, as StockAvailable::postSave() sets it after a combination's save: the sum of the
     * product's combination rows the chunk holds (a combination created since the chunk read its rows
     * is not in it), written by primary key only when it differs; a product without that row gets one.
     */
    protected function beforeCommit(): void
    {
        foreach (array_keys($this->totals) as $productId) {
            $sum = 0;
            foreach ($this->stock as $key => $stock) {
                [$product, $variant] = explode(':', $key);
                if ((int) $product === $productId && $variant !== '0') {
                    $sum += $stock['quantity'];
                }
            }
            $total = $this->stock[$productId.':0'] ?? null;
            if ($total === null) {
                $this->create($productId, 0, $sum, null, false);
            } elseif ($total['quantity'] !== $sum) {
                $this->quantity($productId, 0, $sum, $total, false);
            }
        }
    }

    protected function committed(array $products): void
    {
        if ($this->written !== []) {
            // The objects the hooks get are read again, as committed.
            ($this->flush)([]);
            $this->hooks();
        }
        $touched = array_keys($this->rowsOf);
        if ($this->visibility !== 'none' && $touched !== []) {
            try {
                $this->db->beginChunk();
                $this->visibility($touched);
                $this->db->commitChunk();
            } catch (\Throwable $error) {
                $this->db->rollbackChunk();
                $this->log->warning('write', 'The visibility after a stock chunk failed', ['exception' => get_class($error)]);
                // The quantities are committed; the platform sends the rows again (`unchanged`, then
                // the visibility follows).
                foreach ($touched as $id) {
                    $this->warn((int) $id, 'totals_not_updated');
                }
            }
        }
    }

    protected function finish(): array
    {
        $ids = array_keys($this->changed);
        if ($ids !== []) {
            ($this->flush)(array_keys($this->colors));
            $this->announce('actionKxBulkStockUpdated', ['ids' => $ids]);
        }

        return [];
    }

    /**
     * After the commit, what StockAvailable::setQuantity() runs for each quantity the chunk changed
     * (`safe`), or `actionUpdateQuantity` for each row sent whose quantity changed (`fast` with
     * `fire_hooks`). A hook that throws is logged; the rows stay written.
     */
    private function hooks(): void
    {
        if ($this->mode === 'fast' && ! $this->fireHooks) {
            return;
        }
        foreach ($this->written as $row) {
            if ($this->mode === 'safe') {
                $object = ($this->stockObject)($row['id']);
                $event = $row['created'] ? 'Add' : 'Update';
                foreach (['Before', 'After'] as $when) {
                    $this->announce('actionObject'.$event.$when, ['object' => $object]);
                    $this->announce('actionObjectStockAvailable'.$event.$when, ['object' => $object]);
                }
            } elseif (! $row['sent']) {
                continue;
            }
            $params = ['id_product' => $row['product'], 'id_product_attribute' => $row['variant'], 'quantity' => $row['quantity']];
            if ($this->hookShop) {
                $params += ['delta_quantity' => $row['delta'], 'id_shop' => $this->scope->shopId];
            }
            $this->announce('actionUpdateQuantity', $params);
        }
    }

    /**
     * A stock row's quantity, by primary key: the quantity alone, as setQuantity() writes it (`safe`, and
     * a product's total in both modes), or with the physical quantity (`fast`).
     *
     * @param  array{id: int, quantity: int, out_of_stock: int}  $stock
     */
    private function quantity(int $productId, int $variantId, int $quantity, array $stock, bool $sent): void
    {
        $physical = $this->mode === 'fast' && $sent ? ', physical_quantity = reserved_quantity + '.$quantity : '';
        $this->db->bulk('UPDATE '.$this->db->table('stock_available').' SET quantity = '.$quantity.$physical.' WHERE id_stock_available = '.$stock['id']);
        $this->stock[$productId.':'.$variantId]['quantity'] = $quantity;
        if ($variantId !== 0 && ($quantity <= 0) !== ($stock['quantity'] <= 0)) {
            $this->colors[$productId] = true;
        }
        $this->written[] = ['id' => $stock['id'], 'product' => $productId, 'variant' => $variantId, 'quantity' => $quantity, 'delta' => $quantity - $stock['quantity'], 'created' => false, 'sent' => $sent];
    }

    /**
     * A stock row the shop does not have, as setQuantity() adds it: in the paired shop (its group's
     * when the group shares stock), with the out-of-stock policy of the product's row unless one is
     * sent. By the table's unique key: a row another session created since the chunk looked is
     * updated instead, under the lock this statement takes.
     */
    private function create(int $productId, int $variantId, int $quantity, ?int $policy, bool $sent): void
    {
        $inherited = $this->stock[$productId.':0']['out_of_stock'] ?? 0;
        [$shop, $group] = $this->scope->shareStock ? [0, $this->scope->shopGroupId] : [$this->scope->shopId, 0];
        $fast = $this->mode === 'fast' && $sent;
        $this->db->bulk('INSERT INTO '.$this->db->table('stock_available').' (id_product, id_product_attribute, id_shop, id_shop_group, quantity, physical_quantity, out_of_stock)'
            .' VALUES ('.$productId.', '.$variantId.', '.$shop.', '.$group.', '.$quantity.', '.($fast ? $quantity : 0).', '.($policy ?? $inherited).')'
            .' ON DUPLICATE KEY UPDATE quantity = '.$quantity.($fast ? ', physical_quantity = reserved_quantity + '.$quantity : '')
            .($policy !== null ? ', out_of_stock = '.$policy : ''));
        $created = $this->db->rows('SELECT sa.id_stock_available, sa.out_of_stock FROM '.$this->db->table('stock_available').' sa WHERE sa.id_product = '.$productId
            .' AND sa.id_product_attribute = '.$variantId.' AND sa.id_shop = '.$shop.' AND sa.id_shop_group = '.$group);
        if (count($created) !== 1) {
            throw new \RuntimeException('A stock row the chunk created cannot be read back.');
        }
        $id = (int) $created[0]['id_stock_available'];
        if ($variantId !== 0) {
            $this->colors[$productId] = true;
        }
        $this->stock[$productId.':'.$variantId] = ['id' => $id, 'quantity' => $quantity, 'out_of_stock' => (int) $created[0]['out_of_stock']];
        $this->written[] = ['id' => $id, 'product' => $productId, 'variant' => $variantId, 'quantity' => $quantity, 'delta' => null, 'created' => true, 'sent' => $sent];
    }

    /**
     * The out-of-stock policy as StockAvailable::setProductOutOfStock() sets it (every stock row of the
     * product for variant "0", no hook), by primary key on the rows the chunk holds.
     */
    private function outOfStock(int $productId, int $variantId, int $policy): void
    {
        $ids = [];
        foreach ($this->stock as $key => $stock) {
            if ($variantId === 0 ? (int) $key === $productId : $key === $productId.':'.$variantId) {
                $ids[] = $stock['id'];
                $this->stock[$key]['out_of_stock'] = $policy;
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
