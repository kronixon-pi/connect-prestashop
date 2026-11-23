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

use Kronixon\Connect\Catalog\Decimal;
use Kronixon\Connect\Catalog\Scope;
use Kronixon\Connect\Support\Log;
use Product;

/**
 * `POST prices/bulk` (module/prices-bulk.json, Spec §6.21) without Product::save(): net prices as
 * PrestaShop stores them. A product row (`variant_id` "0") sets `price`, `wholesale_price`,
 * `tax_rules_group_id` and `unit_price`; a combination row sets its `price` **impact** and its
 * `wholesale_price` (what `GET catalog/prices` reads as `price_impact`). `expected_price` guards
 * `price` only. A unit price follows PrestaShop's rule of the version: 8.0+ stores it and derives
 * the ratio, 1.7.8 stores only the ratio (`price / unit_price`), so a new price there changes the
 * unit price as the back office does.
 *
 * Hooks: `actionKxBulkPricesUpdated` with the changed product ids once a request; `fire_hooks: true`
 * adds `actionProductUpdate` per changed product. ps_facetedsearch's price index follows `reindex`:
 * `inline` indexes the changed products after each chunk, `deferred` (the default) lists them in
 * `facets_pending` for `POST maintenance/facets`, `none` leaves it.
 */
final class PriceBulk extends Bulk
{
    /** @var Facets */
    private $facets;

    /** @var callable(): void PrestaShop's price caches */
    private $flush;

    /** @var bool */
    private $flushCache = true;

    /** @var bool */
    private $fireHooks = false;

    /** @var bool */
    private $touch = true;

    /** @var string */
    private $reindex = 'deferred';

    /** @var array<int, array<string, string>> product id => product_shop row */
    private $products = [];

    /** @var array<int, array<string, string>> combination id => product_attribute_shop row */
    private $variants = [];

    /** @var array<int, true>|null tax rules groups of the shop */
    private $taxGroups;

    /**
     * @param  callable(string, array<string, mixed>): void  $hook
     * @param  callable(): void  $flush
     */
    public function __construct(Database $db, Scope $scope, PendingWrites $pending, Log $log, bool $defaultShop, callable $hook, Facets $facets, callable $flush)
    {
        parent::__construct($db, $scope, $pending, $log, $defaultShop, $hook);
        $this->facets = $facets;
        $this->flush = $flush;
    }

    protected function options(Item $options): void
    {
        $this->flushCache = $options->bool('flush_cache') ?? true;
        $this->fireHooks = $options->bool('fire_hooks') ?? false;
        $this->touch = $options->bool('touch_date_upd') ?? true;
        $this->reindex = $options->choice('reindex', ['deferred', 'inline', 'none']) ?? 'deferred';
    }

    protected function parse(Item $row): array
    {
        $wanted = [
            'price' => $row->money('price'),
            'wholesale_price' => $row->money('wholesale_price'),
            'unit_price' => $row->money('unit_price'),
            'tax' => $row->int('tax_rules_group_id'),
            'expected' => $row->money('expected_price'),
        ];
        foreach (['wholesale_price', 'unit_price'] as $member) {
            if ($wanted[$member] !== null && $wanted[$member][0] === '-') {
                throw ItemError::invalid($row->at($member), 'is negative');
            }
        }

        return $wanted;
    }

    protected function load(array $rows): void
    {
        $products = [];
        $variants = [];
        foreach ($rows as $row) {
            $products[] = (int) $row['product'];
            if ($row['variant'] !== 0) {
                $variants[] = (int) $row['variant'];
            }
        }
        $this->products = [];
        foreach ($this->db->rows('SELECT id_product, price, wholesale_price, id_tax_rules_group, unit_price_ratio'.($this->scope->unitPriceColumn ? ', unit_price' : '')
            .' FROM '.$this->db->table('product_shop').' WHERE id_shop = '.$this->scope->shopId.' AND id_product IN ('.self::in($products).')') as $product) {
            $this->products[(int) $product['id_product']] = $product;
        }
        $this->variants = [];
        if ($variants !== []) {
            foreach ($this->db->rows('SELECT pa.id_product_attribute, pa.id_product, pas.price, pas.wholesale_price FROM '.$this->db->table('product_attribute').' pa INNER JOIN '
                .$this->db->table('product_attribute_shop').' pas ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = '.$this->scope->shopId
                .' WHERE pa.id_product_attribute IN ('.self::in($variants).')') as $variant) {
                $this->variants[(int) $variant['id_product_attribute']] = $variant;
            }
        }
    }

    protected function row(array $row): array
    {
        $productId = (int) $row['product'];
        $variantId = (int) $row['variant'];
        $this->product($this->products, $productId);
        /** @var Warnings $warnings */
        $warnings = $row['warnings'];
        if ($variantId !== 0) {
            return $this->combination($row, $productId, $variantId, $warnings);
        }
        $current = $this->products[$productId];
        if ($row['price'] !== null && $row['price'][0] === '-') {
            throw ItemError::invalid('price', 'is negative');
        }
        $set = [];
        $price = Decimal::money($current['price']);
        if ($row['price'] !== null && Decimal::money($row['price']) !== $price) {
            if ($row['expected'] !== null && Decimal::money($row['expected']) !== $price) {
                return ['status' => 'conflict', 'code' => 'price_changed', 'previous_price' => $price, 'current_price' => $price];
            }
            $set['price'] = $this->quote($row['price']);
        }
        if ($row['wholesale_price'] !== null && Decimal::money($row['wholesale_price']) !== Decimal::money($current['wholesale_price'])) {
            $set['wholesale_price'] = $this->quote($row['wholesale_price']);
        }
        if ($row['tax'] !== null && $row['tax'] !== (int) $current['id_tax_rules_group']) {
            if ($row['tax'] !== 0 && ! isset($this->taxGroups()[$row['tax']])) {
                throw ItemError::missing('tax_rules_group_id', 'tax_rules_group_not_found');
            }
            $set['id_tax_rules_group'] = (string) $row['tax'];
        }
        $set += $this->unitPrice($current, $row['price'] ?? $current['price'], $row['unit_price']);
        if ($set === []) {
            return ['status' => 'ok', 'action' => 'unchanged', 'previous_price' => $price];
        }
        $this->write('product', 'id_product', $productId, $set, $productId);

        return ['status' => 'ok', 'action' => 'updated', 'previous_price' => $price];
    }

    protected function committed(array $products): void
    {
        foreach ($products as $id) {
            if ($this->fireHooks) {
                $this->announce('actionProductUpdate', ['id_product' => $id, 'product' => new Product($id)]);
            }
            if ($this->reindex === 'inline' && $this->facets->available()) {
                $this->facets->indexPrices($id);
            }
        }
    }

    protected function finish(): array
    {
        $ids = array_keys($this->changed);
        if ($ids === []) {
            return [];
        }
        if ($this->flushCache) {
            ($this->flush)();
        }
        $this->announce('actionKxBulkPricesUpdated', ['ids' => $ids]);

        return $this->reindex === 'deferred' && $this->facets->available() ? ['facets_pending' => array_map('strval', $ids)] : [];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function combination(array $row, int $productId, int $variantId, Warnings $warnings): array
    {
        $current = $this->variants[$variantId] ?? null;
        if ($current === null || (int) $current['id_product'] !== $productId) {
            throw ItemError::missing('variant_id');
        }
        if ($row['tax'] !== null || $row['unit_price'] !== null) {
            // A combination has the tax rules and the unit price of its product.
            $warnings->add('product_fields_ignored');
        }
        $impact = Decimal::money($current['price']);
        $set = [];
        if ($row['price'] !== null && Decimal::money($row['price']) !== $impact) {
            if ($row['expected'] !== null && Decimal::money($row['expected']) !== $impact) {
                return ['status' => 'conflict', 'code' => 'price_changed', 'previous_price' => $impact, 'current_price' => $impact];
            }
            $set['price'] = $this->quote($row['price']);
        }
        if ($row['wholesale_price'] !== null && Decimal::money($row['wholesale_price']) !== Decimal::money($current['wholesale_price'])) {
            $set['wholesale_price'] = $this->quote($row['wholesale_price']);
        }
        if ($set === []) {
            return ['status' => 'ok', 'action' => 'unchanged', 'previous_price' => $impact];
        }
        $this->write('product_attribute', 'id_product_attribute', $variantId, $set, $productId);

        return ['status' => 'ok', 'action' => 'updated', 'previous_price' => $impact];
    }

    /**
     * The unit price columns for a product of price $price (PrestaShop's rule of the version).
     *
     * @param  array<string, string>  $current
     * @return array<string, string>
     */
    private function unitPrice(array $current, string $price, ?string $unitPrice): array
    {
        if ($this->scope->unitPriceColumn) {
            $unit = $unitPrice ?? $current['unit_price'];
            $ratio = Decimal::money($unit) === '0.000000' ? '0.000000' : Decimal::divide($price, $unit);
            $set = [];
            if (Decimal::money($unit) !== Decimal::money($current['unit_price'])) {
                $set['unit_price'] = $this->quote($unit);
            }
            if ($ratio !== Decimal::money($current['unit_price_ratio']) && ($set !== [] || Decimal::money($price) !== Decimal::money($current['price']))) {
                $set['unit_price_ratio'] = $this->quote($ratio);
            }

            return $set;
        }
        if ($unitPrice === null) {
            return [];
        }
        $ratio = Decimal::money($unitPrice) === '0.000000' ? '0.000000' : Decimal::divide($price, $unitPrice);

        return $ratio === Decimal::money($current['unit_price_ratio']) ? [] : ['unit_price_ratio' => $this->quote($ratio)];
    }

    /**
     * @param  array<string, string>  $set
     */
    private function write(string $table, string $primary, int $id, array $set, int $productId): void
    {
        $this->update($table, $primary, $id, $set);
        if ($this->touch) {
            $this->update('product', 'id_product', $productId, ['date_upd' => 'NOW()']);
        }
    }

    private function quote(string $amount): string
    {
        return $this->db->quote(Decimal::money($amount));
    }

    /**
     * @return array<int, true>
     */
    private function taxGroups(): array
    {
        if ($this->taxGroups === null) {
            $this->taxGroups = [];
            foreach ($this->db->rows('SELECT id_tax_rules_group FROM '.$this->db->table('tax_rules_group').' WHERE deleted = 0') as $group) {
                $this->taxGroups[(int) $group['id_tax_rules_group']] = true;
            }
        }

        return $this->taxGroups;
    }
}
