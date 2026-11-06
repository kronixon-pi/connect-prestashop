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

namespace Kronixon\Connect\Api;

use RuntimeException;

/**
 * A protocol error (Spec §5.6): HTTP status + stable `code`. The message is English, short and never
 * contains secrets, signatures, paths of the host or stack traces.
 */
final class ApiError extends RuntimeException
{
    /** @var int */
    private $status;

    /** @var string */
    private $errorCode;

    /** @var array<string, mixed> */
    private $details;

    /** @var int|null */
    private $retryAfter;

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(int $status, string $errorCode, string $message, array $details = [], ?int $retryAfter = null)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->details = $details;
        $this->retryAfter = $retryAfter;
    }

    public static function badRequest(string $message): self
    {
        return new self(400, 'bad_request', $message);
    }

    public static function protocolUnsupported(): self
    {
        return new self(400, 'protocol_version_unsupported', 'Send X-KX-Protocol: v1 and a /v1 path.', ['supported' => ['v1']]);
    }

    public static function signatureInvalid(): self
    {
        return new self(401, 'signature_invalid', 'The request signature does not match.');
    }

    public static function timestampOutOfWindow(): self
    {
        return new self(401, 'timestamp_out_of_window', 'X-KX-Timestamp is outside the allowed clock window.');
    }

    public static function nonceReused(): self
    {
        return new self(401, 'nonce_reused', 'X-KX-Nonce was already used.');
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self(429, 'rate_limited', 'Too many requests.', [], $retryAfter);
    }

    public static function busy(int $retryAfter): self
    {
        return new self(409, 'busy', 'Another write holds the write lock.', [], $retryAfter);
    }

    /**
     * @param  list<string>  $members  the request members that are missing or invalid
     */
    public static function validationFailed(array $members): self
    {
        return new self(422, 'validation_failed', 'The request body does not match the schema.', ['errors' => $members]);
    }

    public static function unpaired(): self
    {
        return new self(403, 'store_unpaired', 'This module is not paired with Kronixon PI.');
    }

    public static function notFound(): self
    {
        return new self(404, 'not_found', 'Unknown endpoint.');
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function methodNotAllowed(array $allowed = []): self
    {
        return new self(405, 'method_not_allowed', 'Method not allowed for this endpoint.', $allowed === [] ? [] : ['allowed' => $allowed]);
    }

    public static function payloadTooLarge(int $limit): self
    {
        return new self(413, 'payload_too_large', 'The request body exceeds the limit.', ['limit_bytes' => $limit]);
    }

    public static function moduleError(): self
    {
        return new self(500, 'module_error', 'Unexpected module error.');
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
