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

    public const MODULE_VERSION = '0.1.0';

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
