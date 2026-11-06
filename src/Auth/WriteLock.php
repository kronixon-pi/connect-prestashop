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
 * One write batch at a time per shop (README §7 `409 busy`): every POST/DELETE endpoint runs under
 * it; a second writer is refused at once instead of waiting.
 */
interface WriteLock
{
    /** Takes the lock without waiting; false when another request holds it. */
    public function acquire(): bool;

    public function release(): void;
}
