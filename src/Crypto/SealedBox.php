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

namespace Kronixon\Connect\Crypto;

use Throwable;

/**
 * The module's X25519 key pair and `crypto_box_seal_open` (README §5): the platform seals each
 * shared secret to the module's public key, so the secret never crosses the wire in clear.
 * Needs PHP's sodium extension (bundled with PHP since 7.2); without it the module installs but
 * cannot pair, and the settings page says so.
 */
final class SealedBox
{
    private function __construct()
    {
    }

    public static function available(): bool
    {
        return function_exists('sodium_crypto_box_keypair')
            && function_exists('sodium_crypto_box_seal_open')
            && function_exists('sodium_crypto_box_publickey');
    }

    /** A new key pair: the 64 raw bytes of `sodium_crypto_box_keypair()` (secret key, public key). */
    public static function newKeyPair(): string
    {
        return sodium_crypto_box_keypair();
    }

    /** base64 of the 32-byte public key (platform/pair.json `public_key`). */
    public static function publicKey(string $keyPair): string
    {
        return base64_encode(sodium_crypto_box_publickey($keyPair));
    }

    /** Opens a base64 sealed box; null when it is not one or not sealed to this key pair. */
    public static function open(string $sealedBase64, string $keyPair): ?string
    {
        $sealed = base64_decode($sealedBase64, true);
        if ($sealed === false || $sealed === '' || strlen($keyPair) !== 64) {
            return null;
        }
        try {
            $plain = sodium_crypto_box_seal_open($sealed, $keyPair);
        } catch (Throwable $e) {
            return null;
        }

        return is_string($plain) ? $plain : null;
    }
}
