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

/**
 * One HTTP exchange with the Connect service (CurlTransport in the shop, a fake in tests).
 */
interface Transport
{
    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, headers: array<string, string>, body: string} header names in lower case
     *
     * @throws PlatformError `network_error` when no answer arrives
     */
    public function send(string $method, string $url, array $headers, string $body, int $timeoutSeconds): array;
}
