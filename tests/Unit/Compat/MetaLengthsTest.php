<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Compat;

use InvalidArgumentException;
use Kronixon\Connect\Compat\CompatInterface;
use Kronixon\Connect\Compat\MetaLengths;
use PHPUnit\Framework\TestCase;

/**
 * The `_lang` columns of install/data/db_structure.sql, checked in the 1.7.8.11, 8.1.7 and 9.1.5
 * images: the same on every version, so no Compat profile carries them.
 */
final class MetaLengthsTest extends TestCase
{
    public function test_product_meta_title_is_the_128_character_column(): void
    {
        self::assertSame(['meta_title' => 128, 'meta_description' => 512], MetaLengths::of('product'));
    }

    public function test_category(): void
    {
        self::assertSame(['meta_title' => 255, 'meta_description' => 512], MetaLengths::of('category'));
    }

    public function test_brand(): void
    {
        self::assertSame(['meta_title' => 255, 'meta_description' => 512], MetaLengths::of('brand'));
    }

    public function test_supplier(): void
    {
        self::assertSame(['meta_title' => 255, 'meta_description' => 512], MetaLengths::of('supplier'));
    }

    public function test_cms(): void
    {
        self::assertSame(['meta_title' => 255, 'meta_description' => 512], MetaLengths::of('cms'));
    }

    public function test_exactly_these_entities(): void
    {
        self::assertSame(['product', 'category', 'brand', 'supplier', 'cms'], array_keys(MetaLengths::BY_ENTITY));
    }

    public function test_no_version_profile_carries_meta_lengths(): void
    {
        self::assertFalse(method_exists(CompatInterface::class, 'metaLengths'));
    }

    public function test_an_unknown_entity_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MetaLengths::of('order');
    }
}
