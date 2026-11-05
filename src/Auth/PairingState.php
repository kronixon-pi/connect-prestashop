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

namespace Kronixon\Connect\Auth;

/**
 * Whether this shop holds a pairing with the platform, and its keys. M1 implements the secret
 * store (PhpEncryption with _NEW_COOKIE_KEY_, Spec §3.3).
 */
interface PairingState
{
    public function isPaired(): bool;

    public function storeId(): ?string;

    /** The key ring of the pairing, null when unpaired. */
    public function keys(): ?KeyRing;
}
