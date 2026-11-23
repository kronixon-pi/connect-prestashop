<?php

declare(strict_types=1);

namespace Kronixon\Connect\Release;

use RuntimeException;

/**
 * Builds and signs the module's release zip (docs/RELEASING.md). Not part of the module.
 *
 * The zip holds the module folder exactly as scripts/bake-fixture.sh installs it into the test shops — the same list
 * of tracked files — with the channel's API host and first public key written into src/Protocol.php and
 * `kx-manifest.json` (Spec §10: version, channel, compatibility, the sha256 of every file). It is a function of the
 * commit, the version, the channel and the keys alone: the files come from git, sorted by path, stored uncompressed
 * with one fixed time and mode, by the writer below — no libzip or zlib version can change a byte.
 */
final class ReleaseBuilder
{
    /** What the module is: bake-fixture.sh and fixture-sha.sh use the same list (ReleaseBuilderTest). */
    public const PATHS = ['kronixonconnect.php', 'config.xml', 'index.php', 'LICENSE', 'controllers', 'src', 'sql', 'translations', 'upgrade', 'views'];

    /** channel => the API host its builds talk to. */
    public const HOSTS = [
        'staging' => 'https://connect-staging.kronixon.com',
        'stable' => 'https://connect.kronixon.com',
    ];

    private const ROOT = 'kronixonconnect/';

    /** 2026-01-01 00:00:00 in the DOS form (date, time): the same for every file of every build. */
    private const DOS_DATE = ((2026 - 1980) << 9) | (1 << 5) | 1;

    private const DOS_TIME = 0;

    /**
     * `key_id:base64[,key_id:base64…]` → key_id => 32 bytes; the first is the key in use.
     *
     * @return array<string, string>
     */
    public static function keys(string $list): array
    {
        $keys = [];
        foreach (explode(',', trim($list)) as $entry) {
            $parts = explode(':', trim($entry), 2);
            $bytes = base64_decode($parts[1] ?? '', true);
            if (preg_match('/\A[a-z0-9][a-z0-9-]{0,31}\z/', $parts[0]) !== 1 || $bytes === false || strlen($bytes) !== 32 || isset($keys[$parts[0]])) {
                throw new RuntimeException('The public keys are a comma list of key_id:base64 (32 bytes), each id once.');
            }
            $keys[$parts[0]] = $bytes;
        }

        return $keys;
    }

    /**
     * The module's tracked files at HEAD; refuses a work tree that differs, so what was reviewed is what is built.
     *
     * @return array<string, string> path => contents
     */
    public static function filesFromGit(string $root): array
    {
        $paths = implode(' ', array_map('escapeshellarg', self::PATHS));
        if (self::run($root, 'git status --porcelain -- '.$paths) !== '') {
            throw new RuntimeException('The module files differ from HEAD; commit them first.');
        }
        $files = [];
        foreach (explode("\0", rtrim(self::run($root, 'git ls-tree -r -z --name-only HEAD -- '.$paths), "\0")) as $path) {
            $files[$path] = self::run($root, 'git cat-file blob '.escapeshellarg('HEAD:'.$path));
        }

        return $files;
    }

    /** `composer.json` of HEAD — the compatibility comes from the commit too, whatever the work tree says. */
    public static function composerFromGit(string $root): string
    {
        return self::run($root, 'git cat-file blob HEAD:composer.json');
    }

    /**
     * @param  array<string, string>  $files  path => contents, as filesFromGit() gives them
     * @return string the zip's bytes
     */
    public static function build(array $files, string $minPhp, string $version, string $channel, string $keyList): string
    {
        if (! isset(self::HOSTS[$channel])) {
            throw new RuntimeException('The channel is staging or stable.');
        }
        $keys = self::keys($keyList);
        $source = $files['src/Protocol.php'] ?? '';
        $moduleVersion = self::constant($source, 'MODULE_VERSION');
        if ($moduleVersion !== $version) {
            throw new RuntimeException(sprintf('The module is version %s, not %s.', $moduleVersion, $version));
        }
        if (preg_match('/<version><!\[CDATA\[(.*?)\]\]><\/version>/', $files['config.xml'] ?? '', $match) !== 1 || $match[1] !== $version) {
            throw new RuntimeException('The version of config.xml is not the module\'s.');
        }
        if (preg_match("/'max' => '([0-9.]+)'/", $files['kronixonconnect.php'] ?? '', $max) !== 1) {
            throw new RuntimeException('kronixonconnect.php names no newest PrestaShop version.');
        }

        $source = self::replace($source, 'DEFAULT_PLATFORM_URL', self::HOSTS[$channel]);
        $source = self::replace($source, 'CHANNEL', $channel);
        // The keys the module checks its own updates with (Update\ReleaseKeys), in the order given: the key in use first.
        $source = self::replace($source, 'RELEASE_KEYS', implode(',', array_map(static function (string $id, string $bytes): string {
            return $id.':'.base64_encode($bytes);
        }, array_keys($keys), $keys)));
        $files['src/Protocol.php'] = self::replace($source, 'PINNED_PLATFORM_KEY', base64_encode((string) reset($keys)));
        ksort($files, SORT_STRING);

        $published = [];
        foreach ($keys as $id => $bytes) {
            $published[] = ['key_id' => $id, 'public_key' => base64_encode($bytes)];
        }
        $manifest = [
            'manifest' => 1,
            'module' => 'kronixonconnect',
            'platform' => 'prestashop',
            'version' => $version,
            'channel' => $channel,
            'protocol' => self::constant($source, 'VERSION'),
            'min_platform' => self::constant($files['src/Compat/Version.php'] ?? '', 'MIN'),
            'max_platform' => $max[1],
            'min_php' => $minPhp,
            'api_host' => self::HOSTS[$channel],
            'release_keys' => $published,
            'files' => array_map(static function (string $contents): string {
                return hash('sha256', $contents);
            }, $files),
        ];
        $files['kx-manifest.json'] = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        ksort($files, SORT_STRING);

        return self::zip($files);
    }

    /** `composer.json` `require.php` (">=7.4") as the manifest writes it ("7.4.0"). */
    public static function minPhp(string $composerJson): string
    {
        $composer = json_decode($composerJson, true);
        if (! is_array($composer) || preg_match('/\A>=([0-9]+)\.([0-9]+)\z/', (string) ($composer['require']['php'] ?? ''), $match) !== 1) {
            throw new RuntimeException('composer.json require.php is not ">=x.y".');
        }

        return $match[1].'.'.$match[2].'.0';
    }

    /**
     * The Ed25519 secret key (64 bytes) from the secret as it was pasted: base64 of the 64-byte secret key or the 32-byte
     * seed (PHP's sodium), or the PEM of `openssl genpkey -algorithm ed25519`.
     */
    public static function secretKey(string $pasted): string
    {
        $pem = strpos($pasted, '-----BEGIN PRIVATE KEY-----') !== false;
        $raw = base64_decode((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pasted), true);
        $prefix = "\x30\x2e\x02\x01\x00\x30\x05\x06\x03\x2b\x65\x70\x04\x22\x04\x20";
        if ($pem && is_string($raw) && strlen($raw) === 48 && strncmp($raw, $prefix, 16) === 0) {
            $raw = substr($raw, 16);
        } elseif ($pem) {
            $raw = false;
        }
        if (is_string($raw) && strlen($raw) === 32) {
            return sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($raw));
        }
        // A 64-byte secret key carries its public half: both must belong together.
        if (is_string($raw) && strlen($raw) === 64 && sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(substr($raw, 0, 32))) === $raw) {
            return $raw;
        }

        throw new RuntimeException('The signing key is neither a PEM Ed25519 private key nor the base64 of 32 or 64 bytes.');
    }

    /**
     * base64 of the detached signature over the zip — only with the key the build pins first, checked before and the
     * signature after.
     */
    public static function sign(string $zip, string $secretKey, string $keyList): string
    {
        $keys = self::keys($keyList);
        $public = sodium_crypto_sign_publickey_from_secretkey($secretKey);
        if (! hash_equals((string) reset($keys), $public)) {
            throw new RuntimeException('The signing key is not the first public key of the channel: the module would pin another key.');
        }
        $signature = sodium_crypto_sign_detached($zip, $secretKey);
        if (! sodium_crypto_sign_verify_detached($signature, $zip, $public)) {
            throw new RuntimeException('The signature does not verify.');
        }

        return base64_encode($signature)."\n";
    }

    /** @param  array<string, string>  $files  sorted */
    private static function zip(array $files): string
    {
        $body = '';
        $directory = '';
        foreach ($files as $path => $contents) {
            $name = self::ROOT.$path;
            // version needed 1.0, no flags (ASCII names), stored, the fixed time
            $common = pack('vvvvvVVVvv', 10, 0, 0, self::DOS_TIME, self::DOS_DATE, crc32($contents), strlen($contents), strlen($contents), strlen($name), 0);
            // made by: Unix, 2.0; a regular file, 0644
            $directory .= "PK\x01\x02".pack('v', (3 << 8) | 20).$common.pack('vvvVV', 0, 0, 0, 0100644 << 16, strlen($body)).$name;
            $body .= "PK\x03\x04".$common.$name.$contents;
        }

        return $body.$directory."PK\x05\x06".pack('vvvvVVv', 0, 0, count($files), count($files), strlen($directory), strlen($body), 0);
    }

    private static function constant(string $source, string $name): string
    {
        if (preg_match_all('/^    public const '.$name.' = \'([^\']*)\';$/m', $source, $match) !== 1) {
            throw new RuntimeException(sprintf('The constant %s is not there exactly once.', $name));
        }

        return $match[1][0];
    }

    private static function replace(string $source, string $name, string $value): string
    {
        self::constant($source, $name);

        return (string) preg_replace_callback('/^(    public const '.$name.' = \')[^\']*(\';)$/m', static function (array $match) use ($value): string {
            return $match[1].$value.$match[2];
        }, $source);
    }

    private static function run(string $root, string $command): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w']], $pipes, $root);
        if (! is_resource($process)) {
            throw new RuntimeException('git could not be started.');
        }
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('git failed: '.$command);
        }

        return $output;
    }
}
