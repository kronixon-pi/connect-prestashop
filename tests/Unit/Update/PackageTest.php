<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Update;

use Kronixon\Connect\Protocol;
use Kronixon\Connect\Tests\Support\Releases;
use Kronixon\Connect\Update\Package;
use Kronixon\Connect\Update\Release;
use Kronixon\Connect\Update\ReleaseKeys;
use Kronixon\Connect\Update\UpdateError;
use Kronixon\Connect\Update\ZipReader;
use PHPUnit\Framework\TestCase;

/**
 * The checks between a downloaded release zip and the files that replace the module (Spec §10 item 3).
 */
final class PackageTest extends TestCase
{
    private const HOST = 'https://connect-staging.kronixon.com';

    /** @var array<string, string> version => zip, built once per test run */
    private static $zips = [];

    public function test_a_signed_newer_release_of_this_channel_opens_into_the_module_files(): void
    {
        $zip = self::zip('0.9.0');

        $files = $this->open($zip, Releases::latest($zip, '0.9.0'));

        self::assertArrayHasKey('kronixonconnect.php', $files);
        self::assertArrayHasKey('kx-manifest.json', $files);
        self::assertStringContainsString("MODULE_VERSION = '0.9.0';", $files['src/Protocol.php']);
        self::assertStringContainsString("CHANNEL = 'staging';", $files['src/Protocol.php']);
        self::assertStringContainsString("RELEASE_KEYS = '".Releases::keyList()."';", $files['src/Protocol.php']);
        self::assertSame(Releases::files('0.9.0')['config.xml'], $files['config.xml']);
    }

    public function test_a_changed_byte_is_refused_by_its_hash_and_by_its_signature_when_the_hash_follows_it(): void
    {
        $zip = self::zip('0.9.0');
        $at = strpos($zip, 'class KronixonConnect extends Module');
        self::assertIsInt($at);
        $tampered = substr_replace($zip, 'C', $at, 1);
        self::assertNotSame($zip, $tampered);

        $this->assertRefused('sha256_mismatch', $tampered, Releases::latest($zip, '0.9.0'));
        // An answer that names the tampered bytes' hash still carries the signature of the real ones.
        $this->assertRefused('bad_signature', $tampered, Releases::latest($tampered, '0.9.0', ['signature' => Releases::sign($zip)]));
        $this->assertRefused('size_mismatch', $zip.'x', Releases::latest($zip, '0.9.0'));
    }

    public function test_only_a_key_built_into_the_module_counts(): void
    {
        $zip = self::zip('0.9.0');

        // Signed by a key of the same name that is not the built-in one.
        $this->assertRefused('bad_signature', $zip, Releases::latest($zip, '0.9.0', ['signature' => Releases::sign($zip, 'an attacker')]));
        $this->assertRefused('unknown_key', $zip, Releases::latest($zip, '0.9.0', ['signature_key_id' => 'kx-other']));
        // During a rotation the next key verifies as well; it is the one the answer names.
        $next = 'kx-next:'.base64_encode(Releases::publicKey('the next key'));
        $keys = new ReleaseKeys('staging', 'kx-test-1:'.base64_encode(Releases::publicKey('the key before')).','.$next);
        $files = Package::open($zip, Release::fromAnswer(Releases::latest($zip, '0.9.0', ['signature' => Releases::sign($zip, 'the next key'), 'signature_key_id' => 'kx-next'])), $keys, Protocol::MODULE_VERSION, self::HOST, '9.1.5', '8.4.0');
        self::assertArrayHasKey('kx-manifest.json', $files);
    }

    public function test_a_release_of_another_channel_is_refused_whatever_the_answer_says(): void
    {
        $stable = Releases::build('0.9.0', 'stable');

        $this->assertRefused('channel_mismatch', $stable, Releases::latest($stable, '0.9.0', ['channel' => 'stable']));
        // The answer claims staging; the zip's manifest says stable.
        $this->assertRefused('channel_mismatch', $stable, Releases::latest($stable, '0.9.0'));
        // A stable build (another API host built in) never installs a staging zip either.
        $zip = self::zip('0.9.0');
        $this->expectRefusal('bad_package', static function () use ($zip): void {
            Package::open($zip, Release::fromAnswer(Releases::latest($zip, '0.9.0')), Releases::keys(), Protocol::MODULE_VERSION, 'https://connect.kronixon.com', '9.1.5', '8.4.0');
        });
    }

    public function test_the_same_or_an_older_version_is_never_installed(): void
    {
        foreach (['0.7.0', '0.6.9', '0.1.0'] as $version) {
            $zip = self::zip($version);
            $this->assertRefused('not_newer', $zip, Releases::latest($zip, $version));
        }
        // The answer names a newer version than the zip holds.
        $zip = self::zip('0.9.0');
        $this->assertRefused('bad_package', $zip, Releases::latest($zip, '0.9.1'));
    }

    public function test_the_release_must_fit_this_prestashop_and_php(): void
    {
        $zip = self::zip('0.9.0');
        $release = Release::fromAnswer(Releases::latest($zip, '0.9.0'));

        $this->expectRefusal('php_too_old', static function () use ($zip, $release): void {
            Package::open($zip, $release, Releases::keys(), Protocol::MODULE_VERSION, self::HOST, '9.1.5', '7.3.33');
        });
        $this->expectRefusal('platform_unsupported', static function () use ($zip, $release): void {
            Package::open($zip, $release, Releases::keys(), Protocol::MODULE_VERSION, self::HOST, '10.0.0', '8.4.0');
        });
        $this->expectRefusal('platform_unsupported', static function () use ($zip, $release): void {
            Package::open($zip, $release, Releases::keys(), Protocol::MODULE_VERSION, self::HOST, '1.7.7.8', '7.4.33');
        });
    }

    public function test_a_signed_zip_whose_files_differ_from_its_manifest_is_refused(): void
    {
        $zip = self::zip('0.9.0');
        $entries = [];
        foreach (ZipReader::entries($zip) as $name => $contents) {
            $entries[substr($name, strlen('kronixonconnect/'))] = $contents;
        }
        $cases = [
            'an extra file' => $entries + ['src/Extra.php' => '<?php'],
            'a file missing' => array_diff_key($entries, ['src/Services.php' => true]),
            'a file changed' => ['src/Services.php' => '<?php // other'] + $entries,
            'a path outside the folder' => $entries + ['../../index.php' => '<?php'],
            'a hidden file' => $entries + ['.htaccess' => 'Allow from all'],
            'no manifest' => array_diff_key($entries, ['kx-manifest.json' => true]),
        ];
        foreach ($cases as $case => $files) {
            $crafted = Releases::zip($files);
            try {
                // Signed with the right key: only the package checks stand between it and the shop.
                $this->open($crafted, Releases::latest($crafted, '0.9.0'));
                self::fail('accepted '.$case);
            } catch (UpdateError $error) {
                self::assertSame('bad_package', $error->code(), $case);
            }
        }
    }

    public function test_the_zip_reader_takes_only_what_the_release_build_writes(): void
    {
        $zip = Releases::zip(['a.php' => 'A', 'b/c.php' => 'BC']);
        self::assertSame(['kronixonconnect/a.php' => 'A', 'kronixonconnect/b/c.php' => 'BC'], ZipReader::entries($zip));

        $deflated = substr_replace($zip, "\x08\x00", 8, 2);
        $linkMode = strpos($zip, "PK\x01\x02") + 38;
        $cases = [
            'compressed' => $deflated,
            'a symbolic link' => substr_replace($zip, pack('V', 0120777 << 16), $linkMode, 4),
            'cut short' => substr($zip, 0, -30),
            'a comment' => substr_replace($zip, "\x01\x00", -2, 2).'x',
            'a changed byte' => substr_replace($zip, 'X', strpos($zip, 'BC'), 1),
            'nothing' => '',
        ];
        foreach ($cases as $case => $bytes) {
            try {
                ZipReader::entries($bytes);
                self::fail('read '.$case);
            } catch (UpdateError $error) {
                self::assertSame('bad_package', $error->code(), $case);
            }
        }
    }

    public function test_a_development_checkout_has_no_keys_and_installs_nothing(): void
    {
        self::assertSame('', Protocol::RELEASE_KEYS);
        self::assertFalse(ReleaseKeys::builtIn()->available());
        self::assertFalse((new ReleaseKeys('beta', Releases::keyList()))->available());
        $zip = self::zip('0.9.0');

        $this->expectRefusal('no_release_keys', static function () use ($zip): void {
            Package::open($zip, Release::fromAnswer(Releases::latest($zip, '0.9.0')), ReleaseKeys::builtIn(), Protocol::MODULE_VERSION, self::HOST, '9.1.5', '8.4.0');
        });
    }

    public function test_an_answer_with_a_malformed_field_names_no_release(): void
    {
        $zip = self::zip('0.9.0');
        foreach (['version' => '0.9', 'channel' => 'beta', 'url' => 'ftp://x', 'sha256' => 'ABC', 'signature' => 'short', 'signature_key_id' => 'Bad Id', 'size' => 0] as $field => $value) {
            try {
                Release::fromAnswer(Releases::latest($zip, '0.9.0', [$field => $value]));
                self::fail('accepted '.$field);
            } catch (UpdateError $error) {
                self::assertSame('bad_answer', $error->code(), $field);
            }
        }
    }

    private static function zip(string $version): string
    {
        return self::$zips[$version] = self::$zips[$version] ?? Releases::build($version);
    }

    /**
     * @param  array<string, mixed>  $latest
     * @return array<string, string>
     */
    private function open(string $zip, array $latest): array
    {
        return Package::open($zip, Release::fromAnswer($latest), Releases::keys(), Protocol::MODULE_VERSION, self::HOST, '9.1.5', '8.4.0');
    }

    /**
     * @param  array<string, mixed>  $latest
     */
    private function assertRefused(string $code, string $zip, array $latest): void
    {
        $this->expectRefusal($code, function () use ($zip, $latest): void {
            $this->open($zip, $latest);
        });
    }

    private function expectRefusal(string $code, callable $open): void
    {
        try {
            $open();
            self::fail('no refusal, expected '.$code);
        } catch (UpdateError $error) {
            self::assertSame($code, $error->code(), $error->getMessage());
            self::assertContains($error->kind(), [UpdateError::REFUSED, UpdateError::SERVER]);
        }
    }
}
