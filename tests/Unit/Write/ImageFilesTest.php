<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Write;

use Kronixon\Connect\Write\ImageFiles;
use PHPUnit\Framework\TestCase;

/**
 * The pixel cap is read from the image's header, before anything decodes it: a file of a few hundred
 * bytes that announces 20 000 × 20 000 pixels is refused whatever `memory_limit` is.
 */
final class ImageFilesTest extends TestCase
{
    public function test_a_picture_of_more_than_50_megapixels_is_too_large_by_its_header(): void
    {
        $previous = (string) ini_get('memory_limit');
        ini_set('memory_limit', '-1');
        try {
            foreach ([[20000, 20000, true], [7071, 7071, false], [7072, 7072, true], [1, 1, false], [50000001, 1, true]] as [$width, $height, $tooLarge]) {
                $file = self::png($width, $height);
                self::assertLessThan(200, (int) filesize($file), 'a small file');
                self::assertSame($tooLarge, ImageFiles::tooLarge($file), $width.'×'.$height);
                unlink($file);
            }
        } finally {
            ini_set('memory_limit', $previous);
        }
        self::assertFalse(ImageFiles::tooLarge(__FILE__), 'not an image: left to accepts()');
    }

    /** A PNG whose IHDR announces $width × $height (its data is not there: nothing may decode it). */
    private static function png(int $width, int $height): string
    {
        $chunk = static function (string $type, string $body): string {
            return pack('N', strlen($body)).$type.$body.pack('N', crc32($type.$body));
        };
        $file = (string) tempnam(sys_get_temp_dir(), 'kxpng');
        file_put_contents($file, "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 3, 0, 0, 0)).$chunk('PLTE', "\x01\x02\x03").$chunk('IEND', ''));

        return $file;
    }
}
