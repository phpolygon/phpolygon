<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Terrain;

use InvalidArgumentException;
use PHPolygon\Terrain\HeightmapData;
use PHPUnit\Framework\TestCase;

class HeightmapDataTest extends TestCase
{
    public function testRejectsSampleCountThatDoesNotMatchTheGrid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HeightmapData(3, 3, 10.0, 10.0, 0.0, 1.0, [0.0, 0.0, 0.0]);
    }

    public function testMapsNormalisedSamplesIntoTheHeightRange(): void
    {
        $map = new HeightmapData(2, 2, 10.0, 10.0, -20.0, 80.0, [0.0, 0.5, 1.0, 0.25]);

        $this->assertSame(-20.0, $map->heightAt(0, 0));
        $this->assertSame(30.0, $map->heightAt(1, 0));
        $this->assertSame(80.0, $map->heightAt(0, 1));
        $this->assertSame(5.0, $map->heightAt(1, 1));
    }

    public function testGridIsCentredOnTheOrigin(): void
    {
        $map = HeightmapData::flat(5, 5, 100.0, 40.0);

        $this->assertSame(-50.0, $map->worldX(0));
        $this->assertSame(50.0, $map->worldX(4));
        $this->assertSame(-20.0, $map->worldZ(0));
        $this->assertSame(20.0, $map->worldZ(4));
    }

    public function testSampleCoordinatesClampAtTheBorder(): void
    {
        $map = new HeightmapData(2, 2, 10.0, 10.0, 0.0, 1.0, [0.25, 0.5, 0.75, 1.0]);

        $this->assertSame($map->heightAt(0, 0), $map->heightAt(-5, -5));
        $this->assertSame($map->heightAt(1, 1), $map->heightAt(99, 99));
    }

    public function testBilinearWorldQueryInterpolatesBetweenSamples(): void
    {
        // Height ramps 0 → 10 along X and is constant along Z.
        $map = new HeightmapData(2, 2, 10.0, 10.0, 0.0, 10.0, [0.0, 1.0, 0.0, 1.0]);

        $this->assertEqualsWithDelta(0.0, $map->heightAtWorld(-5.0, 0.0), 1e-9);
        $this->assertEqualsWithDelta(5.0, $map->heightAtWorld(0.0, 0.0), 1e-9);
        $this->assertEqualsWithDelta(10.0, $map->heightAtWorld(5.0, 0.0), 1e-9);
        $this->assertEqualsWithDelta(2.5, $map->heightAtWorld(-2.5, 3.0), 1e-9);
    }

    public function testWorldQueryClampsOutsideTheTerrain(): void
    {
        $map = new HeightmapData(2, 2, 10.0, 10.0, 0.0, 10.0, [0.0, 1.0, 0.0, 1.0]);

        $this->assertEqualsWithDelta(0.0, $map->heightAtWorld(-500.0, 0.0), 1e-9);
        $this->assertEqualsWithDelta(10.0, $map->heightAtWorld(500.0, 0.0), 1e-9);
    }

    public function testFlatTerrainHasStraightUpNormals(): void
    {
        $map = HeightmapData::flat(5, 5, 10.0, 10.0, 0.0, 10.0, 0.5);

        $this->assertSame([0.0, 1.0, 0.0], $map->normalAt(2, 2));
    }

    public function testNormalTiltsAgainstTheSlopeDirection(): void
    {
        // Rises along +X, so the normal must lean towards -X and stay unit length.
        $map = new HeightmapData(3, 3, 20.0, 20.0, 0.0, 10.0, [
            0.0, 0.5, 1.0,
            0.0, 0.5, 1.0,
            0.0, 0.5, 1.0,
        ]);

        [$nx, $ny, $nz] = $map->normalAt(1, 1);

        $this->assertLessThan(0.0, $nx, 'normal must lean away from the uphill direction');
        $this->assertGreaterThan(0.0, $ny);
        $this->assertEqualsWithDelta(0.0, $nz, 1e-9, 'no slope along Z means no Z tilt');
        $this->assertEqualsWithDelta(1.0, sqrt($nx * $nx + $ny * $ny + $nz * $nz), 1e-9);
    }

    public function testEncodeDecodeRoundTripsWithinQuantisationError(): void
    {
        $samples = [];
        for ($i = 0; $i < 64; $i++) {
            $samples[] = $i / 63.0;
        }
        $map = new HeightmapData(8, 8, 32.0, 32.0, -10.0, 90.0, $samples);

        $restored = HeightmapData::decode($map->encode(), 8, 8, 32.0, 32.0, -10.0, 90.0);

        foreach ($samples as $index => $expected) {
            $this->assertEqualsWithDelta($expected, $restored->samples()[$index], 1e-4);
        }
    }

    public function testDecodingAPayloadThatDoesNotFitTheGridYieldsFlatTerrain(): void
    {
        // A resolution edit invalidates the payload; that must not be fatal.
        $map = HeightmapData::flat(4, 4, 10.0, 10.0, 0.0, 10.0, 1.0);

        $restored = HeightmapData::decode($map->encode(), 8, 8, 10.0, 10.0, 0.0, 10.0);

        $this->assertSame(64, count($restored->samples()));
        $this->assertSame(0.0, $restored->heightAt(3, 3));
    }

    public function testDecodingAnEmptyPayloadYieldsFlatTerrain(): void
    {
        $restored = HeightmapData::decode('', 4, 4, 10.0, 10.0, 5.0, 25.0);

        $this->assertSame(5.0, $restored->heightAt(0, 0));
        $this->assertSame(5.0, $restored->heightAt(3, 3));
    }

    public function testFromFunctionNormalisesSampledWorldHeights(): void
    {
        $map = HeightmapData::fromFunction(
            static fn (float $x, float $z): float => $x,
            gridWidth: 3,
            gridDepth: 3,
            sizeX: 20.0,
            sizeZ: 20.0,
            minHeight: -10.0,
            maxHeight: 10.0,
        );

        $this->assertEqualsWithDelta(-10.0, $map->heightAt(0, 0), 1e-9);
        $this->assertEqualsWithDelta(0.0, $map->heightAt(1, 0), 1e-9);
        $this->assertEqualsWithDelta(10.0, $map->heightAt(2, 0), 1e-9);
    }

    public function testFromFunctionClampsHeightsOutsideTheRange(): void
    {
        $map = HeightmapData::fromFunction(
            static fn (float $x, float $z): float => 1000.0,
            gridWidth: 2,
            gridDepth: 2,
            minHeight: 0.0,
            maxHeight: 10.0,
        );

        $this->assertSame(10.0, $map->heightAt(0, 0));
    }
}
