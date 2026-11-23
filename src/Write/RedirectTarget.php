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

use Configuration;

/**
 * Where a redirect may lead: to a page the shop shows. A target that is gone or inactive, one that is
 * redirected itself (a chain) and one whose redirects lead back (a loop, also one that would only close
 * when somebody deactivates an entity) are refused, each with its own code.
 */
final class RedirectTarget
{
    /** More redirects in a row than any shop has: the search ends. */
    private const STEPS = 50;

    /** @var Shop */
    private $shop;

    public function __construct(Shop $shop)
    {
        $this->shop = $shop;
    }

    /**
     * @param  int  $id  the entity that is redirected, 0 for one that is yet to be created
     *
     * @throws ItemError target_not_found, redirect_loop, redirect_chain, target_inactive
     */
    public function check(string $entity, int $id, string $target, int $targetId, string $field): void
    {
        $active = $targetId > 0 && $this->shop->holds($target, $targetId) ? $this->shop->pages->active($target, $targetId) : null;
        if ($active === null) {
            throw ItemError::missing($field, 'target_not_found');
        }
        if ($this->leadsTo([$target, $targetId], [$entity, $id])) {
            throw new ItemError('redirect_loop', 'The redirects would lead back', $field);
        }
        if (! $active) {
            throw $this->next($target, $targetId) === null
                ? new ItemError('target_inactive', 'The target is not active', $field)
                : new ItemError('redirect_chain', 'The target is redirected itself', $field);
        }
    }

    /**
     * Whether the redirects kept for $from lead to $to, whether they are in force now (the entity is
     * inactive) or not.
     *
     * @param  array{0: string, 1: int}  $from
     * @param  array{0: string, 1: int}  $to
     */
    public function leadsTo(array $from, array $to): bool
    {
        $at = $from;
        for ($step = 0; $at !== null && $step < self::STEPS; $step++) {
            if ($at === $to) {
                return true;
            }
            $at = $this->next($at[0], $at[1]);
        }

        return false;
    }

    /**
     * Where the entity is redirected while it is inactive.
     *
     * @return array{0: string, 1: int}|null
     */
    private function next(string $entity, int $id): ?array
    {
        if ($entity === 'category') {
            foreach (Configuration::get('PS_REWRITING_SETTINGS') ? $this->shop->pathsOf('category', $id) : [] as $path) {
                $row = $this->shop->redirects->find($path);
                if ($row !== null && $row['target_type'] !== '') {
                    return [$row['target_type'], $row['target_id']];
                }
            }

            return null;
        }
        $rows = $this->shop->db->rows('SELECT redirect_type, id_type_redirected, id_category_default FROM '.$this->shop->db->table('product_shop')
            .' WHERE id_product = '.$id.' AND id_shop = '.$this->shop->scope->shopId);
        $type = (string) ($rows[0]['redirect_type'] ?? '');
        if ($type === 'default') {
            $type = (string) Configuration::get('PS_PRODUCT_REDIRECTION_DEFAULT');
        }
        if (preg_match('/-(product|category)\z/', $type, $m) !== 1) {
            return null;
        }
        $targetId = (int) $rows[0]['id_type_redirected'];
        // A category redirect without a target is to the product's default category.
        $targetId = $targetId === 0 && $m[1] === 'category' ? (int) $rows[0]['id_category_default'] : $targetId;

        return $targetId === 0 ? null : [$m[1], $targetId];
    }
}
