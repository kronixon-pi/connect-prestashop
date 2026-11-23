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

namespace Kronixon\Connect\Write;

/**
 * Fetches a file a signed request names by URL and sha256: HTTPS only (plain HTTP with the development
 * setting KX_PLATFORM_ALLOW_HTTP), TLS verified, no redirects, at most 20 MB in 20 s, and nothing is
 * kept whose sha256 differs.
 */
final class Download
{
    public const MAX_BYTES = 20971520;

    /** @var bool */
    private $allowHttp;

    public function __construct(bool $allowHttp)
    {
        $this->allowHttp = $allowHttp;
    }

    /**
     * A temporary file with exactly the bytes of $sha256; the caller deletes it.
     */
    public function fetch(string $url, string $sha256, string $field): string
    {
        if (preg_match($this->allowHttp ? '#\Ahttps?://[^\s]+\z#' : '#\Ahttps://[^\s]+\z#', $url) !== 1 || strlen($url) > 2048) {
            throw ItemError::invalid($field.'.url');
        }
        $path = tempnam(sys_get_temp_dir(), 'kx');
        $file = $path === false ? false : fopen($path, 'wb');
        $handle = curl_init($url);
        if ($path === false || $file === false || $handle === false) {
            throw new ItemError('download_failed', 'The file could not be fetched', $field);
        }
        $bytes = 0;
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => $this->allowHttp ? CURLPROTO_HTTP | CURLPROTO_HTTPS : CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($file, &$bytes): int {
                $bytes += strlen($chunk);

                return $bytes > self::MAX_BYTES ? 0 : (int) fwrite($file, $chunk);
            },
        ]);
        $ok = curl_exec($handle) !== false && (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200;
        unset($handle);
        fclose($file);
        if (! $ok || ! hash_equals($sha256, (string) hash_file('sha256', $path))) {
            unlink($path);
            throw $ok ? new ItemError('sha256_mismatch', 'The file is not the one announced', $field) : new ItemError('download_failed', 'The file could not be fetched', $field);
        }

        return $path;
    }
}
