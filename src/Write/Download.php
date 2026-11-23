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
 * Fetches a file a signed request names by URL and sha256 (a logo, a product image): only from a host
 * of the platform the module is paired with (its Connect host and the `download_hosts` of the pairing),
 * HTTPS only (plain HTTP with the development setting KX_PLATFORM_ALLOW_HTTP), TLS verified, no
 * redirects, at most 20 MB in 20 s, and nothing is kept whose sha256 differs. Outside development the
 * host must resolve to public addresses only, and the connection goes to the address that was checked
 * (no second lookup that could answer otherwise).
 */
final class Download
{
    public const MAX_BYTES = 20971520;

    /** @var bool */
    private $allowHttp;

    /** @var list<string> */
    private $hosts;

    /** @var callable(string): list<string> the addresses of a host name */
    private $resolve;

    /**
     * @param  list<string>  $hosts  where files may come from: `host` (port 443) or `host:port`
     * @param  (callable(string): list<string>)|null  $resolve  gethostbynamel() unless given
     */
    public function __construct(bool $allowHttp, array $hosts = [], ?callable $resolve = null)
    {
        $this->allowHttp = $allowHttp;
        $this->hosts = array_values(array_unique(array_map(static function (string $host): string {
            $host = strtolower($host);

            return preg_match('/:[0-9]{1,5}\z/', $host) === 1 ? $host : $host.':443';
        }, $hosts)));
        $this->resolve = $resolve ?? static function (string $host): array {
            $ips = gethostbynamel($host);

            return is_array($ips) ? $ips : [];
        };
    }

    /**
     * A temporary file (in $dir, else the system's temporary folder) with exactly the bytes of $sha256;
     * the caller deletes it.
     *
     * @throws ItemError
     */
    public function fetch(string $url, string $sha256, string $field, ?string $dir = null): string
    {
        $pin = $this->check($url, $field);
        $path = tempnam($dir ?? sys_get_temp_dir(), 'kx');
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
        if ($pin !== null) {
            curl_setopt($handle, CURLOPT_RESOLVE, [$pin]);
        }
        $ok = curl_exec($handle) !== false && (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200;
        unset($handle);
        fclose($file);
        if (! $ok || ! hash_equals($sha256, (string) hash_file('sha256', $path))) {
            unlink($path);
            throw $ok ? new ItemError('sha256_mismatch', 'The file is not the one announced', $field) : new ItemError('download_failed', 'The file could not be fetched', $field);
        }

        return $path;
    }

    /**
     * The URL may be fetched: its scheme, its host, and outside development its addresses.
     *
     * @return string|null the `host:port:address` the connection is pinned to
     *
     * @throws ItemError
     */
    public function check(string $url, string $field): ?string
    {
        $parts = parse_url($url);
        if (preg_match($this->allowHttp ? '#\Ahttps?://[^\s]+\z#' : '#\Ahttps://[^\s]+\z#', $url) !== 1 || strlen($url) > 2048
            || ! is_array($parts) || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw ItemError::invalid($field.'.url');
        }
        $host = strtolower(trim($parts['host'], '[]'));
        $port = (int) ($parts['port'] ?? (strtolower((string) ($parts['scheme'] ?? '')) === 'http' ? 80 : 443));
        if (! in_array($host.':'.$port, $this->hosts, true)) {
            throw new ItemError('download_host_refused', 'The file is not on a host of the platform', $field.'.url');
        }
        if ($this->allowHttp) {
            return null;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolve)($host);
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new ItemError('download_host_refused', 'The host of the file has a private address', $field.'.url');
            }
        }
        if ($ips === []) {
            throw new ItemError('download_failed', 'The host of the file is unknown', $field.'.url');
        }

        return $host.':'.$port.':'.$ips[0];
    }
}
