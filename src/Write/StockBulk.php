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
 * its total quantity is 0 and shows again only those it hid (`kx_entity_map.flags` bit 1).
 * `available_now`/`available_later` are texts of the product (POST products, `content`): ignored here
 * with a warning.
 */
final class StockBulk extends Bulk
{
    /** `kx_entity_map.flags`: the product was hidden by `hide_when_zero`. */
    private const HIDDEN_BY_KX = 1;

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

    /** @var array<string, array{quantity: int, out_of_stock: int}> `product:variant` => stock row */
    private $stock = [];

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
        // First, before any plain read starts the transaction's snapshot: every stock row of the chunk's
        // products, their totals included, locked by id. An order that commits while this waits is in
        // what is read (and in what StockAvailable::setQuantity() reads after it); one that comes later
        // waits for the commit. An order's statements run without a transaction, one row each, so it
        // never holds a row this waits for while waiting for one this holds.
        $this->stock = [];
        foreach ($this->db->lockRows('SELECT sa.id_product, sa.id_product_attribute, sa.quantity, sa.out_of_stock FROM '.$this->db->table('stock_available').' sa WHERE '
            .$this->scope->stockWhere('sa').' AND sa.id_product IN ('.$ids.') ORDER BY sa.id_stock_available', $waitSeconds) as $stock) {
            $this->stock[(int) $stock['id_product'].':'.(int) $stock['id_product_attribute']] = ['quantity' => (int) $stock['quantity'], 'out_of_stock' => (int) $stock['out_of_stock']];
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
        $changed = false;
        if ($quantity !== $current || $stock === null) {
            $this->quantity($productId, $variantId, $quantity, $stock !== null);
            $changed = true;
        }
        if ($policy !== null && ($stock === null || $policy !== $stock['out_of_stock'])) {
            ($this->setOutOfStock)($productId, $policy, $this->scope->shopId, $variantId);
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
                foreach (array_keys($this->totals) as $id) {
                    $this->db->bulk('UPDATE '.$this->db->table('stock_available').' SET quantity = (SELECT q FROM (SELECT COALESCE(SUM(c.quantity), 0) AS q FROM '
                        .$this->db->table('stock_available').' c WHERE '.$this->scope->stockWhere('c').' AND c.id_product = '.$id.' AND c.id_product_attribute <> 0) t)'
                        .' WHERE '.str_replace('sa.', '', $this->scope->stockWhere('sa')).' AND id_product = '.$id.' AND id_product_attribute = 0');
                }
                if ($this->visibility !== 'none') {
                    $this->visibility($touched);
                }
                $this->db->commit();
            } catch (\Throwable $error) {
                $this->db->rollback();
                $this->log->warning('write', 'Totals or visibility after a stock chunk failed', ['exception' => get_class($error)]);
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

    private function quantity(int $productId, int $variantId, int $quantity, bool $exists): void
    {
        if ($this->mode === 'safe' || ! $exists) {
            ($this->setQuantity)($productId, $variantId, $quantity, $this->scope->shopId);

            return;
        }
        $this->db->bulk('UPDATE '.$this->db->table('stock_available').' SET quantity = '.$quantity.', physical_quantity = reserved_quantity + '.$quantity
            .' WHERE '.str_replace('sa.', '', $this->scope->stockWhere('sa')).' AND id_product = '.$productId.' AND id_product_attribute = '.$variantId);
        if ($variantId !== 0) {
            $this->totals[$productId] = true;
        }
        if ($this->fireHooks) {
            $this->announce('actionUpdateQuantity', ['id_product' => $productId, 'id_product_attribute' => $variantId, 'quantity' => $quantity, 'id_shop' => $this->scope->shopId]);
        }
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
                $this->show($id, 'both', $ours ? (int) $product['flags'] & ~self::HIDDEN_BY_KX : null, 'visibility_restored');
            } elseif ($total <= 0 && ! $hidden && $this->visibility === 'hide_when_zero') {
                if ($product['flags'] === null) {
                    $this->warn($id, 'not_hidden_unlinked');
                } else {
                    $this->show($id, 'none', $product['flags'] | self::HIDDEN_BY_KX, 'visibility_hidden');
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
