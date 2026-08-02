<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Terrain;

use PHPolygon\Terrain\HeightmapData;
use PHPolygon\Terrain\ScatterRules;
use PHPolygon\Terrain\TerrainScatterGenerator;
use PHPUnit\Framework\TestCase;

class TerrainScatterGeneratorTest extends TestCase
{
    private function heightmap(float $level = 0.25): HeightmapData
    {
        return HeightmapData::flat(17, 17, 64.0, 64.0, 0.0, 20.0, $level);
    }

    /** Density painted to the given byte across the whole grid. */
    private function density(HeightmapData $map, int $value = 255): string
    {
        return base64_encode(str_repeat(chr($value), $map->gridWidth * $map->gridDepth));
    }

    public function testHashMatchesTheEditorsJavascriptImplementation(): void
    {
        // Pinned against the values the TypeScript port produces. These are the
        // parity contract: if this drifts, the editor previews a forest the
        // engine will not render. The mirror assertion lives in
        // resources/js/terrain/scatter.test.ts of the editor.
        $this->assertEqualsWithDelta(0.12160472269169986, TerrainScatterGenerator::hash(0, 0, 1337), 1e-15);
        $this->assertEqualsWithDelta(0.5570077104493976, TerrainScatterGenerator::hash(1, 0, 1337), 1e-15);
        $this->assertEqualsWithDelta(0.035408849362283945, TerrainScatterGenerator::hash(0, 1, 1337), 1e-15);
        $this->assertEqualsWithDelta(0.10102832107804716, TerrainScatterGenerator::hash(123, 4, 99), 1e-15);
        $this->assertEqualsWithDelta(0.015377218835055828, TerrainScatterGenerator::hash(7, 2, -9001), 1e-15);
    }

    public function testHashStaysWithinTheUnitInterval(): void
    {
        for ($cell = 0; $cell < 500; $cell++) {
            $value = TerrainScatterGenerator::hash($cell, $cell % 5, 4242);
            $this->assertGreaterThanOrEqual(0.0, $value);
            $this->assertLessThan(1.0, $value);
        }
    }

    public function testHashHandlesNegativeSeedsWithoutBreaking(): void
    {
        $value = TerrainScatterGenerator::hash(7, 2, -9001);

        $this->assertGreaterThanOrEqual(0.0, $value);
        $this->assertLessThan(1.0, $value);
    }

    public function testProducesNothingWithoutPaintedDensity(): void
    {
        $map = $this->heightmap();

        $this->assertSame([], (new TerrainScatterGenerator)->generate($map, '', new ScatterRules));
        $this->assertSame(
            [],
            (new TerrainScatterGenerator)->generate($map, $this->density($map, 0), new ScatterRules),
        );
    }

    public function testProducesNothingAtZeroDensity(): void
    {
        $map = $this->heightmap();

        $instances = (new TerrainScatterGenerator)->generate(
            $map,
            $this->density($map),
            new ScatterRules(densityPerUnit: 0.0),
        );

        $this->assertSame([], $instances);
    }

    public function testRejectsADensityMapThatDoesNotMatchTheGrid(): void
    {
        $map = $this->heightmap();

        $instances = (new TerrainScatterGenerator)->generate(
            $map,
            base64_encode(str_repeat("\xFF", 10)),
            new ScatterRules,
        );

        $this->assertSame([], $instances);
    }

    public function testPlacesInstancesWherePainted(): void
    {
        $map = $this->heightmap();

        $instances = (new TerrainScatterGenerator)->generate($map, $this->density($map), new ScatterRules);

        $this->assertNotEmpty($instances);
    }

    public function testIsDeterministicForTheSameSeed(): void
    {
        $map = $this->heightmap();
        $generator = new TerrainScatterGenerator;

        $first = $generator->generate($map, $this->density($map), new ScatterRules(seed: 7));
        $second = $generator->generate($map, $this->density($map), new ScatterRules(seed: 7));

        $this->assertEquals($first, $second);
    }

    public function testDifferentSeedsProduceDifferentLayouts(): void
    {
        $map = $this->heightmap();
        $generator = new TerrainScatterGenerator;

        $a = $generator->generate($map, $this->density($map), new ScatterRules(seed: 1));
        $b = $generator->generate($map, $this->density($map), new ScatterRules(seed: 2));

        $this->assertNotEquals($a, $b);
    }

    public function testFollowsTheTerrainHeight(): void
    {
        $generator = new TerrainScatterGenerator;
        $low = $this->heightmap(0.1);
        $high = $this->heightmap(0.9);

        $onLow = $generator->generate($low, $this->density($low), new ScatterRules);
        $onHigh = $generator->generate($high, $this->density($high), new ScatterRules);

        $this->assertNotEmpty($onLow);
        $this->assertCount(count($onLow), $onHigh);
        $this->assertGreaterThan($onLow[0]->position->y, $onHigh[0]->position->y);
    }

    public function testRespectsTheHeightBand(): void
    {
        $map = $this->heightmap(0.9);

        $instances = (new TerrainScatterGenerator)->generate(
            $map,
            $this->density($map),
            new ScatterRules(minHeight: 0.0, maxHeight: 0.5),
        );

        $this->assertSame([], $instances);
    }

    public function testRespectsTheSlopeBand(): void
    {
        // A ramp along X, so every interior cell is steep.
        $map = HeightmapData::fromFunction(
            static fn (float $x, float $z): float => $x * 0.5,
            gridWidth: 17,
            gridDepth: 17,
            sizeX: 64.0,
            sizeZ: 64.0,
            minHeight: -20.0,
            maxHeight: 20.0,
        );
        $generator = new TerrainScatterGenerator;

        $flatOnly = $generator->generate($map, $this->density($map), new ScatterRules(maxSlope: 2.0));
        $anySlope = $generator->generate($map, $this->density($map), new ScatterRules(maxSlope: 90.0));

        $this->assertLessThan(count($anySlope), count($flatOnly));
    }

    public function testScalesInstancesWithinTheConfiguredRange(): void
    {
        $map = $this->heightmap();

        $instances = (new TerrainScatterGenerator)->generate(
            $map,
            $this->density($map),
            new ScatterRules(minScale: 2.0, maxScale: 3.0),
        );

        $this->assertNotEmpty($instances);
        foreach ($instances as $instance) {
            $this->assertGreaterThanOrEqual(2.0, $instance->scale);
            $this->assertLessThanOrEqual(3.0, $instance->scale);
        }
    }

    public function testKeepsInstancesUprightUnlessAlignedToTheNormal(): void
    {
        $map = HeightmapData::fromFunction(
            static fn (float $x, float $z): float => $x * 0.3,
            gridWidth: 17,
            gridDepth: 17,
            sizeX: 64.0,
            sizeZ: 64.0,
            minHeight: -20.0,
            maxHeight: 20.0,
        );
        $generator = new TerrainScatterGenerator;

        $upright = $generator->generate($map, $this->density($map), new ScatterRules(maxSlope: 90.0));
        $aligned = $generator->generate(
            $map,
            $this->density($map),
            new ScatterRules(maxSlope: 90.0, alignToNormal: true),
        );

        $this->assertSame(0.0, $upright[0]->rotation->x);
        $this->assertSame(0.0, $upright[0]->rotation->z);
        $this->assertGreaterThan(0.0, abs($aligned[0]->rotation->z));
    }

    public function testHonoursTheInstanceLimit(): void
    {
        $map = $this->heightmap();

        $instances = (new TerrainScatterGenerator)->generate(
            $map,
            $this->density($map),
            new ScatterRules(densityPerUnit: 10.0),
            limit: 25,
        );

        $this->assertCount(25, $instances);
    }

    public function testScalesInstanceCountWithDensity(): void
    {
        $map = $this->heightmap();
        $generator = new TerrainScatterGenerator;

        $sparse = $generator->generate($map, $this->density($map), new ScatterRules(densityPerUnit: 0.01));
        $dense = $generator->generate($map, $this->density($map), new ScatterRules(densityPerUnit: 0.5));

        $this->assertGreaterThan(count($sparse), count($dense));
    }

    public function testKeepsInstancesInsideTheTerrainFootprint(): void
    {
        $map = $this->heightmap();
        $marginX = $map->sizeX / ($map->gridWidth - 1) / 2.0;
        $marginZ = $map->sizeZ / ($map->gridDepth - 1) / 2.0;

        $instances = (new TerrainScatterGenerator)->generate($map, $this->density($map), new ScatterRules);

        foreach ($instances as $instance) {
            $this->assertLessThanOrEqual($map->sizeX / 2.0 + $marginX, abs($instance->position->x));
            $this->assertLessThanOrEqual($map->sizeZ / 2.0 + $marginZ, abs($instance->position->z));
        }
    }
}
