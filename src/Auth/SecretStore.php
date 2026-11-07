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

use Kronixon\Connect\Crypto\Cipher;
use Kronixon\Connect\Crypto\SealedBox;
use Kronixon\Connect\Support\Settings;
use RuntimeException;

/**
 * The pairing as the module holds it: the store id, the shared secret `cur`, the previous secret
 * `prev` with its expiry (README §4.5) and the module's X25519 key pair. Secrets and the key pair
 * are stored only encrypted (Cipher); none of them is ever returned to a caller other than the
 * signer and the verifier, logged or rendered.
 *
 * Writes are ordered so that an interrupted write leaves a usable state: the store id is written
 * last when pairing (isPaired() needs it), `cur` last when rotating (until then the old secret
 * still verifies, and the platform switches only after the module's `200`).
 */
final class SecretStore implements PairingState
{
    public const STORE_ID = 'KX_STORE_ID';

    public const SECRET = 'KX_SECRET';

    public const SECRET_PREV = 'KX_SECRET_PREV';

    public const SECRET_PREV_UNTIL = 'KX_SECRET_PREV_UNTIL';

    public const KEY_PAIR = 'KX_PRIVATE_KEY';

    public const PUBLIC_KEY = 'KX_PUBLIC_KEY';

    public const API_BASE = 'KX_API_BASE';

    public const PAIRED_AT = 'KX_PAIRED_AT';

    public const PLATFORM_KEY = 'KX_PLATFORM_KEY';

    public const TENANT_NAME = 'KX_TENANT_NAME';

    /** The settings page's last pairing outcome (Pairing::STATE). */
    public const PAIR_STATE = 'KX_PAIR_STATE';

    /** The time of the last verified platform request (Endpoints::LAST_REQUEST_AT). */
    public const LAST_REQUEST_AT = 'KX_LAST_REQUEST_AT';

    /** `kxs_` + 32 base62 characters (README §4.1). */
    public const SECRET_PATTERN = '/\Akxs_[0-9A-Za-z]{32}\z/';

    /**
     * The keys that describe a pairing; forget() deletes them, besides STORE_ID, whoever ends the
     * pairing (the settings page or the platform's `POST unpair`). What stays belongs to the
     * installation, not to a pairing: the key pair (KEY_PAIR, PUBLIC_KEY), the platform URL and its
     * HTTP switch, the instance id, the service account, the limits and the later-version settings.
     */
    public const PAIRING_KEYS = [self::SECRET, self::SECRET_PREV, self::SECRET_PREV_UNTIL, self::API_BASE, self::PAIRED_AT, self::PLATFORM_KEY, self::TENANT_NAME, self::PAIR_STATE, self::LAST_REQUEST_AT];

    /** @var Settings */
    private $settings;

    /** @var Cipher */
    private $cipher;

    public function __construct(Settings $settings, Cipher $cipher)
    {
        $this->settings = $settings;
        $this->cipher = $cipher;
    }

    public function isPaired(): bool
    {
        return $this->storeId() !== null && $this->keys() !== null;
    }

    public function storeId(): ?string
    {
        return $this->settings->get(self::STORE_ID);
    }

    public function keys(): ?KeyRing
    {
        $current = $this->secret(self::SECRET);
        if ($current === null) {
            return null;
        }
        $until = $this->settings->get(self::SECRET_PREV_UNTIL);

        return new KeyRing($current, $this->secret(self::SECRET_PREV), $until === null ? null : (int) $until);
    }

    /** The current secret for an outgoing message (README §4.5: always `cur`). */
    public function current(): ?string
    {
        return $this->secret(self::SECRET);
    }

    public function setting(string $key): ?string
    {
        return $this->settings->get($key);
    }

    /**
     * The base64 public key, creating the key pair when the module has none (or it cannot be opened
     * any more, e.g. after `_NEW_COOKIE_KEY_` changed).
     *
     * @throws RuntimeException without the sodium extension
     */
    public function ensureKeyPair(): string
    {
        if (! SealedBox::available()) {
            throw new RuntimeException('The PHP sodium extension is missing.');
        }
        $keyPair = $this->keyPair();
        if ($keyPair === null) {
            $keyPair = SealedBox::newKeyPair();
            if (! $this->settings->set(self::KEY_PAIR, $this->cipher->encrypt(base64_encode($keyPair)))
                || ! $this->settings->set(self::PUBLIC_KEY, SealedBox::publicKey($keyPair))) {
                throw new RuntimeException('The key pair could not be stored.');
            }
        }

        return SealedBox::publicKey($keyPair);
    }

    /** A secret sealed to this module's key pair, or null when it is not a valid one (README §4.1). */
    public function openSecret(string $sealedBase64): ?string
    {
        $keyPair = SealedBox::available() ? $this->keyPair() : null;
        $secret = $keyPair === null ? null : SealedBox::open($sealedBase64, $keyPair);

        return $secret !== null && preg_match(self::SECRET_PATTERN, $secret) === 1 ? $secret : null;
    }

    public function paired(string $storeId, string $secret, string $apiBase, string $platformKey, string $tenantName, int $now): bool
    {
        $this->forget();

        return $this->settings->set(self::SECRET, $this->cipher->encrypt($secret))
            && $this->settings->set(self::API_BASE, $apiBase)
            && $this->settings->set(self::PLATFORM_KEY, $platformKey)
            && ($tenantName === '' || $this->settings->set(self::TENANT_NAME, $tenantName))
            && $this->settings->set(self::PAIRED_AT, (string) $now)
            && $this->settings->set(self::STORE_ID, $storeId);
    }

    /**
     * $secret becomes `cur`. Verified with `cur` ($verifiedWith), the old `cur` becomes `prev` until
     * $prevUntil. Verified with `prev` (a retry whose first answer was lost: the platform never
     * switched and still signs with the secret that is `prev` here), `prev` and its expiry stay and
     * only `cur` is replaced, so the never-confirmed secret stops verifying. Repeating it with the
     * same secret changes nothing.
     */
    public function rotated(string $secret, int $prevUntil, string $verifiedWith = KeyRing::CURRENT): bool
    {
        $current = $this->current();
        if ($current === null) {
            return false;
        }
        if (hash_equals($current, $secret)) {
            return true;
        }
        if ($verifiedWith === KeyRing::PREVIOUS) {
            return $this->settings->set(self::SECRET, $this->cipher->encrypt($secret));
        }

        return $this->settings->set(self::SECRET_PREV, $this->cipher->encrypt($current))
            && $this->settings->set(self::SECRET_PREV_UNTIL, (string) $prevUntil)
            && $this->settings->set(self::SECRET, $this->cipher->encrypt($secret));
    }

    /** Ends the pairing: every later request answers `403 store_unpaired`. The store id goes first. */
    public function forget(): bool
    {
        $ok = $this->settings->delete(self::STORE_ID);
        foreach (self::PAIRING_KEYS as $key) {
            $ok = $this->settings->delete($key) && $ok;
        }

        return $ok;
    }

    private function keyPair(): ?string
    {
        $stored = $this->secret(self::KEY_PAIR);
        $raw = $stored === null ? false : base64_decode($stored, true);

        return $raw !== false && strlen($raw) === 64 ? $raw : null;
    }

    private function secret(string $key): ?string
    {
        $stored = $this->settings->get($key);

        return $stored === null ? null : $this->cipher->decrypt($stored);
    }
}
