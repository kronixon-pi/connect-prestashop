<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Release;

use Kronixon\Connect\Protocol;
use Kronixon\Connect\Release\ReleaseBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 3).'/scripts/release/ReleaseBuilder.php';

/**
 * The release zip (docs/RELEASING.md): the module's files with the channel's host and key, the same bytes every time,
 * signed only by the key it pins. Keys are derived on the spot; none is stored.
 */
final class ReleaseBuilderTest extends TestCase
{
    private const SEED = 'throw-away release key of the module tests';

    public function test_two_builds_of_one_commit_are_the_same_bytes(): void
    {
        self::assertSame(hash('sha256', $this->build()), hash('sha256', $this->build()));
        self::assertNotSame(hash('sha256', $this->build()), hash('sha256', $this->build('stable')));
    }

    public function test_the_order_the_files_are_listed_in_changes_nothing(): void
    {
        $files = $this->files();
        self::assertSame(
            hash('sha256', $this->buildOf($files)),
            hash('sha256', $this->buildOf(array_reverse($files, true)))
        );
    }

    public function test_the_zip_holds_the_module_and_nothing_of_the_development(): void
    {
        $entries = $this->entries($this->build());
        $paths = array_keys($entries);

        self::assertContains('kronixonconnect/kronixonconnect.php', $paths);
        self::assertContains('kronixonconnect/kx-manifest.json', $paths);
        self::assertSame($paths, array_values(array_unique($paths)));
        $sorted = $paths;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $paths);
        foreach ($paths as $path) {
            self::assertMatchesRegularExpression('#\Akronixonconnect/(kronixonconnect\.php|config\.xml|index\.php|LICENSE|kx-manifest\.json|(controllers|src|sql|translations|upgrade|views)/.+)\z#', $path);
            self::assertDoesNotMatchRegularExpression('#(^|/)(tests?|tools|docker|scripts|protocol|\.git[^/]*|\.github|vendor|composer\.(json|lock))(/|$)#', $path);
        }
    }

    public function test_the_module_files_are_the_list_the_test_shops_install(): void
    {
        $list = implode(' ', ReleaseBuilder::PATHS);
        $root = dirname(__DIR__, 3);

        self::assertStringContainsString('ls-files -z -- '.$list, (string) file_get_contents($root.'/scripts/bake-fixture.sh'));
        self::assertStringContainsString("\n    ".$list."\n", (string) file_get_contents($root.'/scripts/fixture-sha.sh'));
    }

    public function test_every_tracked_path_is_in_the_zip_or_known_to_stay_out(): void
    {
        $root = $this->checkout();
        $out = ['.commitlintrc.json', '.editorconfig', '.gitattributes', '.github', '.gitignore', '.php-cs-fixer.dist.php', '.well-known', 'CHANGELOG.md', 'Makefile', 'README.md', 'SECURITY.md', 'composer.json', 'composer.lock', 'docker', 'docs', 'phpcs.xml.dist', 'phpstan.neon.dist', 'phpunit.xml.dist', 'protocol', 'scripts', 'tests'];
        $tracked = explode("\n", trim((string) shell_exec('git -C '.escapeshellarg($root).' ls-tree --name-only HEAD')));
        $known = array_merge(ReleaseBuilder::PATHS, $out);
        sort($tracked);
        sort($known);

        self::assertSame($known, $tracked, 'a new top-level path: add it to ReleaseBuilder::PATHS (and bake-fixture.sh, fixture-sha.sh) or to this list');
    }

    public function test_the_lowest_php_is_the_commits_not_the_work_trees(): void
    {
        $root = sys_get_temp_dir().'/kx-release-'.bin2hex(random_bytes(6));
        exec('git clone --quiet --no-hardlinks '.escapeshellarg($this->checkout()).' '.escapeshellarg($root).' 2>&1', $output, $code);
        self::assertSame(0, $code, implode("\n", $output));

        try {
            $build = static function () use ($root): string {
                exec('php '.escapeshellarg($root.'/scripts/release/build.php').' '.Protocol::MODULE_VERSION.' staging kx-test-1:'.base64_encode(str_repeat('k', 32)).' '.escapeshellarg($root.'/k.zip').' 2>&1', $printed, $code);

                return $code.' '.implode("\n", $printed);
            };
            $clean = $build();
            self::assertStringStartsWith('0 ', $clean);
            $composer = (string) file_get_contents($root.'/composer.json');
            file_put_contents($root.'/composer.json', str_replace('">=7.4"', '">=8.1"', $composer, $replaced));
            self::assertSame(1, $replaced);
            self::assertSame($clean, $build(), 'an uncommitted composer.json changed the zip');
        } finally {
            exec('rm -rf '.escapeshellarg($root));
        }
    }

    public function test_the_channel_decides_the_host_and_the_first_key_is_pinned(): void
    {
        $files = $this->files();
        foreach (['staging' => 'https://connect-staging.kronixon.com', 'stable' => 'https://connect.kronixon.com'] as $channel => $host) {
            $protocol = $this->entries($this->build($channel))['kronixonconnect/src/Protocol.php'];
            self::assertStringContainsString("public const DEFAULT_PLATFORM_URL = '$host';", $protocol);
            self::assertStringContainsString("public const PINNED_PLATFORM_KEY = '".base64_encode($this->publicKey())."';", $protocol);
            // The module checks its own updates with the channel's keys, in the order given (Update\ReleaseKeys).
            self::assertStringContainsString("public const CHANNEL = '$channel';", $protocol);
            self::assertStringContainsString("public const RELEASE_KEYS = '".$this->keyList()."';", $protocol);
        }
        $rotation = $this->keyList().',kx-next:'.base64_encode(str_repeat('n', 32));
        $protocol = $this->entries(ReleaseBuilder::build($files, '7.4.0', Protocol::MODULE_VERSION, 'staging', $rotation))['kronixonconnect/src/Protocol.php'];
        self::assertStringContainsString("public const RELEASE_KEYS = '".$rotation."';", $protocol);
        // Nothing else of the module changes.
        $entries = $this->entries($this->build());
        foreach ($files as $path => $contents) {
            if ($path !== 'src/Protocol.php') {
                self::assertSame($contents, $entries['kronixonconnect/'.$path], $path);
            }
        }
        self::assertSame('', Protocol::PINNED_PLATFORM_KEY, 'the source pins no key');
        self::assertSame('', Protocol::CHANNEL, 'the source belongs to no channel');
        self::assertSame('', Protocol::RELEASE_KEYS, 'the source trusts no release key');
    }

    public function test_the_manifest_names_the_release_and_every_file(): void
    {
        $entries = $this->entries($this->build());
        $manifest = json_decode($entries['kronixonconnect/kx-manifest.json'], true);
        unset($entries['kronixonconnect/kx-manifest.json']);

        $files = $manifest['files'];
        unset($manifest['files']);
        self::assertSame([
            'manifest' => 1, 'module' => 'kronixonconnect', 'platform' => 'prestashop', 'version' => Protocol::MODULE_VERSION,
            'channel' => 'staging', 'protocol' => 'v1', 'min_platform' => '1.7.8.0', 'max_platform' => '9.99.99', 'min_php' => '7.4.0',
            'api_host' => 'https://connect-staging.kronixon.com',
            'release_keys' => [['key_id' => 'kx-test-1', 'public_key' => base64_encode($this->publicKey())]],
        ], $manifest);
        $hashes = [];
        foreach ($entries as $path => $contents) {
            $hashes[substr($path, strlen('kronixonconnect/'))] = hash('sha256', $contents);
        }
        self::assertSame($hashes, $files);
    }

    public function test_it_builds_only_the_version_the_module_is(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not 9.9.9');
        ReleaseBuilder::build($this->files(), '7.4.0', '9.9.9', 'staging', $this->keyList());
    }

    public function test_it_refuses_a_version_config_xml_does_not_have(): void
    {
        $files = $this->files();
        $files['config.xml'] = str_replace(Protocol::MODULE_VERSION, '0.0.1', $files['config.xml']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('config.xml');
        $this->buildOf($files);
    }

    public function test_it_refuses_a_channel_or_keys_that_are_none(): void
    {
        foreach ([['beta', $this->keyList()], ['staging', ''], ['staging', 'no-key'], ['staging', 'kx:'.base64_encode('short')], ['staging', $this->keyList().','.$this->keyList()], ['staging', "kx';phpinfo();//:".base64_encode($this->publicKey())]] as [$channel, $keys]) {
            try {
                ReleaseBuilder::build($this->files(), '7.4.0', Protocol::MODULE_VERSION, $channel, $keys);
                self::fail("accepted $channel / $keys");
            } catch (RuntimeException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_the_signature_is_over_the_zip_by_the_pinned_key(): void
    {
        $zip = $this->build();
        $signature = base64_decode(trim(ReleaseBuilder::sign($zip, $this->secretKey(), $this->keyList())), true);

        self::assertIsString($signature);
        self::assertTrue(sodium_crypto_sign_verify_detached($signature, $zip, $this->publicKey()));
        self::assertFalse(sodium_crypto_sign_verify_detached($signature, $zip.'x', $this->publicKey()));
    }

    public function test_it_signs_with_no_other_key_than_the_one_the_build_pins(): void
    {
        $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(hash('sha256', 'another', true)));
        $second = 'kx-other:'.base64_encode(sodium_crypto_sign_publickey_from_secretkey($other));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not the first public key');
        ReleaseBuilder::sign('zip', $other, $this->keyList().','.$second);
    }

    public function test_it_reads_the_secret_in_every_form_the_guide_names(): void
    {
        $seed = hash('sha256', self::SEED, true);
        $der = "\x30\x2e\x02\x01\x00\x30\x05\x06\x03\x2b\x65\x70\x04\x22\x04\x20".$seed;
        $pem = '-----BEGIN PRIVATE KEY-----'."\n".base64_encode($der)."\n".'-----END PRIVATE KEY-----'."\n";

        self::assertSame($this->secretKey(), ReleaseBuilder::secretKey(base64_encode($this->secretKey())."\n"));
        self::assertSame($this->secretKey(), ReleaseBuilder::secretKey(base64_encode($seed)));
        self::assertSame($this->secretKey(), ReleaseBuilder::secretKey($pem));
        self::assertSame($this->secretKey(), ReleaseBuilder::secretKey(str_replace("\n", "\r\n", $pem)));
    }

    public function test_it_refuses_anything_else_as_a_secret_and_never_repeats_it(): void
    {
        $mixed = substr($this->secretKey(), 0, 32).str_repeat('x', 32);
        foreach (['', 'hunter2', base64_encode('short'), base64_encode($mixed), "-----BEGIN PRIVATE KEY-----\n".base64_encode(str_repeat('k', 48))."\n-----END PRIVATE KEY-----"] as $pasted) {
            try {
                ReleaseBuilder::secretKey($pasted);
                self::fail('accepted a secret that is none');
            } catch (RuntimeException $e) {
                if ($pasted !== '') {
                    self::assertStringNotContainsString($pasted, $e->getMessage());
                }
            }
        }
    }

    /** The checkout's path when git can read it: the plain PHP images of `make test` have no git, CI has. */
    private function checkout(): string
    {
        $root = dirname(__DIR__, 3);
        exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>&1', $output, $code);
        if ($code !== 0) {
            self::assertFalse(getenv('CI'), 'CI must have git: '.implode("\n", $output));
            self::markTestSkipped('needs git');
        }

        return $root;
    }

    private function build(string $channel = 'staging'): string
    {
        return $this->buildOf($this->files(), $channel);
    }

    /** @param array<string, string> $files */
    private function buildOf(array $files, string $channel = 'staging'): string
    {
        return ReleaseBuilder::build($files, ReleaseBuilder::minPhp((string) file_get_contents(dirname(__DIR__, 3).'/composer.json')), Protocol::MODULE_VERSION, $channel, $this->keyList());
    }

    /** @return array<string, string> the module's files of the work tree (the build itself takes them from git) */
    private function files(): array
    {
        $root = dirname(__DIR__, 3);
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

        return $files;
    }

    /** @return array<string, string> the zip's central directory read back: path => contents, each crc checked */
    private function entries(string $zip): array
    {
        $end = unpack('vdisk/vstart/ventries/vtotal/Vsize/Voffset/vcomment', substr($zip, -18));
        self::assertIsArray($end);
        self::assertSame("PK\x05\x06", substr($zip, -22, 4));
        $entries = [];
        $at = $end['offset'];
        for ($i = 0; $i < $end['total']; $i++) {
            self::assertSame("PK\x01\x02", substr($zip, $at, 4));
            $head = unpack('vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vpacked/Vsize/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Voffset', substr($zip, $at + 4, 42));
            self::assertIsArray($head);
            $name = substr($zip, $at + 46, $head['name']);
            self::assertSame(0, $head['method']);
            self::assertSame(0100644 << 16, $head['external']);
            $contents = substr($zip, $head['offset'] + 30 + $head['name'], $head['size']);
            self::assertSame($name, substr($zip, $head['offset'] + 30, $head['name']));
            self::assertSame($head['crc'], crc32($contents));
            $entries[$name] = $contents;
            $at += 46 + $head['name'];
        }
        self::assertSame($end['offset'] + $end['size'], $at);

        return $entries;
    }

    private function keyList(): string
    {
        return 'kx-test-1:'.base64_encode($this->publicKey());
    }

    private function publicKey(): string
    {
        return sodium_crypto_sign_publickey_from_secretkey($this->secretKey());
    }

    private function secretKey(): string
    {
        return sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(hash('sha256', self::SEED, true)));
    }
}
