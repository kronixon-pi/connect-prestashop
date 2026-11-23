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

namespace Kronixon\Connect\Write;

/**
 * The `warnings` of the item being written: what was stored in another form than it was sent.
 */
final class Warnings
{
    /** @var array<string, true> */
    private $codes = [];

    public function add(string $code): void
    {
        $this->codes[$code] = true;
    }

    /**
     * The codes so far, each once; the next item starts without any.
     *
     * @return list<string>
     */
    public function take(): array
    {
        $codes = array_keys($this->codes);
        $this->codes = [];

        return $codes;
    }
}
