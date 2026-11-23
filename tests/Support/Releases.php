<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Support;

use Kronixon\Connect\Protocol;
use Kronixon\Connect\Release\ReleaseBuilder;
use Kronixon\Connect\Update\ReleaseKeys;
use ReflectionMethod;

require_once dirname(__DIR__, 2).'/scripts/release/ReleaseBuilder.php';

/**
 * Release zips of the work tree's module as the release build makes them, of any version, signed with a throw-away
 * key made from a fixed seed (never a real release key), and the signed answer of `GET /v1/module/latest` naming them.
 */
final class Releases
{
    public const KEY_ID = 'kx-test-1';

    private const SEED = 'throw-away release key of the self-update tests';

    public static function secretKey(string $seed = self::SEED): string
    {
        return sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(hash('sha256', $seed, true)));
    }

    public static function publicKey(string $seed = self::SEED): string
    {
        return sodium_crypto_sign_publickey_from_secretkey(self::secretKey($seed));
    }

    public static function keyList(): string
    {
        return self::KEY_ID.':'.base64_encode(self::publicKey());
    }

    /** The keys a staging build of these tests has built in. */
    public static function keys(string $channel = 'staging'): ReleaseKeys
    {
        return new ReleaseKeys($channel, self::keyList());
    }

    /**
     * The module's files of the work tree as $version.
     *
     * @return array<string, string>
     */
    public static function files(string $version): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        foreach (ReleaseBuilder::PATHS as $path) {
            if (is_file($root.'/'.$path)) {
                $files[$path] = (string) file_get_contents($root.'/'.$path);

                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$path, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $files[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }
        $files['config.xml'] = str_replace('<![CDATA['.Protocol::MODULE_VERSION.']]>', '<![CDATA['.$version.']]>', $files['config.xml']);
        $files['src/Protocol.php'] = str_replace("MODULE_VERSION = '".Protocol::MODULE_VERSION."';", "MODULE_VERSION = '".$version."';", $files['src/Protocol.php']);

        return $files;
    }

    /**
     * @param  array<string, string>|null  $files  the work tree's as $version unless given
     */
    public static function build(string $version, string $channel = 'staging', ?array $files = null): string
    {
        return ReleaseBuilder::build($files ?? self::files($version), '7.4.0', $version, $channel, self::keyList());
    }

    /**
     * A zip of exactly these entries (below `kronixonconnect/`), written by the release build's own writer: for
     * packages the build would never make.
     *
     * @param  array<string, string>  $entries
     */
    public static function zip(array $entries): string
    {
        $zip = new ReflectionMethod(ReleaseBuilder::class, 'zip');
        if (PHP_VERSION_ID < 80100) {
            $zip->setAccessible(true);
        }
        ksort($entries, SORT_STRING);

        return (string) $zip->invoke(null, $entries);
    }

    public static function sign(string $zip, string $seed = self::SEED): string
    {
        return base64_encode(sodium_crypto_sign_detached($zip, self::secretKey($seed)));
    }

    /**
     * `latest` of the signed answer for $zip.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function latest(string $zip, string $version, array $overrides = []): array
    {
        return $overrides + [
            'version' => $version,
            'channel' => 'staging',
            'min_platform' => '1.7.8.0',
            'max_platform' => '9.99.99',
            'min_php' => '7.4.0',
            'url' => 'https://connect-staging.kronixon.com/dl/kronixonconnect-'.$version.'.zip',
            'size' => strlen($zip),
            'sha256' => hash('sha256', $zip),
            'signature' => self::sign($zip),
            'signature_key_id' => self::KEY_ID,
            'changelog_url' => 'https://github.com/kronixon-pi/connect-prestashop/releases/tag/v'.$version,
            'released_at' => '2026-11-23T10:00:00Z',
            'mandatory' => false,
            'protocol' => 'v1',
        ];
    }
}
