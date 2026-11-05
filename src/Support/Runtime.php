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

namespace Kronixon\Connect\Support;

/**
 * PHP runtime settings of an API request: errors never displayed, time limit raised when the host
 * allows it, the body read with a hard cap.
 */
final class Runtime
{
    private function __construct()
    {
    }

    public static function prepareApiRequest(int $timeLimit): void
    {
        ini_set('display_errors', '0');
        if (self::canCall('set_time_limit')) {
            set_time_limit($timeLimit);
        }
        if (self::canCall('ignore_user_abort')) {
            ignore_user_abort(true);
        }
    }

    /** Reads at most $max + 1 bytes of php://input, so an oversized body is detectable without reading it all. */
    public static function readBody(int $max): string
    {
        $body = file_get_contents('php://input', false, null, 0, $max + 1);

        return $body === false ? '' : $body;
    }

    public static function hostCompressesOutput(): bool
    {
        $value = strtolower((string) ini_get('zlib.output_compression'));

        return $value !== '' && $value !== '0' && $value !== 'off';
    }

    public static function canCall(string $function): bool
    {
        if (! function_exists($function)) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array($function, $disabled, true);
    }
}
