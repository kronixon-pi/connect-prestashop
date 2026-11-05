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

namespace Kronixon\Connect\Compat;

/**
 * Picks the compatibility profile for a PrestaShop version (`_PS_VERSION_`).
 * Supported: 1.7.8.0 ≤ version < 10.0.0 (Spec §14 #4: no 1.7.6/1.7.7).
 */
final class Version
{
    public const MIN = '1.7.8.0';

    public const MAX_EXCLUSIVE = '10.0.0';

    private function __construct()
    {
    }

    /**
     * @throws UnsupportedVersion
     */
    public static function select(string $psVersion): CompatInterface
    {
        if (preg_match('/\A[0-9]+(?:\.[0-9]+){1,3}\z/', $psVersion) !== 1) {
            throw new UnsupportedVersion('Unrecognised PrestaShop version.');
        }
        if (version_compare($psVersion, self::MIN, '<') || version_compare($psVersion, self::MAX_EXCLUSIVE, '>=')) {
            throw new UnsupportedVersion('Kronixon Connect supports PrestaShop 1.7.8 to 9.x.');
        }
        if (version_compare($psVersion, '8.0.0', '<')) {
            return new Ps178();
        }
        if (version_compare($psVersion, '8.1.0', '<')) {
            return new Ps80();
        }
        if (version_compare($psVersion, '9.0.0', '<')) {
            return new Ps81();
        }

        return new Ps9();
    }

    public static function isSupported(string $psVersion): bool
    {
        try {
            self::select($psVersion);

            return true;
        } catch (UnsupportedVersion $e) {
            return false;
        }
    }
}
