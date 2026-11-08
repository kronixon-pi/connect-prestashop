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

namespace Kronixon\Connect\Platform;

use Kronixon\Connect\Api\Endpoints;
use Kronixon\Connect\Api\Response;
use Kronixon\Connect\Auth\SecretStore;
use Kronixon\Connect\Protocol;
use Kronixon\Connect\Shop\ShopInfo;
use Kronixon\Connect\Support\Clock;
use Kronixon\Connect\Support\Log;
use Kronixon\Connect\Support\Settings;
use RuntimeException;

/**
 * Pairing from the module's settings page (README §5): the code typed by the administrator, the
 * unsigned `POST /v1/pair` with the module's public key, the sealed secret opened and stored, then
 * the signed `GET /v1/stores/{id}/ping`. The platform's reverse check (`GET capabilities`) follows
 * on its side. The outcome — or the platform's error code with `retry_after` — is kept for the page.
 */
final class Pairing
{
    public const PLATFORM_URL = 'KX_PLATFORM_URL';

    /** '1' allows a plain http:// platform URL (a development platform only, README §2). */
    public const ALLOW_HTTP = 'KX_PLATFORM_ALLOW_HTTP';

    public const STATE = SecretStore::PAIR_STATE;

    /** The platform's code alphabet (README §5): no 0/O, 1/I. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** @var SecretStore */
    private $secrets;

    /** @var PlatformClient */
    private $client;

    /** @var Endpoints */
    private $endpoints;

    /** @var ShopInfo */
    private $shop;

    /** @var Settings */
    private $settings;

    /** @var Clock */
    private $clock;

    /** @var Log */
    private $log;

    /** @var string */
    private $pinnedKey;

    public function __construct(SecretStore $secrets, PlatformClient $client, Endpoints $endpoints, ShopInfo $shop, Settings $settings, Clock $clock, Log $log, string $pinnedKey = Protocol::PINNED_PLATFORM_KEY)
    {
        $this->pinnedKey = $pinnedKey;
        $this->secrets = $secrets;
        $this->client = $client;
        $this->endpoints = $endpoints;
        $this->shop = $shop;
        $this->settings = $settings;
        $this->clock = $clock;
        $this->log = $log;
    }

    /**
     * `XXXX-XXXX`, normalised like the platform: case, spaces and any dash ignored; null when it
     * cannot be a code.
     */
    public static function normaliseCode(string $typed): ?string
    {
        $code = strtoupper((string) preg_replace('/[\s\x{00A0}\x{2010}-\x{2015}\x{2212}-]+/u', '', $typed));
        if (strlen($code) !== 8 || strspn($code, self::ALPHABET) !== 8) {
            return null;
        }

        return substr($code, 0, 4).'-'.substr($code, 4);
    }

    /** The base URL of the Connect service (scheme and host, no `/v1`), or null when it is not allowed. */
    public function normaliseUrl(string $typed): ?string
    {
        $url = rtrim(trim($typed), '/');
        if (substr($url, -3) === '/v1') {
            $url = substr($url, 0, -3);
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || preg_match('#\A[^\s]+\z#', $url) !== 1) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && ! ($scheme === 'http' && $this->settings->get(self::ALLOW_HTTP) === '1')) {
            return null;
        }

        return $url;
    }

    public function platformUrl(): string
    {
        return $this->settings->get(self::PLATFORM_URL) ?? Protocol::DEFAULT_PLATFORM_URL;
    }

    public function setPlatformUrl(string $typed): bool
    {
        $url = $this->normaliseUrl($typed);
        if ($url === null || ! $this->settings->set(self::PLATFORM_URL, $url)) {
            return false;
        }
        $this->settings->delete(self::STATE); // an error of the previous platform no longer applies

        return true;
    }

    /**
     * The last outcome for the settings page: `state` (`paired`, `error`) and, for an error, `code`
     * and optionally `reason`, `retry_until`, `min_module_version`, `errors`, `warning`.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $stored = json_decode((string) $this->settings->get(self::STATE), true);

        return is_array($stored) ? $stored : [];
    }

    /**
     * Pairs with the code; returns the outcome (also stored for the page). Never throws for a
     * refusal of the platform; secrets never appear in the outcome or the log.
     *
     * @return array<string, mixed>
     */
    public function pair(string $typedCode): array
    {
        $now = $this->now();
        $code = self::normaliseCode($typedCode);
        $base = $this->normaliseUrl($this->platformUrl());
        if ($code === null || $base === null) {
            return $this->remember(['state' => 'error', 'code' => $code === null ? 'pair_code_invalid' : 'platform_url_invalid']);
        }
        try {
            $publicKey = $this->secrets->ensureKeyPair();
        } catch (RuntimeException $e) {
            return $this->remember(['state' => 'error', 'code' => 'sodium_missing']);
        }

        try {
            $answer = $this->client->pair($base, $this->request($code, $publicKey));
            $storeId = $answer['store_id'] ?? null;
            $sealed = $answer['shared_secret_sealed'] ?? null;
            $platformKey = $answer['platform_public_key'] ?? null;
            $apiBase = $answer['api_base'] ?? null;
            if (! is_string($storeId) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $storeId) !== 1
                || ! is_string($sealed) || ! is_string($platformKey) || ! is_string($apiBase)) {
                throw new PlatformError(201, PlatformError::BAD_RESPONSE);
            }
            if ($this->pinnedKey !== '' && ! hash_equals($this->pinnedKey, $platformKey)) {
                throw new PlatformError(201, 'platform_key_mismatch');
            }
            $secret = $this->secrets->openSecret($sealed);
            if ($secret === null) {
                throw new PlatformError(201, PlatformError::BAD_RESPONSE);
            }
            $tenant = $answer['tenant_name'] ?? '';
            if (! $this->secrets->paired($storeId, $secret, $apiBase, $platformKey, is_string($tenant) ? $tenant : '', $now)) {
                throw new RuntimeException('The pairing could not be stored.');
            }
        } catch (PlatformError $error) {
            $this->log->warning('pairing', 'Pairing refused', ['error_code' => $error->errorCode(), 'status' => $error->status()]);

            return $this->remember(self::errorState($error, $now));
        }
        $this->log->info('pairing', 'Paired', ['store_id' => $storeId]);

        $outcome = ['state' => 'paired', 'store_id' => $storeId];
        $skew = $answer['server_time'] ?? null;
        if (is_int($skew) && abs($skew - $now) > Protocol::CLOCK_WINDOW_S) {
            $outcome['warning'] = 'clock_skew';
        } elseif (($ping = $this->ping()) !== null) {
            $outcome['warning'] = $ping;
        }

        return $this->remember($outcome);
    }

    /** The signed self-check `GET /v1/stores/{id}/ping`; null when it passed, else the error code. */
    public function ping(): ?string
    {
        $storeId = $this->secrets->storeId();
        $base = $this->normaliseUrl($this->platformUrl());
        if ($storeId === null || $base === null) {
            return 'store_unpaired';
        }
        try {
            $answer = $this->client->signed($base, 'GET', '/v1/stores/'.$storeId.'/ping');
        } catch (PlatformError $error) {
            return $error->errorCode();
        }

        return ($answer['ok'] ?? null) === true ? null : PlatformError::BAD_RESPONSE;
    }

    /**
     * Unpairs from the shop's side (README §5, platform/unpair.json): tells the platform when it can
     * (best effort, `$reason` user|uninstall|reset|other) and forgets the secrets whatever it answers.
     */
    public function unpair(string $reason, int $timeoutSeconds = 10): void
    {
        $storeId = $this->secrets->storeId();
        $base = $this->normaliseUrl($this->platformUrl());
        if ($storeId !== null && $base !== null) {
            try {
                $this->client->signed($base, 'POST', '/v1/stores/'.$storeId.'/unpair', ['reason' => $reason], $timeoutSeconds);
            } catch (PlatformError $error) {
                $this->log->warning('pairing', 'Unpair not confirmed by the platform', ['error_code' => $error->errorCode()]);
            }
        }
        $this->secrets->forget();
        $this->settings->delete(self::STATE);
        $this->log->info('pairing', 'Unpaired from the shop', ['reason' => $reason]);
    }

    /**
     * The platform/pair.json request.
     *
     * @return array<string, mixed>
     */
    public function request(string $code, string $publicKey): array
    {
        $request = [
            'code' => $code,
            'store_url' => $this->shop->shopUrl(),
            'platform' => 'prestashop',
            'version' => defined('_PS_VERSION_') ? (string) constant('_PS_VERSION_') : '0',
            'php' => PHP_VERSION,
            'module_version' => Protocol::MODULE_VERSION,
            'protocol' => Protocol::VERSION,
            'public_key' => $publicKey,
            'shop_id' => $this->shop->shopId(),
            'capabilities_hash' => hash('sha256', (string) json_encode($this->endpoints->capabilities(), Response::JSON_FLAGS)),
        ];
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $this->shop->shopName());
        if ($name !== '') {
            $request['shop_name'] = function_exists('mb_substr') ? mb_substr($name, 0, 255) : substr($name, 0, 255);
        }
        $zone = $this->shop->timezone();
        if (preg_match('#\A[A-Za-z0-9_+/-]{1,64}\z#', $zone) === 1) {
            $request['timezone'] = $zone;
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private static function errorState(PlatformError $error, int $now): array
    {
        $state = ['state' => 'error', 'code' => $error->errorCode()];
        $details = $error->details();
        if (is_string($details['reason'] ?? null) && preg_match('/\A[a-z_]{1,64}\z/', $details['reason']) === 1) {
            $state['reason'] = $details['reason'];
        }
        if (is_string($details['min_module_version'] ?? null)) {
            $state['min_module_version'] = substr($details['min_module_version'], 0, 32);
        }
        if (is_array($details['errors'] ?? null)) {
            $state['errors'] = array_values(array_filter($details['errors'], static function ($member): bool {
                return is_string($member) && preg_match('/\A[a-z_]{1,64}\z/', $member) === 1;
            }));
        }
        if ($error->retryAfter() !== null) {
            $state['retry_until'] = $now + $error->retryAfter();
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function remember(array $state): array
    {
        $state['at'] = $this->now();
        $this->settings->set(self::STATE, (string) json_encode($state, Response::JSON_FLAGS));

        return $state;
    }

    private function now(): int
    {
        return (int) floor($this->clock->now());
    }
}
