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

namespace Kronixon\Connect\Install;

use Hook;
use Kronixon\Connect\Compat\CompatInterface;
use Module;
use Shop;

/**
 * Hooks registered at install (Spec §7.2 Phase-0 events, §7.3 flush on visit, §6.26 routes). ADDED marks
 * what a write creates (PendingWrites), the change hooks record store events (Outbox), moduleRoutes serves
 * kx_redirect. Order events, specific prices and the blog's displayHeader are Phase 2 and are not
 * registered.
 */
final class Hooks
{
    /** The first hook PrestaShop runs once an object's rows are stored. */
    public const ADDED = 'actionObjectAddAfter';

    /**
     * Hooks where the module runs before every other module: on 1.7.8 a module that throws stops the
     * ones after it, and a change must be recorded even then.
     */
    public const FIRST = [
        self::ADDED,
        'actionProductAdd',
        'actionProductUpdate',
        'actionProductDelete',
        'actionObjectProductUpdateBefore',
        'actionObjectProductUpdateAfter',
        'actionUpdateQuantity',
        'actionObjectCombinationAddAfter',
        'actionObjectCombinationUpdateBefore',
        'actionObjectCombinationUpdateAfter',
        'actionObjectCombinationDeleteAfter',
        'actionObjectCategoryAddAfter',
        'actionObjectCategoryUpdateAfter',
        'actionObjectCategoryDeleteAfter',
        'actionObjectImageAddAfter',
        'actionObjectImageDeleteAfter',
        'actionObjectManufacturerUpdateAfter',
        'actionObjectFeatureValueAddAfter',
        'actionObjectAttributeAddAfter',
        'actionObjectProductAttributeAddAfter',
    ];

    public const COMMON = [
        self::ADDED,
        'actionProductAdd',
        'actionProductUpdate',
        'actionProductDelete',
        'actionObjectProductUpdateBefore',
        'actionObjectProductUpdateAfter',
        'actionUpdateQuantity',
        'actionObjectCombinationAddAfter',
        'actionObjectCombinationUpdateBefore',
        'actionObjectCombinationUpdateAfter',
        'actionObjectCombinationDeleteAfter',
        'actionObjectCategoryAddAfter',
        'actionObjectCategoryUpdateAfter',
        'actionObjectCategoryDeleteAfter',
        'actionObjectImageAddAfter',
        'actionObjectImageDeleteAfter',
        'actionObjectManufacturerUpdateAfter',
        'actionObjectFeatureValueAddAfter',
        'actionFrontControllerSetMedia',
        'actionAdminControllerSetMedia',
        'moduleRoutes',
    ];

    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function forCompat(CompatInterface $compat): array
    {
        return array_values(array_unique(array_merge(self::COMMON, $compat->versionHooks())));
    }

    /**
     * Moves the module before the others of the FIRST hooks it is registered on, in every shop, active
     * or not: the paired shop may be any of them. updatePosition() works on the shops of the context and
     * stops at the first where the module is already first, so it runs once per shop, and the context is
     * put back afterwards.
     */
    public static function stayFirst(Module $module): void
    {
        $hooks = [];
        foreach (self::FIRST as $name) {
            $id = (int) Hook::getIdByName($name);
            if ($id > 0 && $module->isRegisteredInHook($name)) {
                $hooks[] = $id;
            }
        }
        $context = Shop::getContext();
        $shopId = Shop::getContextShopID(true);
        $groupId = Shop::getContextShopGroupID(true);
        try {
            foreach (Shop::getShops(false, null, true) as $id) {
                Shop::setContext(Shop::CONTEXT_SHOP, (int) $id);
                foreach ($hooks as $hook) {
                    $module->updatePosition($hook, false, 1);
                }
            }
        } finally {
            if ($context === Shop::CONTEXT_SHOP && $shopId !== null) {
                Shop::setContext(Shop::CONTEXT_SHOP, (int) $shopId);
            } elseif ($context === Shop::CONTEXT_GROUP && $groupId !== null) {
                Shop::setContext(Shop::CONTEXT_GROUP, (int) $groupId);
            } else {
                Shop::setContext(Shop::CONTEXT_ALL);
            }
        }
    }
}
