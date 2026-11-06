<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Icon;

use PHPolygon\Icon\IconResampler;
use PHPolygon\Icon\RgbaImage;
use PHPUnit\Framework\TestCase;

class IconResamplerTest extends TestCase
{
    public function testHalvingIsTheMeanOfEachTwoByTwoBlock(): void
    {
        // 2x2 -> 1x1: (0 + 100 + 200 + 40) / 4 = 85 per channel.
        $src = new RgbaImage(2, 2, pack('C*', 0, 0, 0, 255, 100, 100, 100, 255, 200, 200, 200, 255, 40, 40, 40, 255));
        $this->assertSame([85, 85, 85, 255], IconResampler::downscale($src, 1, 1)->pixel(0, 0));
    }

    public function testTransparentPixelsLeaveNoDarkFringe(): void
    {
        // One opaque white pixel and three fully transparent black ones: colour
        // stays white (premultiplied), alpha is a quarter.
        $src = new RgbaImage(2, 2, pack('C*', 255, 255, 255, 255, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0));
        $this->assertSame([255, 255, 255, 64], IconResampler::downscale($src, 1, 1)->pixel(0, 0));
    }

    public function testFractionalCoverageWeighsByArea(): void
    {
        // 3 -> 2 columns: output 0 covers column 0 fully and half of column 1.
        $src = new RgbaImage(3, 1, pack('C*', 0, 0, 0, 255, 90, 90, 90, 255, 180, 180, 180, 255));
        $out = IconResampler::downscale($src, 2, 1);
        $this->assertSame([30, 30, 30, 255], $out->pixel(0, 0));   // (0 * 1 + 90 * 0.5) / 1.5
        $this->assertSame([150, 150, 150, 255], $out->pixel(1, 0)); // (90 * 0.5 + 180) / 1.5
    }

    public function testFlatColourStaysFlat(): void
    {
        $out = IconResampler::downscale(TestIcons::solid(64, 64, 12, 34, 56, 200), 24, 24);
        $this->assertSame(str_repeat(pack('C4', 12, 34, 56, 200), 24 * 24), $out->pixels);
    }

    public function testSizesCascadeMatchesDirectHalving(): void
    {
        $src = TestIcons::pattern(128);
        $sizes = IconResampler::sizes($src, [16, 24, 32, 64, 128, 256]);

        $this->assertSame([16, 24, 32, 64, 128], array_keys($sizes));
        $this->assertArrayNotHasKey(256, $sizes, 'never enlarged');
        $this->assertSame($src, $sizes[128]);
        foreach ($sizes as $size => $image) {
            $this->assertSame([$size, $size], [$image->width, $image->height]);
        }
        // Power-of-two steps of a 2x box are the direct box: halving twice
        // equals the direct 4:1 mean up to the rounding of the middle step.
        $direct = IconResampler::downscale($src, 32, 32);
        $maxDiff = 0;
        for ($i = 0; $i < strlen($direct->pixels); $i++) {
            $maxDiff = max($maxDiff, abs(ord($direct->pixels[$i]) - ord($sizes[32]->pixels[$i])));
        }
        $this->assertLessThanOrEqual(1, $maxDiff);
    }

    public function testSmallSizesKeepTheMotif(): void
    {
        // The yellow disc stays in the centre and the corners stay transparent at 16 px.
        $small = IconResampler::sizes(TestIcons::pattern(256), [16])[16];
        $this->assertLessThan(16, $small->pixel(0, 0)[3], 'corner nearly transparent (the rounding touches it)');
        $centre = $small->pixel(10, 5);
        $this->assertGreaterThan(200, $centre[0]);
        $this->assertGreaterThan(150, $centre[1]);
        $this->assertSame(255, $small->pixel(8, 1)[3]);
    }

    public function testRefusesToEnlargeOrNonSquareSets(): void
    {
        try {
            IconResampler::downscale(TestIcons::solid(4, 4, 0, 0, 0), 8, 8);
            $this->fail('enlarging accepted');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        IconResampler::sizes(TestIcons::solid(8, 4, 0, 0, 0), [2]);
    }
}
