<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build\Icon;

use PHPolygon\Build\Icon\IcoFile;
use PHPolygon\Icon\IconResampler;
use PHPolygon\Icon\Png;
use PHPolygon\Icon\RgbaImage;
use PHPolygon\Tests\Icon\TestIcons;
use PHPUnit\Framework\TestCase;

class IcoFileTest extends TestCase
{
    public function testHeaderAndDirectoryFollowTheFormat(): void
    {
        $images = IconResampler::sizes(TestIcons::pattern(256), IcoFile::SIZES);
        $ico = IcoFile::build(IcoFile::entries($images));

        // ICONDIR: reserved 0, type 1 (icon), 7 images.
        $this->assertSame(pack('vvv', 0, 1, 7), substr($ico, 0, 6));

        $offset = 6 + 16 * 7;
        foreach (IcoFile::SIZES as $i => $size) {
            $e = unpack('Cw/Ch/Ccolors/Creserved/vplanes/vbits/Vbytes/Voffset', $ico, 6 + 16 * $i);
            $this->assertSame($size === 256 ? 0 : $size, $e['w'], "width byte of {$size}");
            $this->assertSame($size === 256 ? 0 : $size, $e['h']);
            $this->assertSame(0, $e['colors']);
            $this->assertSame(0, $e['reserved']);
            $this->assertSame(1, $e['planes']);
            $this->assertSame(32, $e['bits']);
            $this->assertSame($offset, $e['offset'], "images follow the directory back to back ({$size})");
            $data = substr($ico, $e['offset'], $e['bytes']);
            if ($size === 256) {
                $this->assertSame([256, 256], Png::size($data));
                $this->assertSame($images[256]->pixels, Png::decode($data)->pixels);
            } else {
                $maskStride = intdiv($size + 31, 32) * 4;
                $this->assertSame(40 + $size * $size * 4 + $maskStride * $size, $e['bytes']);
                $h = unpack('Vsize/lwidth/lheight/vplanes/vbits/Vcompression/Vimage', $data);
                $this->assertSame(40, $h['size']);
                $this->assertSame($size, $h['width']);
                $this->assertSame($size * 2, $h['height'], 'XOR + AND');
                $this->assertSame(1, $h['planes']);
                $this->assertSame(32, $h['bits']);
                $this->assertSame(0, $h['compression']);
                $this->assertSame($size * $size * 4 + $maskStride * $size, $h['image']);
            }
            $offset += $e['bytes'];
        }
        $this->assertSame($offset, strlen($ico));
    }

    public function testDibIsBottomUpBgraWithTransparencyMask(): void
    {
        // 2x2: top row red opaque + transparent, bottom row green + blue half alpha.
        $image = new RgbaImage(2, 2, pack('C*', 255, 0, 0, 255, 0, 0, 0, 0, 0, 255, 0, 255, 0, 0, 255, 128));
        $dib = IcoFile::dib($image);

        $pixels = substr($dib, 40, 16);
        $this->assertSame(pack('C*', 0, 255, 0, 255, 255, 0, 0, 128), substr($pixels, 0, 8), 'bottom row first, BGRA');
        $this->assertSame(pack('C*', 0, 0, 255, 255, 0, 0, 0, 0), substr($pixels, 8, 8));
        // AND mask rows of 4 bytes, bottom row first: nothing, then pixel 1 (bit 6).
        $this->assertSame("\0\0\0\0" . "\x40\0\0\0", substr($dib, 56, 8));
        $this->assertSame(64, strlen($dib));
    }

    public function testParseReadsWhatBuildWrote(): void
    {
        $entries = IcoFile::entries(IconResampler::sizes(TestIcons::pattern(256), [16, 48, 256]));
        $parsed = IcoFile::parse(IcoFile::build($entries));
        $this->assertCount(3, $parsed);
        foreach ($entries as $i => $entry) {
            $this->assertSame($entry->width, $parsed[$i]->width);
            $this->assertSame($entry->data, $parsed[$i]->data);
        }
        $this->assertTrue($parsed[2]->isPng());
        $this->assertFalse($parsed[0]->isPng());
    }

    public function testRejectsOversizedImagesAndForeignFiles(): void
    {
        try {
            IcoFile::entries([TestIcons::solid(512, 512, 0, 0, 0)]);
            $this->fail('512 px accepted');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        IcoFile::parse("\0\0\2\0\1\0");
    }
}
