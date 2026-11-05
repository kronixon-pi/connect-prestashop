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

use Kronixon\Connect\Protocol;

/**
 * HMAC-SHA256 signatures of protocol v1: lower-case hex over the canonical string (Canonical).
 */
final class Signer
{
    public static function hmac(string $secret, string $canonical): string
    {
        return hash_hmac('sha256', $canonical, $secret);
    }

    public function signRequest(string $secret, string $method, string $logicalPath, string $rawQuery, int $timestamp, string $nonce, string $body): string
    {
        return self::hmac($secret, Canonical::request($method, $logicalPath, $rawQuery, $timestamp, $nonce, $body));
    }

    public function signResponse(string $secret, string $requestNonce, int $timestamp, int $status, string $body): string
    {
        return self::hmac($secret, Canonical::response($requestNonce, $timestamp, $status, $body));
    }

    /**
     * Headers of a signed request (the caller adds Content-Type, Idempotency-Key, User-Agent).
     *
     * @return array<string, string>
     */
    public function requestHeaders(string $storeId, string $keyId, string $secret, string $method, string $logicalPath, string $rawQuery, int $timestamp, string $nonce, string $body): array
    {
        return [
            Protocol::HEADER_STORE => $storeId,
            Protocol::HEADER_TIMESTAMP => (string) $timestamp,
            Protocol::HEADER_NONCE => $nonce,
            Protocol::HEADER_SIGNATURE => $this->signRequest($secret, $method, $logicalPath, $rawQuery, $timestamp, $nonce, $body),
            Protocol::HEADER_PROTOCOL => Protocol::VERSION,
            Protocol::HEADER_KEY_ID => $keyId,
        ];
    }

    /**
     * Headers of a signed response to a request that carried $requestNonce.
     *
     * @return array<string, string>
     */
    public function responseHeaders(string $keyId, string $secret, string $requestNonce, int $timestamp, int $status, string $body): array
    {
        return [
            Protocol::HEADER_TIMESTAMP => (string) $timestamp,
            Protocol::HEADER_SIGNATURE => $this->signResponse($secret, $requestNonce, $timestamp, $status, $body),
            Protocol::HEADER_KEY_ID => $keyId,
        ];
    }
}
