<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Icon;

use PHPolygon\Icon\WindowIconSet;
use PHPUnit\Framework\TestCase;

class WindowIconSetTest extends TestCase
{
    public function testFromImageMakesEverySizeUpToTheSource(): void
    {
        $set = WindowIconSet::fromImage(TestIcons::pattern(256));
        $this->assertSame(WindowIconSet::SIZES, array_map(static fn ($i) => $i->width, $set->images));

        $set = WindowIconSet::fromImage(TestIcons::pattern(64));
        $this->assertSame([16, 24, 32, 48, 64], array_map(static fn ($i) => $i->width, $set->images));
    }

    public function testSmallSourceDrawsTheSmallSizes(): void
    {
        $small = TestIcons::solid(32, 32, 0, 255, 0);
        $set = WindowIconSet::fromImage(TestIcons::pattern(256), $small);
        foreach ($set->images as $image) {
            $green = $image->pixel(0, 0) === [0, 255, 0, 255];
            $this->assertSame($image->width <= 32, $green, "size {$image->width}");
        }
    }

    public function testEncodeDecodeRoundTrip(): void
    {
        $set = WindowIconSet::fromImage(TestIcons::pattern(64));
        $back = WindowIconSet::decode($set->encode());
        $this->assertCount(count($set->images), $back->images);
        foreach ($set->images as $i => $image) {
            $this->assertSame($image->pixels, $back->images[$i]->pixels);
        }
    }

    public function testToVioHasTheShapeOfVioSetWindowIcon(): void
    {
        $vio = WindowIconSet::fromImage(TestIcons::pattern(32))->toVio();
        $this->assertSame(['data', 'width', 'height'], array_keys($vio[0]));
        $this->assertSame(16, $vio[0]['width']);
        $this->assertSame(16 * 16 * 4, strlen($vio[0]['data']));
    }

    public function testDecodeRejectsOtherFiles(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WindowIconSet::decode((string) gzcompress('something else'));
    }
}
