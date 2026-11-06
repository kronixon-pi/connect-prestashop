<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Support;

use Kronixon\Connect\Auth\Canonical;
use Kronixon\Connect\Platform\PlatformError;
use Kronixon\Connect\Platform\Transport;

/**
 * The Connect service as the merged platform answers (PairStore, StoreSessionController,
 * VerifyConnectSignature): `POST /v1/pair` → 201 with the secret sealed to the module's key,
 * signed `ping` / `unpair` / `rotate-request` verified and answered signed. Modes break one thing.
 */
final class FakePlatform implements Transport
{
    public const CODE = 'K7PM-3XQ9';

    public const STORE_ID = '0192b2e4-2f7a-7c5b-8a1d-3e4f5a6b7c8d';

    public const PLATFORM_KEY = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    /** @var string|null the secret issued at pairing */
    public $secret;

    /** @var string|null base64 public key the module sent */
    public $modulePublicKey;

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    public $requests = [];

    /** @var array{0: int, 1: string, 2: array<string, mixed>, 3: int|null}|null status, code, details, retry_after of a refused pair */
    public $refusePair;

    /** @var string normal | network | unsigned | forged */
    public $mode = 'normal';

    /** @var int */
    public $now = 1790772000;

    public function send(string $method, string $url, array $headers, string $body, int $timeoutSeconds): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if ($this->mode === 'network') {
            throw new PlatformError(0, PlatformError::NETWORK);
        }
        if (($headers['X-KX-Protocol'] ?? null) !== 'v1') {
            return self::error(400, 'protocol_version_unsupported');
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($method === 'POST' && $path === '/v1/pair') {
            return $this->pair($body);
        }

        return $this->signed($method, $path, $headers, $body);
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function pair(string $body): array
    {
        if ($this->refusePair !== null) {
            [$status, $code, $details, $retry] = $this->refusePair;

            return self::error($status, $code, $details, $retry);
        }
        $input = json_decode($body, true);
        if (! is_array($input) || ($input['code'] ?? null) !== self::CODE) {
            return self::error(400, 'pair_code_invalid');
        }
        $this->modulePublicKey = (string) $input['public_key'];
        $this->secret = 'kxs_'.substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(48))), 0, 32);
        $sealed = base64_encode(sodium_crypto_box_seal($this->secret, (string) base64_decode($this->modulePublicKey, true)));

        return ['status' => 201, 'headers' => ['x-kx-protocol' => 'v1'], 'body' => (string) json_encode([
            'store_id' => self::STORE_ID,
            'shared_secret_sealed' => $sealed,
            'platform_public_key' => self::PLATFORM_KEY,
            'api_base' => 'https://connect.example.test/v1',
            'events_url' => 'https://connect.example.test/v1/stores/'.self::STORE_ID.'/events',
            'server_time' => $this->now,
            'tenant_name' => 'Example Ltd',
            'heartbeat_interval' => 86400,
        ], JSON_UNESCAPED_SLASHES)];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function signed(string $method, string $path, array $headers, string $body): array
    {
        $nonce = $headers['X-KX-Nonce'] ?? '';
        $canonical = Canonical::request($method, $path, '', (int) ($headers['X-KX-Timestamp'] ?? 0), $nonce, $body);
        if ($this->secret === null || ($headers['X-KX-Store'] ?? '') !== self::STORE_ID
            || ! hash_equals(hash_hmac('sha256', $canonical, $this->secret), $headers['X-KX-Signature'] ?? '')) {
            return self::error(401, 'signature_invalid');
        }
        $answer = (string) json_encode(substr($path, -5) === '/ping' ? ['ok' => true, 'server_time' => $this->now] : ['ok' => true]);
        $status = 200;
        $responseHeaders = ['x-kx-protocol' => 'v1'];
        if ($this->mode !== 'unsigned') {
            $key = $this->mode === 'forged' ? 'kxs_forgedforgedforgedforgedforged12' : $this->secret;
            $responseHeaders['x-kx-timestamp'] = (string) $this->now;
            $responseHeaders['x-kx-signature'] = hash_hmac('sha256', Canonical::response($nonce, $this->now, $status, $answer), $key);
            $responseHeaders['x-kx-key-id'] = 'cur';
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $answer];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private static function error(int $status, string $code, array $details = [], ?int $retryAfter = null): array
    {
        $error = ['code' => $code, 'message' => 'refused', 'request_id' => 'r-1', 'server_time' => 1790772000];
        if ($details !== []) {
            $error['details'] = $details;
        }
        if ($retryAfter !== null) {
            $error['retry_after'] = $retryAfter;
        }

        return ['status' => $status, 'headers' => ['x-kx-protocol' => 'v1'], 'body' => (string) json_encode(['error' => $error])];
    }
}
