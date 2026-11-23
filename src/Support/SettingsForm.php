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
 * The actions of the settings page run only on a POST, with the submit name and the fields read from
 * the POST body. A GET never acts: on PrestaShop 9.x the legacy settings URL answers a 308 to its
 * Symfony route that adds a valid `_token` for the logged-in employee, whatever token the request
 * carried, so the admin token cannot guard a GET (a link clicked elsewhere). The 308 keeps the
 * method, and the back-office cookie is SameSite=Lax, so a POST from another site carries no session.
 */
final class SettingsForm
{
    public const PLATFORM_URL = 'submitKxPlatformUrl';

    public const PAIR = 'submitKxPair';

    public const UNPAIR = 'submitKxUnpair';

    public const ROTATE_CRON = 'submitKxRotateCron';

    public const PING = 'submitKxPing';

    public const ACTIONS = [self::PLATFORM_URL, self::PAIR, self::UNPAIR, self::ROTATE_CRON, self::PING];

    /**
     * The action this request asks for, or null.
     *
     * @param  array<string, mixed>  $server  $_SERVER
     * @param  array<string, mixed>  $post  $_POST
     */
    public static function action(array $server, array $post): ?string
    {
        if (($server['REQUEST_METHOD'] ?? '') !== 'POST') {
            return null;
        }
        foreach (self::ACTIONS as $name) {
            if (isset($post[$name])) {
                return $name;
            }
        }

        return null;
    }

    /**
     * A text field of the POST body ('' when it is missing or not a string).
     *
     * @param  array<string, mixed>  $post  $_POST
     */
    public static function field(array $post, string $name): string
    {
        $value = $post[$name] ?? '';

        return is_string($value) ? $value : '';
    }
}
