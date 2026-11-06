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
 * Nonces of verified requests (README §4.4 step 7): each is accepted once within NONCE_TTL_S.
 * A nonce is stored only after its signature verified, so a forged request cannot burn one.
 * The same rows count the verified requests of the last minute for the rate limit (README §9).
 */
interface NonceStore
{
    /** True the first time $nonce is seen within the TTL; false for a replay. Expired rows are dropped. */
    public function claim(string $nonce, int $now): bool;

    /** Verified requests since $since (unix seconds), the one being handled included. */
    public function countSince(int $since): int;
}
