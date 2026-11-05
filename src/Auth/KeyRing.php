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

use InvalidArgumentException;

/**
 * The shared secrets of one pairing: `cur` always, `prev` for 24 h after a rotation (Spec §4.3).
 * A revocation is a key ring without `prev`. The HMAC key is the secret string itself (UTF-8 bytes).
 */
final class KeyRing
{
    public const CURRENT = 'cur';

    public const PREVIOUS = 'prev';

    /** @var string */
    private $current;

    /** @var string|null */
    private $previous;

    /** @var int|null */
    private $previousUntil;

    public function __construct(string $current, ?string $previous = null, ?int $previousUntil = null)
    {
        if ($current === '') {
            throw new InvalidArgumentException('The current secret is empty.');
        }
        $this->current = $current;
        $this->previous = $previous === '' ? null : $previous;
        $this->previousUntil = $previousUntil;
    }

    public function current(): string
    {
        return $this->current;
    }

    /**
     * The secrets a message may be signed with, in the order they are tried.
     * A given key id narrows it to that key; an unknown key id yields none.
     *
     * @return array<string, string> key id => secret
     */
    public function candidates(?string $keyId, int $now): array
    {
        $all = [self::CURRENT => $this->current];
        if ($this->previous !== null && $this->previousUntil !== null && $now <= $this->previousUntil) {
            $all[self::PREVIOUS] = $this->previous;
        }
        if ($keyId === null || $keyId === '') {
            return $all;
        }

        return isset($all[$keyId]) ? [$keyId => $all[$keyId]] : [];
    }
}
