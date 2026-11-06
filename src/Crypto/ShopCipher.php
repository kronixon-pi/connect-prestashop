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

use PhpEncryption;
use RuntimeException;
use Throwable;

/**
 * PrestaShop's own `PhpEncryption` (defuse/php-encryption, the same API on 1.7.8–9.x) keyed with
 * `_NEW_COOKIE_KEY_`, which lives in the shop's parameters file, not in the database: a database
 * dump or backup alone does not reveal the secrets (README §11).
 */
final class ShopCipher implements Cipher
{
    public function encrypt(string $plaintext): string
    {
        $ciphertext = $this->engine()->encrypt($plaintext);
        if (! is_string($ciphertext) || $ciphertext === '') {
            throw new RuntimeException('PhpEncryption could not encrypt.');
        }

        return $ciphertext;
    }

    public function decrypt(string $ciphertext): ?string
    {
        try {
            $plaintext = $this->engine()->decrypt($ciphertext);
        } catch (Throwable $e) {
            return null;
        }

        return is_string($plaintext) && $plaintext !== '' ? $plaintext : null;
    }

    private function engine(): PhpEncryption
    {
        if (! defined('_NEW_COOKIE_KEY_')) {
            throw new RuntimeException('The shop has no _NEW_COOKIE_KEY_.');
        }

        return new PhpEncryption(constant('_NEW_COOKIE_KEY_'));
    }
}
