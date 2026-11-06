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

use Kronixon\Connect\Api\ApiError;
use Kronixon\Connect\Api\Response;
use Kronixon\Connect\Auth\KeyRing;
use Kronixon\Connect\Auth\SecretStore;
use Kronixon\Connect\Auth\Signer;
use Kronixon\Connect\Auth\Verifier;
use Kronixon\Connect\Protocol;
use Kronixon\Connect\Support\Clock;
use Kronixon\Connect\Support\Uuid;

/**
 * Requests to the Connect service (README §6.2). `POST /v1/pair` is unsigned (the code is the
 * secret); every other request is signed with `cur` (README §4.2) and its 2xx answer must carry a
 * valid response signature over our nonce (README §4.3), else it is refused like no answer.
 */
final class PlatformClient
{
    /** @var Transport */
    private $transport;

    /** @var SecretStore */
    private $secrets;

    /** @var Clock */
    private $clock;

    public function __construct(Transport $transport, SecretStore $secrets, Clock $clock)
    {
        $this->transport = $transport;
        $this->secrets = $secrets;
        $this->clock = $clock;
    }

    /**
     * @param  array<string, mixed>  $payload  platform/pair.json request
     * @return array<string, mixed> the `201` body
     *
     * @throws PlatformError
     */
    public function pair(string $base, array $payload): array
    {
        $answer = $this->transport->send('POST', $base.'/v1/pair', $this->headers(Uuid::v4(), true), self::json($payload), 20);
        if ($answer['status'] !== 201) {
            throw self::failure($answer);
        }

        return self::decode($answer);
    }

    /**
     * A signed request to `$path` (`/v1/stores/{id}/…`).
     *
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed> the verified 2xx body
     *
     * @throws PlatformError
     */
    public function signed(string $base, string $method, string $path, ?array $body = null, int $timeoutSeconds = 10): array
    {
        $secret = $this->secrets->current();
        $storeId = $this->secrets->storeId();
        if ($secret === null || $storeId === null) {
            throw new PlatformError(0, 'store_unpaired');
        }
        $raw = $body === null ? '' : self::json($body);
        $nonce = Uuid::v4();
        $now = (int) floor($this->clock->now());
        $headers = (new Signer())->requestHeaders($storeId, KeyRing::CURRENT, $secret, $method, $path, '', $now, $nonce, $raw)
            + $this->headers($nonce, $raw !== '');
        $answer = $this->transport->send($method, $base.$path, $headers, $raw, $timeoutSeconds);

        $signature = $answer['headers']['x-kx-signature'] ?? null;
        $signed = false;
        if ($signature !== null) {
            try {
                (new Verifier())->verifyResponse(new KeyRing($secret), $nonce, (string) ($answer['headers']['x-kx-timestamp'] ?? ''), $answer['status'], $answer['body'], $signature, null, $now);
                $signed = true;
            } catch (ApiError $invalid) {
                $signed = false;
            }
        }
        if ($answer['status'] < 200 || $answer['status'] > 299) {
            throw self::failure($answer);
        }
        if (! $signed) {
            throw new PlatformError($answer['status'], $signature === null ? PlatformError::UNSIGNED : PlatformError::BAD_SIGNATURE);
        }

        return self::decode($answer);
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $requestId, bool $json): array
    {
        $headers = [
            Protocol::HEADER_PROTOCOL => Protocol::VERSION,
            Protocol::HEADER_REQUEST_ID => $requestId,
            'Accept' => 'application/json',
            'User-Agent' => sprintf('KronixonConnect/%s (prestashop %s; php %s)', Protocol::MODULE_VERSION, defined('_PS_VERSION_') ? constant('_PS_VERSION_') : '-', PHP_VERSION),
        ];
        if ($json) {
            $headers['Content-Type'] = 'application/json; charset=utf-8';
        }

        return $headers;
    }

    /**
     * @param  array{status: int, headers: array<string, string>, body: string}  $answer
     * @return array<string, mixed>
     */
    private static function decode(array $answer): array
    {
        $data = json_decode($answer['body'], true);
        if (! is_array($data)) {
            throw new PlatformError($answer['status'], PlatformError::BAD_RESPONSE);
        }

        return $data;
    }

    /**
     * The error envelope of README §7; its code and `retry_after` only steer the module's message
     * and its retry, an unsigned body is never trusted for data.
     *
     * @param  array{status: int, headers: array<string, string>, body: string}  $answer
     */
    private static function failure(array $answer): PlatformError
    {
        $data = json_decode($answer['body'], true);
        $error = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];
        $code = $error['code'] ?? null;
        if (! is_string($code) || preg_match('/\A[a-z_]{1,64}\z/', $code) !== 1) {
            $code = $answer['status'] >= 500 ? 'module_error' : PlatformError::BAD_RESPONSE;
        }
        $retry = $error['retry_after'] ?? ($answer['headers']['retry-after'] ?? null);
        $retryAfter = is_int($retry) || (is_string($retry) && ctype_digit($retry)) ? (int) $retry : null;
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];
        if (is_int($error['server_time'] ?? null)) {
            $details['server_time'] = $error['server_time'];
        }

        return new PlatformError($answer['status'], $code, $details, $retryAfter);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function json(array $data): string
    {
        return (string) json_encode($data, Response::JSON_FLAGS);
    }
}
