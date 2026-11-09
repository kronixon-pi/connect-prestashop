<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Catalog;

use InvalidArgumentException;
use Kronixon\Connect\Catalog\ContentHash;
use Normalizer;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * `content_hash` against the vectors in content-hash-vectors.json (also checked against the platform's
 * CanonicalPayload), on every PHP the suite runs on.
 */
final class ContentHashTest extends TestCase
{
    /**
     * @return iterable<string, array{stdClass}>
     */
    public function vectors(): iterable
    {
        $file = json_decode((string) file_get_contents(__DIR__.'/content-hash-vectors.json'), false, 512, JSON_THROW_ON_ERROR);
        foreach ($file->vectors as $vector) {
            yield $vector->name => [$vector];
        }
    }

    /**
     * @dataProvider vectors
     */
    public function test_vector(stdClass $vector): void
    {
        $item = self::item($vector->item);

        self::assertSame($vector->json, ContentHash::json($item));
        self::assertSame($vector->sha256, ContentHash::of($item));
    }

    /**
     * @requires extension intl
     *
     * @dataProvider vectors
     */
    public function test_decomposed_text_hashes_like_composed_text(stdClass $vector): void
    {
        if (empty($vector->decompose)) {
            self::assertTrue(true);

            return;
        }
        $nfd = json_decode((string) Normalizer::normalize((string) json_encode($vector->item, JSON_UNESCAPED_UNICODE), Normalizer::FORM_D), false);
        self::assertNotSame(json_encode($vector->item, JSON_UNESCAPED_UNICODE), json_encode($nfd, JSON_UNESCAPED_UNICODE), 'the input really is decomposed');

        self::assertSame($vector->sha256, ContentHash::of(self::item($nfd)));
    }

    public function test_floats_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContentHash::of(['price' => 1.5]);
    }

    /**
     * The decoded vector item as a reader builds it: maps as arrays, an empty map as stdClass.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private static function item($value)
    {
        if ($value instanceof stdClass) {
            $vars = get_object_vars($value);
            if ($vars === []) {
                return new stdClass();
            }

            return array_map([self::class, 'item'], $vars);
        }

        return is_array($value) ? array_map([self::class, 'item'], $value) : $value;
    }
}
