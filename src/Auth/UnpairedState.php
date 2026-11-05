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
 * The only pairing state of M0: pairing arrives in M1, so every /v1 request answers 403
 * store_unpaired. It cannot sign that answer — there is no shared secret yet.
 */
final class UnpairedState implements PairingState
{
    public function isPaired(): bool
    {
        return false;
    }

    public function storeId(): ?string
    {
        return null;
    }

    public function keys(): ?KeyRing
    {
        return null;
    }
}
