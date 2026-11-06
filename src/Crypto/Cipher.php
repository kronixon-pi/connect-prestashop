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

/**
 * Encryption of the module's secrets at rest (the shared secrets and the X25519 key pair).
 */
interface Cipher
{
    public function encrypt(string $plaintext): string;

    /** The plaintext, or null when the value cannot be opened (another key, tampered, empty). */
    public function decrypt(string $ciphertext): ?string;
}
