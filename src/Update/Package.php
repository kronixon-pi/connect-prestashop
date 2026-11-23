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

namespace Kronixon\Connect\Update;

use Kronixon\Connect\Protocol;

/**
 * The checks between a downloaded zip and its files (Spec §10 item 3), in this order, nothing unpacked before all pass:
 *
 * 1. the size and sha256 the signed answer announced;
 * 2. the Ed25519 signature over the zip's bytes, with the built-in key the answer names (ReleaseKeys);
 * 3. the zip (ZipReader) holds `kronixonconnect/kx-manifest.json` and exactly the files it lists, each with its
 *    sha256, on safe relative paths;
 * 4. the manifest is this module's, of the announced version, of this build's channel and API host, newer than the
 *    running version (no downgrade, no reinstall), for this protocol, PrestaShop and PHP.
 */
final class Package
{
    public const MAX_ZIP_BYTES = 20971520;

    private const ROOT = Protocol::MODULE_NAME.'/';

    private const MANIFEST = 'kx-manifest.json';

    /**
     * The checks that need no download: channel and version.
     *
     * @throws UpdateError
     */
    public static function admissible(Release $release, ReleaseKeys $keys, string $running): void
    {
        if (! $keys->available()) {
            throw UpdateError::server('no_release_keys');
        }
        if ($release->channel !== $keys->channel()) {
            throw UpdateError::refused('channel_mismatch', $release->channel);
        }
        if (version_compare($release->version, $running, '<=')) {
            throw UpdateError::refused('not_newer', $release->version);
        }
    }

    /**
     * @return array<string, string> path relative to the module folder => contents, the manifest included
     *
     * @throws UpdateError
     */
    public static function open(string $zip, Release $release, ReleaseKeys $keys, string $running, string $apiHost, string $psVersion, string $php): array
    {
        self::admissible($release, $keys, $running);
        if (strlen($zip) !== $release->size) {
            throw UpdateError::refused('size_mismatch', (string) strlen($zip));
        }
        if (! hash_equals($release->sha256, hash('sha256', $zip))) {
            throw UpdateError::refused('sha256_mismatch');
        }
        $keys->verify($zip, $release->signature, $release->keyId);

        $files = [];
        foreach (ZipReader::entries($zip) as $name => $contents) {
            $path = strpos($name, self::ROOT) === 0 ? substr($name, strlen(self::ROOT)) : '';
            if (! self::safe($path)) {
                throw UpdateError::refused('bad_package', 'path '.substr($name, 0, 100));
            }
            $files[$path] = $contents;
        }
        $manifest = json_decode($files[self::MANIFEST] ?? '', true);
        if (! is_array($manifest) || ! is_array($manifest['files'] ?? null)) {
            throw UpdateError::refused('bad_package', 'manifest');
        }
        self::matches($manifest, $release, $keys, $apiHost, $psVersion, $php);

        $listed = $manifest['files'];
        $unlisted = array_diff(array_keys($files), array_keys($listed), [self::MANIFEST]);
        if ($unlisted !== [] || count($listed) !== count($files) - 1) {
            throw UpdateError::refused('bad_package', 'files differ from the manifest');
        }
        foreach ($listed as $path => $sha256) {
            if (! is_string($sha256) || ! isset($files[$path]) || ! hash_equals($sha256, hash('sha256', $files[$path]))) {
                throw UpdateError::refused('bad_package', 'sha256 of '.substr((string) $path, 0, 100));
            }
        }
        foreach ([Protocol::MODULE_NAME.'.php', 'config.xml'] as $required) {
            if (! isset($files[$required])) {
                throw UpdateError::refused('bad_package', 'no '.$required);
            }
        }
        if (strpos($files['src/Protocol.php'] ?? '', "    public const MODULE_VERSION = '".$release->version."';\n") === false) {
            throw UpdateError::refused('bad_package', 'module version');
        }

        return $files;
    }

    /**
     * @param  array<mixed>  $manifest
     *
     * @throws UpdateError
     */
    private static function matches(array $manifest, Release $release, ReleaseKeys $keys, string $apiHost, string $psVersion, string $php): void
    {
        $expected = [
            'manifest' => 1,
            'module' => Protocol::MODULE_NAME,
            'platform' => 'prestashop',
            'version' => $release->version,
            'channel' => $keys->channel(),
            'protocol' => Protocol::VERSION,
            'api_host' => $apiHost,
        ];
        foreach ($expected as $field => $value) {
            if (($manifest[$field] ?? null) !== $value) {
                throw UpdateError::refused($field === 'channel' ? 'channel_mismatch' : 'bad_package', 'manifest '.$field);
            }
        }
        $min = (string) ($manifest['min_platform'] ?? '');
        $max = (string) ($manifest['max_platform'] ?? '');
        $minPhp = (string) ($manifest['min_php'] ?? '');
        if ($min === '' || $max === '' || $minPhp === '') {
            throw UpdateError::refused('bad_package', 'manifest compatibility');
        }
        if (version_compare($psVersion, $min, '<') || version_compare($psVersion, $max, '>')) {
            throw UpdateError::refused('platform_unsupported', $psVersion);
        }
        if (version_compare($php, $minPhp, '<')) {
            throw UpdateError::refused('php_too_old', $php);
        }
    }

    /** A relative path of plain names: no `..`, `.`, empty segment, backslash, control byte or leading slash. */
    private static function safe(string $path): bool
    {
        if ($path === '' || strlen($path) > 240 || preg_match('#\A[A-Za-z0-9_][A-Za-z0-9._-]*(/[A-Za-z0-9_][A-Za-z0-9._-]*)*\z#', $path) !== 1) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return false;
            }
        }

        return true;
    }
}
