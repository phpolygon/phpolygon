<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build\Icon;

use PHPolygon\Build\Icon\IcnsFile;
use PHPolygon\Icon\IconResampler;
use PHPolygon\Icon\Png;
use PHPolygon\Tests\Icon\TestIcons;
use PHPUnit\Framework\TestCase;

class IcnsFileTest extends TestCase
{
    public function testLayoutHeaderTocAndEntries(): void
    {
        $images = IconResampler::sizes(TestIcons::pattern(1024), IcnsFile::SIZES);
        $icns = IcnsFile::build($images);

        $this->assertSame('icns', substr($icns, 0, 4));
        $this->assertSame(strlen($icns), unpack('N', $icns, 4)[1], 'big-endian total length');

        // TOC first: 'TOC ' + length + (type, length) per entry.
        $this->assertSame('TOC ', substr($icns, 8, 4));
        $tocLength = unpack('N', $icns, 12)[1];
        $this->assertSame(8 + 8 * count(IcnsFile::TYPES), $tocLength);

        $pos = 8 + $tocLength;
        $tocAt = 16;
        foreach (IcnsFile::TYPES as $type => $size) {
            $this->assertSame($type, substr($icns, $pos, 4));
            $length = unpack('N', $icns, $pos + 4)[1];
            $this->assertSame($type . pack('N', $length), substr($icns, $tocAt, 8), "TOC entry of {$type}");
            $png = substr($icns, $pos + 8, $length - 8);
            $this->assertSame([$size, $size], Png::size($png), "{$type} holds {$size} px");
            $this->assertSame($images[$size]->pixels, Png::decode($png)->pixels);
            $pos += $length;
            $tocAt += 8;
        }
        $this->assertSame(strlen($icns), $pos);
    }

    public function testSmallSourceLeavesOutLargerTypes(): void
    {
        $entries = IcnsFile::parse(IcnsFile::build(IconResampler::sizes(TestIcons::pattern(256), IcnsFile::SIZES)));
        $this->assertSame(['TOC ', 'icp4', 'ic11', 'icp5', 'ic12', 'ic07', 'ic13', 'ic08'], array_keys($entries));
    }

    public function testParseRejectsAWrongLength(): void
    {
        $icns = IcnsFile::build([16 => TestIcons::pattern(16)]);
        $this->expectException(\InvalidArgumentException::class);
        IcnsFile::parse($icns . 'x');
    }
}
