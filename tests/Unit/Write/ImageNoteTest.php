<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Write;

use Kronixon\Connect\Write\ImageNote;
use PHPUnit\Framework\TestCase;

/**
 * The hash of the file a stored picture was made of.
 */
final class ImageNoteTest extends TestCase
{
    private const SHA = '6a8d1ab4cdaf0b1f5d2c0a52bf2a3f8e0b7a2f9c8d4e6b1a3c5f7e9d1b3a5c7e';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** @var string */
    private $file;

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'kx');
    }

    protected function tearDown(): void
    {
        unlink($this->file);
    }

    public function test_in_a_jpeg_it_follows_jfif_and_the_picture_is_untouched(): void
    {
        $jfif = "\xFF\xE0\x00\x07JFIF\x00";
        $picture = "\xFF\xDB\x00\x03q\xFF\xDA\x00\x03scan-data\xFF\xD9";
        file_put_contents($this->file, "\xFF\xD8".$jfif.$picture);

        self::assertNull(ImageNote::read($this->file));
        self::assertTrue(ImageNote::write($this->file, self::SHA));
        self::assertSame(self::SHA, ImageNote::read($this->file));
        self::assertSame("\xFF\xD8".$jfif."\xFF\xFE\x00\x4Ckx-sha256\x00".self::SHA.$picture, file_get_contents($this->file));
    }

    public function test_a_jpeg_without_jfif(): void
    {
        file_put_contents($this->file, "\xFF\xD8\xFF\xDA\x00\x03scan");

        ImageNote::write($this->file, self::SHA);
        self::assertSame(self::SHA, ImageNote::read($this->file));
    }

    public function test_in_a_png_it_follows_the_header(): void
    {
        $png = (string) base64_decode(self::PNG, true);
        file_put_contents($this->file, $png);

        self::assertNull(ImageNote::read($this->file));
        self::assertTrue(ImageNote::write($this->file, self::SHA));
        self::assertSame(self::SHA, ImageNote::read($this->file));
        $written = (string) file_get_contents($this->file);
        self::assertSame($png, substr($written, 0, 33).substr($written, 33 + 12 + 74), 'the picture is untouched');
        self::assertSame([1, 1, IMAGETYPE_PNG], array_slice((array) getimagesize($this->file), 0, 3));
    }

    public function test_another_comment_another_file_and_a_broken_one_have_no_note(): void
    {
        foreach (["\xFF\xD8\xFF\xFE\x00\x07hello\xFF\xDA\x00\x03s", "\x89PNG\r\n\x1A\n\xFF\xFF\xFF\xFFtEXtkx", 'GIF89a', '', "\xFF\xD8\xFF\xFE\x00\x00", "\xFF\xD8\xFF\xFE\xFF"] as $bytes) {
            file_put_contents($this->file, $bytes);
            self::assertNull(ImageNote::read($this->file));
        }
        file_put_contents($this->file, 'GIF89a');
        self::assertTrue(ImageNote::write($this->file, self::SHA));
        self::assertSame('GIF89a', file_get_contents($this->file), 'another kind of file stays as it is');
    }
}
