<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Write;

use Kronixon\Connect\Redirect\RedirectStore;
use Kronixon\Connect\Tests\Support\CatalogDb;
use Kronixon\Connect\Tests\Support\MemorySettings;
use PHPUnit\Framework\TestCase;

/**
 * kx_redirect: paths as they are kept and compared, per shop.
 */
final class RedirectStoreTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public function paths(): iterable
    {
        yield 'a URL' => ['https://shop.example.com:8443/bg/45-old?x=1#top', '/bg/45-old'];
        yield 'a request URI' => ['/bg/45-old?utm=1', '/bg/45-old'];
        yield 'encoded' => ['/bg/%D0%BE%D0%B1%D1%83%D0%B2%D0%BA%D0%B8', '/bg/обувки'];
        yield 'no path' => ['https://shop.example.com', null];
        yield 'relative' => ['bg/45-old', null];
        yield 'not UTF-8' => ['/%FF', null];
        yield 'a control character' => ['/a%0Ab', null];
        yield '191 characters' => ['/'.str_repeat('я', 190), '/'.str_repeat('я', 190)];
        yield '192 characters' => ['/'.str_repeat('я', 191), null];
    }

    /**
     * @dataProvider paths
     */
    public function test_a_path_is_decoded_and_bounded(string $url, ?string $path): void
    {
        self::assertSame($path, RedirectStore::path($url));
    }

    public function test_put_find_replace_remove_per_shop(): void
    {
        $db = new CatalogDb();
        $settings = new MemorySettings();
        $one = new RedirectStore($db, $settings, 1);
        $two = new RedirectStore($db, $settings, 2);
        self::assertFalse($one->any());

        self::assertSame('created', $one->put('/old', 'product', 5, 301));
        self::assertTrue($one->any());
        self::assertSame('unchanged', $one->put('/old', 'product', 5, 301));
        self::assertSame('updated', $one->put('/old', 'category', 6, 302));
        self::assertSame(['target_type' => 'category', 'target_id' => 6, 'code' => 302], $one->find('/old'));
        self::assertNull($two->find('/old'), 'another shop');
        self::assertFalse($two->remove(['/old']));
        self::assertTrue($one->remove(['/old', '/never']));
        self::assertNull($one->find('/old'));
    }

    public function test_a_changed_address_keeps_leading_to_its_page_without_a_loop(): void
    {
        $store = new RedirectStore(new CatalogDb(), new MemorySettings(), 1);
        $store->keep('https://s/a.html', 'https://s/b.html', 'product', 5);
        self::assertSame(['target_type' => 'product', 'target_id' => 5, 'code' => 301], $store->find('/a.html'));

        $store->keep('https://s/b.html', 'https://s/a.html', 'product', 5);
        self::assertNull($store->find('/a.html'), 'the page is back at this address');
        self::assertNotNull($store->find('/b.html'));
        $store->keep('https://s/c.html?x', 'https://s/c.html', 'product', 5);
        self::assertNull($store->find('/c.html'), 'nothing changed');
    }
}
