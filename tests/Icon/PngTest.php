<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Icon;

use PHPolygon\Icon\Png;
use PHPolygon\Icon\RgbaImage;
use PHPUnit\Framework\TestCase;

class PngTest extends TestCase
{
    public function testEncodeDecodeRoundTripIsLossless(): void
    {
        $image = TestIcons::pattern(37);
        $png = Png::encode($image);

        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        $this->assertSame([37, 37], Png::size($png));
        $this->assertSame($image->pixels, Png::decode($png)->pixels);
    }

    public function testEncodedFileHasOnlyHeaderDataAndEnd(): void
    {
        $png = Png::encode(TestIcons::solid(3, 2, 1, 2, 3, 4));
        $chunks = [];
        for ($pos = 8; $pos < strlen($png);) {
            $length = unpack('N', $png, $pos)[1];
            $chunks[] = substr($png, $pos + 4, 4);
            $pos += 12 + $length;
        }
        $this->assertSame(['IHDR', 'IDAT', 'IEND'], $chunks);
        // IHDR: 3x2, 8 bit, colour type 6 (RGBA), no interlace.
        $this->assertSame(pack('NNCCCCC', 3, 2, 8, 6, 0, 0, 0), substr($png, 16, 13));
    }

    public function testEncodingIsDeterministic(): void
    {
        $this->assertSame(Png::encode(TestIcons::pattern(20)), Png::encode(TestIcons::pattern(20)));
    }

    /**
     * Every filter type the decoder must undo, on an RGBA image whose rows were
     * filtered by hand.
     */
    public function testDecodesAllFilterTypes(): void
    {
        $rows = [
            [10, 20, 30, 255, 40, 50, 60, 128],
            [11, 22, 33, 255, 44, 55, 66, 129],
            [200, 100, 50, 0, 250, 5, 9, 77],
            [1, 2, 3, 4, 5, 6, 7, 8],
            [9, 8, 7, 6, 5, 4, 3, 2],
        ];
        $raw = '';
        $prev = array_fill(0, 8, 0);
        foreach ($rows as $y => $row) {
            $filter = $y; // 0 none, 1 sub, 2 up, 3 average, 4 paeth
            $out = [];
            foreach ($row as $i => $x) {
                $a = $i >= 4 ? $row[$i - 4] : 0;
                $b = $prev[$i];
                $c = $i >= 4 ? $prev[$i - 4] : 0;
                $p = $a + $b - $c;
                $paeth = abs($p - $a) <= abs($p - $b) && abs($p - $a) <= abs($p - $c) ? $a : (abs($p - $b) <= abs($p - $c) ? $b : $c);
                $out[] = match ($filter) {
                    0 => $x,
                    1 => ($x - $a) & 0xff,
                    2 => ($x - $b) & 0xff,
                    3 => ($x - intdiv($a + $b, 2)) & 0xff,
                    default => ($x - $paeth) & 0xff,
                };
            }
            $raw .= chr($filter) . pack('C*', ...$out);
            $prev = $row;
        }
        $image = Png::decode(self::png(2, 5, 8, 6, $raw));

        $expected = '';
        foreach ($rows as $row) {
            $expected .= pack('C*', ...$row);
        }
        $this->assertSame($expected, $image->pixels);
    }

    public function testDecodesPaletteWithTransparency(): void
    {
        // 2 bit palette, 3 entries, entry 1 half transparent; row: 0 1 2 1.
        $raw = "\0" . chr(0b00011001);
        $png = self::png(4, 1, 2, 3, $raw, ['PLTE' => "\xff\0\0\0\xff\0\0\0\xff", 'tRNS' => "\xff\x80"]);
        $this->assertSame(
            "\xff\0\0\xff" . "\0\xff\0\x80" . "\0\0\xff\xff" . "\0\xff\0\x80",
            Png::decode($png)->pixels,
        );
    }

    public function testDecodesGreyAndSixteenBit(): void
    {
        // 8-bit grey + alpha.
        $png = self::png(2, 1, 8, 4, "\0" . "\x10\x20\xf0\xff");
        $this->assertSame("\x10\x10\x10\x20\xf0\xf0\xf0\xff", Png::decode($png)->pixels);

        // 16-bit RGB keeps the high byte.
        $png = self::png(1, 1, 16, 2, "\0" . "\x12\x34\xab\xcd\xff\x00");
        $this->assertSame("\x12\xab\xff\xff", Png::decode($png)->pixels);

        // 1-bit grey scales 1 to 255; colour key tRNS makes 0 transparent.
        $png = self::png(3, 1, 1, 0, "\0" . chr(0b10100000), ['tRNS' => "\x00\x00"]);
        $this->assertSame("\xff\xff\xff\xff" . "\0\0\0\0" . "\xff\xff\xff\xff", Png::decode($png)->pixels);
    }

    public function testDecodesWhatGdWrites(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('ext-gd not loaded');
        }
        $gd = imagecreatetruecolor(5, 3);
        imagealphablending($gd, false);
        imagesavealpha($gd, true);
        imagefill($gd, 0, 0, imagecolorallocatealpha($gd, 10, 200, 30, 0));
        imagesetpixel($gd, 2, 1, imagecolorallocatealpha($gd, 250, 0, 0, 127));
        ob_start();
        imagepng($gd);
        $png = (string) ob_get_clean();

        $image = Png::decode($png);
        $this->assertSame([10, 200, 30, 255], $image->pixel(0, 0));
        $this->assertSame(0, $image->pixel(2, 1)[3]);
    }

    public function testRejectsInterlacedBrokenAndForeignFiles(): void
    {
        $interlaced = self::png(1, 1, 8, 6, "\0\0\0\0\0", [], 1);
        try {
            Png::decode($interlaced);
            $this->fail('interlaced PNG accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Interlaced', $e->getMessage());
        }

        $broken = Png::encode(TestIcons::solid(2, 2, 1, 1, 1));
        $broken[20] = "\x7f"; // inside IHDR: the CRC no longer matches
        try {
            Png::decode($broken);
            $this->fail('bad CRC accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('CRC', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        Png::decode('GIF89a');
    }

    /**
     * @param array<string, string> $extra chunks between IHDR and IDAT
     */
    private static function png(int $w, int $h, int $depth, int $color, string $raw, array $extra = [], int $interlace = 0): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $png = Png::SIGNATURE . $chunk('IHDR', pack('NNCCCCC', $w, $h, $depth, $color, 0, 0, $interlace));
        foreach ($extra as $type => $data) {
            $png .= $chunk($type, $data);
        }
        return $png . $chunk('IDAT', (string) gzcompress($raw)) . $chunk('IEND', '');
    }

    public function testRgbaImageChecksItsSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RgbaImage(2, 2, 'short');
    }
}
