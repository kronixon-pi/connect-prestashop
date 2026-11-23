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

namespace Kronixon\Connect\Update;

use Kronixon\Connect\Protocol;

/**
 * The Ed25519 public keys this build trusts for its own updates (plan D16, C14): those of its channel, written into
 * Protocol::RELEASE_KEYS by the release build — the key in use, and during a rotation the next one. Never a key from
 * the platform's answer or from the zip being checked. A development checkout has none and does not update itself.
 */
final class ReleaseKeys
{
    /** @var string */
    private $channel;

    /** @var array<string, string> key_id => 32 bytes */
    private $keys = [];

    /**
     * @param  string  $list  `key_id:base64[,key_id:base64]`; an entry that is not a 32-byte key is left out
     */
    public function __construct(string $channel, string $list)
    {
        $this->channel = $channel;
        foreach (explode(',', $list) as $entry) {
            $parts = explode(':', trim($entry), 2);
            $bytes = base64_decode($parts[1] ?? '', true);
            if (preg_match('/\A[a-z0-9][a-z0-9-]{0,31}\z/', $parts[0]) === 1 && is_string($bytes) && strlen($bytes) === 32 && ! isset($this->keys[$parts[0]])) {
                $this->keys[$parts[0]] = $bytes;
            }
        }
    }

    public static function builtIn(): self
    {
        return new self(Protocol::CHANNEL, Protocol::RELEASE_KEYS);
    }

    /** A release build: a channel and at least one key. */
    public function available(): bool
    {
        return in_array($this->channel, ['stable', 'staging'], true) && $this->keys !== [];
    }

    public function channel(): string
    {
        return $this->channel;
    }

    /**
     * The signature of $bytes verifies with the built-in key named $keyId.
     *
     * @throws UpdateError
     */
    public function verify(string $bytes, string $signature, string $keyId): void
    {
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            throw UpdateError::server('sodium_missing');
        }
        if (! isset($this->keys[$keyId])) {
            throw UpdateError::refused('unknown_key', $keyId);
        }
        if (strlen($signature) !== 64 || ! sodium_crypto_sign_verify_detached($signature, $bytes, $this->keys[$keyId])) {
            throw UpdateError::refused('bad_signature', $keyId);
        }
    }
}
