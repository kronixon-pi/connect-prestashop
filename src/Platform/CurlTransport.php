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
 * Transport over PHP's curl (required by PrestaShop itself): TLS verified, no redirects, http(s)
 * only, the answer capped at 1 MB.
 */
final class CurlTransport implements Transport
{
    private const MAX_ANSWER_BYTES = 1048576;

    public function send(string $method, string $url, array $headers, string $body, int $timeoutSeconds): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new PlatformError(0, PlatformError::NETWORK);
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }
        $received = [];
        $answer = '';
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$received): int {
                $colon = strpos($line, ':');
                if ($colon !== false) {
                    $received[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$answer): int {
                $answer .= $chunk;

                return strlen($answer) > self::MAX_ANSWER_BYTES ? 0 : strlen($chunk);
            },
        ]);
        if ($method !== 'GET') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        unset($handle); // curl_close() is a no-op since PHP 8.0 and deprecated in 8.5
        if ($ok === false || $status === 0) {
            throw new PlatformError(0, PlatformError::NETWORK);
        }

        return ['status' => $status, 'headers' => $received, 'body' => $answer];
    }
}
