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

namespace Kronixon\Connect;

/**
 * PSR-4 loader for `Kronixon\Connect\` → `src/`. The module ships no runtime composer autoloader
 * (Spec §1 principle 7); bundled libraries arrive prefixed under their own namespace.
 */
final class Autoloader
{
    private const PREFIX = 'Kronixon\\Connect\\';

    /** @var bool */
    private static $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        spl_autoload_register([self::class, 'load']);
        self::$registered = true;
    }

    public static function load(string $class): void
    {
        if (strncmp($class, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            return;
        }
        $relative = substr($class, strlen(self::PREFIX));
        if ($relative === '' || strpos($relative, '..') !== false) {
            return;
        }
        $file = __DIR__.'/'.str_replace('\\', '/', $relative).'.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
}
