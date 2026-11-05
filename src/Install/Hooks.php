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

use Kronixon\Connect\Compat\CompatInterface;

/**
 * Hooks registered at install (Spec §7.2 Phase-0 events, §7.3 flush on visit, §6.26 routes).
 * Their handlers are empty until M5 (outbox) and M3 (moduleRoutes for kx_redirect). Order events,
 * specific prices and the blog's displayHeader are Phase 2 and are not registered.
 */
final class Hooks
{
    public const COMMON = [
        'actionProductAdd',
        'actionProductUpdate',
        'actionProductDelete',
        'actionObjectProductUpdateAfter',
        'actionUpdateQuantity',
        'actionObjectCombinationAddAfter',
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
}
