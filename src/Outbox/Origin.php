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

namespace Kronixon\Connect\Outbox;

/**
 * Whose request this PHP process serves. A change made while the platform's own request runs (the API
 * front controller: its writes, PrestaShop's hooks after them, the clean-up of a dead write) is not an
 * event: the platform knows it from the answer (README §8, `origin: kronixon` is suppressed). The flag
 * lives for the request; nothing else sets it.
 */
final class Origin
{
    /** @var bool */
    private static $platform = false;

    private function __construct()
    {
    }

    public static function platformRequest(): void
    {
        self::$platform = true;
    }

    public static function isPlatform(): bool
    {
        return self::$platform;
    }

    /** Tests only. */
    public static function reset(): void
    {
        self::$platform = false;
    }
}
