<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Compat;

use Kronixon\Connect\Compat\Ps178;
use Kronixon\Connect\Compat\Ps80;
use Kronixon\Connect\Compat\Ps81;
use Kronixon\Connect\Compat\Ps9;
use Kronixon\Connect\Compat\UnsupportedVersion;
use Kronixon\Connect\Compat\Version;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, class-string, string}>
     */
    public function supported(): iterable
    {
        yield 'matrix 1.7.8.11' => ['1.7.8.11', Ps178::class, 'ps178'];
        yield '1.7.8.0' => ['1.7.8.0', Ps178::class, 'ps178'];
        yield '8.0.5 (not in the matrix)' => ['8.0.5', Ps80::class, 'ps80'];
        yield 'matrix 8.1.7' => ['8.1.7', Ps81::class, 'ps81'];
        yield 'matrix 8.2.8' => ['8.2.8', Ps81::class, 'ps81'];
        yield 'matrix 9.0.3' => ['9.0.3', Ps9::class, 'ps9'];
        yield 'matrix 9.1.5' => ['9.1.5', Ps9::class, 'ps9'];
        yield 'non-blocking 9.2.0' => ['9.2.0', Ps9::class, 'ps9'];
    }

    /**
     * @dataProvider supported
     *
     * @param  class-string  $class
     */
    public function test_selection(string $version, string $class, string $id): void
    {
        $compat = Version::select($version);

        self::assertInstanceOf($class, $compat);
        self::assertSame($id, $compat->id());
        self::assertTrue(Version::isSupported($version));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function unsupported(): iterable
    {
        yield '1.7.7.8' => ['1.7.7.8'];
        yield '1.6.1.24' => ['1.6.1.24'];
        yield '10.0.0' => ['10.0.0'];
        yield 'garbage' => ['nine'];
        yield 'empty' => [''];
        yield 'suffix' => ['9.1.5-beta'];
    }

    /**
     * @dataProvider unsupported
     */
    public function test_unsupported(string $version): void
    {
        self::assertFalse(Version::isSupported($version));
        $this->expectException(UnsupportedVersion::class);
        Version::select($version);
    }

    public function test_version_facts(): void
    {
        $ps178 = Version::select('1.7.8.11');
        $ps81 = Version::select('8.1.7');

        self::assertSame('Attribute', $ps178->attributeClass());
        self::assertSame('ProductAttribute', $ps81->attributeClass());
        self::assertSame(['actionObjectAttributeAddAfter'], $ps178->versionHooks());
        self::assertSame(['actionObjectProductAttributeAddAfter'], Version::select('9.1.5')->versionHooks());
        self::assertNotContains('410', $ps178->redirectTypes());
        self::assertContains('410', Version::select('8.0.0')->redirectTypes());
        self::assertFalse(Version::select('8.0.5')->hasImageFormatSetting());
        self::assertTrue($ps81->hasImageFormatSetting());
    }
}
