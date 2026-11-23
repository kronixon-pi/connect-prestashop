<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Write;

use Kronixon\Connect\Write\Download;
use Kronixon\Connect\Write\ItemError;
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
            return ['files.example.test' => ['203.0.113.7'], 'connect.example.test' => ['198.51.100.9', '10.0.0.5']][$host] ?? [];
        });

        self::assertSame('files.example.test:443:203.0.113.7', $download->check('https://Files.Example.test/p/1.jpg?X-Amz-Signature=a', 'url'));
        self::assertSame('files.example.test:8443:203.0.113.7', $download->check('https://files.example.test:8443/p/1.jpg', 'url'));
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

    public function test_without_hosts_nothing_is_fetched(): void
    {
        $this->expectException(ItemError::class);
        (new Download(true))->check('http://127.0.0.1/1.jpg', 'logo');
    }
}
