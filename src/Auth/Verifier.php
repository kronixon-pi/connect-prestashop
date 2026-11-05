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

use Kronixon\Connect\Api\ApiError;
use Kronixon\Connect\Protocol;
use Kronixon\Connect\Support\Uuid;

/**
 * Verifies v1 signatures with hash_equals() against every candidate key of the key ring. It checks
 * format, clock window and HMAC; nonce replay is the NonceStore's job (it runs after a valid signature,
 * so a forged request cannot burn a nonce).
 */
final class Verifier
{
    /** @var int */
    private $windowSeconds;

    public function __construct(int $windowSeconds = Protocol::CLOCK_WINDOW_S)
    {
        $this->windowSeconds = $windowSeconds;
    }

    /**
     * @param  string  $body  the uncompressed body
     * @return string the key id that matched (`cur` or `prev`)
     *
     * @throws ApiError 401 signature_invalid | timestamp_out_of_window
     */
    public function verifyRequest(KeyRing $keys, string $method, string $logicalPath, string $rawQuery, string $timestamp, string $nonce, string $body, string $signature, ?string $keyId, int $now): string
    {
        $ts = $this->checkEnvelope($timestamp, $nonce, $signature, $now);
        $canonical = Canonical::request($method, $logicalPath, $rawQuery, $ts, $nonce, $body);

        return $this->match($keys, $keyId, $now, $canonical, $signature);
    }

    /**
     * Verifies a response to a request that carried $requestNonce.
     *
     * @return string the key id that matched
     *
     * @throws ApiError 401 signature_invalid | timestamp_out_of_window
     */
    public function verifyResponse(KeyRing $keys, string $requestNonce, string $timestamp, int $status, string $body, string $signature, ?string $keyId, int $now): string
    {
        $ts = $this->checkEnvelope($timestamp, $requestNonce, $signature, $now);
        $canonical = Canonical::response($requestNonce, $ts, $status, $body);

        return $this->match($keys, $keyId, $now, $canonical, $signature);
    }

    private function checkEnvelope(string $timestamp, string $nonce, string $signature, int $now): int
    {
        if (preg_match('/\A[0-9]{1,12}\z/', $timestamp) !== 1 || ! Uuid::isValid($nonce)
            || preg_match('/\A[0-9a-fA-F]{64}\z/', $signature) !== 1) {
            throw ApiError::signatureInvalid();
        }
        $ts = (int) $timestamp;
        if (abs($now - $ts) > $this->windowSeconds) {
            throw ApiError::timestampOutOfWindow();
        }

        return $ts;
    }

    private function match(KeyRing $keys, ?string $keyId, int $now, string $canonical, string $signature): string
    {
        $given = strtolower($signature);
        foreach ($keys->candidates($keyId, $now) as $id => $secret) {
            if (hash_equals(Signer::hmac($secret, $canonical), $given)) {
                return $id;
            }
        }
        throw ApiError::signatureInvalid();
    }
}
