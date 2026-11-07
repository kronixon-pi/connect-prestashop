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

namespace Kronixon\Connect;

/**
 * Constants of Connect protocol v1 (protocol/v1/README.md).
 */
final class Protocol
{
    public const VERSION = 'v1';

    public const MODULE_NAME = 'kronixonconnect';

    public const MODULE_VERSION = '0.2.1';

    /** The Connect service the module pairs with unless the administrator enters another (README §1). */
    public const DEFAULT_PLATFORM_URL = 'https://connect.kronixon.com';

    /**
     * The base64 Ed25519 release key of the platform channel this build belongs to. When set, a pair
     * answer whose `platform_public_key` differs is refused; empty until signed releases exist (the
     * key is then built into the release zip).
     */
    public const PINNED_PLATFORM_KEY = '';

    /** Nonce memory of the receiver, seconds (README §4.4 step 7). */
    public const NONCE_TTL_S = 600;

    /** Verified requests per minute (README §9), unless KX_RATE_LIMIT says otherwise. */
    public const RATE_LIMIT_PER_MINUTE = 120;

    /** Seconds a `409 busy` asks the caller to wait for the write lock. */
    public const BUSY_RETRY_S = 5;

    /** Clock window of a signed message, seconds either side of the receiver's clock. */
    public const CLOCK_WINDOW_S = 300;

    /** Default request time budget (Spec §5.7), seconds; configurable up to MAX_TIME_BUDGET_S. */
    public const TIME_BUDGET_S = 25;

    public const MAX_TIME_BUDGET_S = 30;

    /** Request body limit before compression (Spec §5.7). */
    public const MAX_BODY_BYTES = 4194304;

    public const HEADER_STORE = 'X-KX-Store';

    public const HEADER_TIMESTAMP = 'X-KX-Timestamp';

    public const HEADER_NONCE = 'X-KX-Nonce';

    public const HEADER_SIGNATURE = 'X-KX-Signature';

    public const HEADER_PROTOCOL = 'X-KX-Protocol';

    public const HEADER_KEY_ID = 'X-KX-Key-Id';

    public const HEADER_REQUEST_ID = 'X-KX-Request-Id';

    public const HEADER_MODULE_VERSION = 'X-KX-Module-Version';

    public const HEADER_TIME_USED = 'X-KX-Time-Used';

    public const HEADER_METHOD_OVERRIDE = 'X-HTTP-Method-Override';

    private function __construct()
    {
    }
}
