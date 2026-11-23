<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Write;

use Kronixon\Connect\Write\Download;
use Kronixon\Connect\Write\ItemError;
use Kronixon\Connect\Write\PublicAddress;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * What a logo or an image may be fetched from: only the platform's hosts, over HTTPS, at public
 * addresses, and the connection pinned to the address that was checked (no network in this test).
 */
final class DownloadTest extends TestCase
{
    public function test_only_a_host_of_the_platform_at_a_public_address(): void
    {
        $download = new Download(false, ['connect.example.test', 'files.example.test', 'files.example.test:8443'], static function (string $host): array {
            return ['files.example.test' => ['151.101.1.7'], 'connect.example.test' => ['93.184.215.9', '10.0.0.5']][$host] ?? [];
        });

        self::assertSame('files.example.test:443:151.101.1.7', $download->check('https://Files.Example.test/p/1.jpg?X-Amz-Signature=a', 'url'));
        self::assertSame('files.example.test:8443:151.101.1.7', $download->check('https://files.example.test:8443/p/1.jpg', 'url'));
        foreach ([
            'https://files.example.test:9443/p/1.jpg' => 'download_host_refused',
            'https://shop.example.test/img/1.jpg' => 'download_host_refused',
            'https://connect.example.test/dl/1.jpg' => 'download_host_refused',
            'http://files.example.test/p/1.jpg' => 'validation_failed',
            'https://user:pass@files.example.test/p/1.jpg' => 'validation_failed',
            'ftp://files.example.test/p/1.jpg' => 'validation_failed',
            'https://127.0.0.1/p/1.jpg' => 'download_host_refused',
        ] as $url => $code) {
            try {
                $download->check($url, 'url');
                self::fail('accepted '.$url);
            } catch (ItemError $error) {
                self::assertSame([$code, 'url.url'], [$error->itemCode(), $error->field()], $url);
            }
        }
    }

    public function test_a_platform_host_given_as_a_private_address_is_refused_outside_development(): void
    {
        $download = new Download(false, ['127.0.0.1:443', 'files.example.test'], static function (): array {
            return [];
        });
        foreach (['https://127.0.0.1/p/1.jpg' => 'download_host_refused', 'https://files.example.test/p/1.jpg' => 'download_failed'] as $url => $code) {
            try {
                $download->check($url, 'url');
                self::fail('accepted '.$url);
            } catch (ItemError $error) {
                self::assertSame($code, $error->itemCode(), $url);
            }
        }

        $development = new Download(true, ['127.0.0.1:8099']);
        self::assertNull($development->check('http://127.0.0.1:8099/files/1.jpg', 'url'), 'KX_PLATFORM_ALLOW_HTTP: the local platform, not pinned');
        foreach (['http://localhost:8099/files/1.jpg', 'http://127.0.0.1/img/1.jpg'] as $url) {
            try {
                $development->check($url, 'url');
                self::fail('accepted '.$url);
            } catch (ItemError $error) {
                self::assertSame('download_host_refused', $error->itemCode(), 'another host, or the same host on another port (the shop itself)');
            }
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function addresses(): iterable
    {
        // Every IPv4 block of the table: its first and last address are refused, the neighbours outside
        // it that are public are taken.
        foreach (array_keys(PublicAddress::IPV4) as $block) {
            [$network, $bits] = explode('/', $block);
            $first = (int) ip2long($network);
            $last = $first + (1 << (32 - (int) $bits)) - 1;
            yield $block.' first' => [(string) long2ip($first), false];
            yield $block.' last' => [(string) long2ip($last), false];
            foreach ([$first - 1, $last + 1] as $outside) {
                if ($outside >= 0 && $outside <= 0xFFFFFFFF && PublicAddress::refusal((string) long2ip($outside)) === null) {
                    yield $block.' outside '.long2ip($outside) => [(string) long2ip($outside), true];
                }
            }
        }
        // Every IPv6 block: its first address and one inside.
        foreach (array_keys(PublicAddress::IPV6) as $block) {
            $network = explode('/', $block)[0];
            yield $block.' first' => [$network, false];
            yield $block.' inside' => [(string) inet_ntop(substr((string) inet_pton($network), 0, 15).chr(1)), false];
        }
        foreach ([
            '255.255.255.255' => false, '100.100.100.200' => false, '169.254.169.254' => false, '100.63.255.255' => true,
            '100.128.0.0' => true, '8.8.8.8' => true,
            '::' => false, '::1' => false, '::127.0.0.1' => false, '::10.0.0.1' => false,
            '::ffff:127.0.0.1' => false, '::ffff:10.0.0.1' => false, '::ffff:100.64.0.1' => false, '::ffff:169.254.169.254' => false,
            '::ffff:198.18.0.1' => false, '::ffff:224.0.0.1' => false, '::ffff:7f00:1' => false, '::ffff:8.8.8.8' => true,
            '64:ff9b::7f00:1' => false, '64:ff9b::808:808' => false, '2002:7f00:1::' => false, '2001:0:4136:e378::1' => false,
            'fd00::1' => false, 'fe80::1' => false, 'ff02::1' => false, '4000::1' => false, '1fff:ffff::1' => false,
            '2606:4700:4700::1111' => true, '2a00:1450:4001:80b::200e' => true, '3ffe::1' => true,
        ] as $ip => $public) {
            yield 'address '.$ip => [(string) $ip, $public];
        }
    }

    /**
     * @dataProvider addresses
     */
    public function test_only_public_addresses_by_the_table(string $ip, bool $public): void
    {
        self::assertSame($public, PublicAddress::refusal($ip) === null, $ip.': '.(PublicAddress::refusal($ip) ?? 'public'));

        // What a platform host resolves to.
        $download = new Download(false, ['files.example.test'], static function () use ($ip): array {
            return [$ip];
        });
        try {
            self::assertSame('files.example.test:443:'.$ip, $download->check('https://files.example.test/p/1.jpg', 'url'));
            self::assertTrue($public, $ip.' accepted');
        } catch (ItemError $error) {
            self::assertSame([false, 'download_host_refused'], [$public, $error->itemCode()], $ip);
        }
    }

    public function test_a_literal_address_of_the_platform_is_judged_by_the_table_and_not_pinned(): void
    {
        $download = new Download(false, ['::ffff:127.0.0.1:443', '8.8.4.4:443'], static function (): array {
            throw new LogicException('a literal address is not looked up');
        });
        self::assertNull($download->check('https://8.8.4.4/p/1.jpg', 'url'));
        try {
            $download->check('https://[::ffff:127.0.0.1]/p/1.jpg', 'url');
            self::fail('accepted ::ffff:127.0.0.1');
        } catch (ItemError $error) {
            self::assertSame('download_host_refused', $error->itemCode());
        }
        self::assertNotNull(PublicAddress::refusal('not-an-ip'));
    }

    public function test_a_pairing_before_0_5_0_says_pair_again_for_a_host_it_does_not_know(): void
    {
        $old = new Download(false, ['connect.example.test'], static function (): array {
            return ['8.8.8.8'];
        }, false);
        self::assertSame('connect.example.test:443:8.8.8.8', $old->check('https://connect.example.test/dl/1.jpg', 'url'), 'the Connect host still serves files');
        try {
            $old->check('https://files.example.test/p/1.jpg', 'url');
            self::fail('accepted a host of no pairing');
        } catch (ItemError $error) {
            self::assertSame(['download_hosts_unknown', 'url.url'], [$error->itemCode(), $error->field()]);
            self::assertStringContainsString('pair it again', $error->getMessage());
        }
    }

    public function test_without_hosts_nothing_is_fetched(): void
    {
        $this->expectException(ItemError::class);
        (new Download(true))->check('http://127.0.0.1/1.jpg', 'logo');
    }
}
