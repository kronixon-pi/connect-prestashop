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

use RuntimeException;

/**
 * A failed call to the Connect service: the platform's error code (README §7) or one of the
 * module's own codes below, with the error's `details` and `retry_after` when it gave them.
 */
final class PlatformError extends RuntimeException
{
    public const NETWORK = 'network_error';

    public const BAD_RESPONSE = 'bad_response';

    public const UNSIGNED = 'response_unsigned';

    public const BAD_SIGNATURE = 'response_signature_invalid';

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
    public function __construct(int $status, string $errorCode, array $details = [], ?int $retryAfter = null)
    {
        parent::__construct('Connect service call failed: '.$errorCode);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->details = $details;
        $this->retryAfter = $retryAfter;
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
