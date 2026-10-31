<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Vegetation;

use PHPUnit\Framework\TestCase;
use PHPolygon\Vegetation\GrassGroundRaster;

final class GrassGroundRasterTest extends TestCase
{
    public function testHeightAndCoverageAreBilinear(): void
    {
        // Plane y = 0.1 x, kind 1 below x = 10, kind 2 from there, no ground beyond z = 50.
        $raster = new GrassGroundRaster(static fn(float $x, float $z): array => $z > 50.0 ? [null, 0] : [0.1 * $x, $x < 10.0 ? 1 : 2]);

        self::assertEqualsWithDelta(0.35, $raster->heightAt(3.5, 7.25), 1e-9);
        self::assertEqualsWithDelta(-1.23, $raster->heightAt(-12.3, -40.0), 1e-9, 'negative tiles');
        self::assertSame(1.0, $raster->coverage(4.0, 4.0, 1));
        self::assertSame(0.0, $raster->coverage(4.0, 4.0, 2));
        self::assertEqualsWithDelta(0.5, $raster->coverage(9.5, 3.0, 1), 1e-9, 'soft over one step');
        self::assertEqualsWithDelta(1.0, $raster->anyCoverage(9.5, 3.0), 1e-9);
        self::assertNull($raster->heightAt(5.0, 60.0));
        self::assertSame(0.0, $raster->anyCoverage(5.0, 60.0));
    }

    public function testHeightLeansOnTheCornersWithGround(): void
    {
        $raster = new GrassGroundRaster(static fn(float $x, float $z): array => $x <= 0.0 ? [2.0, 1] : [null, 0]);

        self::assertEqualsWithDelta(2.0, $raster->heightAt(0.25, 0.5), 1e-9, 'one side of the mesh has no ground');
        self::assertNull($raster->heightAt(1.5, 0.5));
    }

    public function testAReadMeasuresAtMostItsFourCornersAndEachOnlyOnce(): void
    {
        $raster = GrassGroundRaster::fromHeight(static fn(float $x, float $z): ?float => 1.0);

        $raster->heightAt(3.5, 3.5);
        self::assertSame(4, $raster->samples, 'a read costs four measurements at most');

        $raster->heightAt(3.7, 3.1);
        $raster->coverage(3.2, 3.9, 1);
        self::assertSame(4, $raster->samples, 'the same mesh is not measured again');

        $raster->anyCoverage(4.5, 3.5);
        self::assertSame(6, $raster->samples, 'a neighbour shares two corners');

        $raster->clear();
        $raster->heightAt(3.5, 3.5);
        self::assertSame(10, $raster->samples);
    }

    public function testNegativeAndPositiveCoordinatesKeepTheirOwnPoints(): void
    {
        $raster = new GrassGroundRaster(static fn(float $x, float $z): array => [100.0 * $x + $z, 1]);

        foreach ([[-1.0, 1.0], [1.0, -1.0], [-1.0, -1.0], [1.0, 1.0], [-300.0, 400.0], [0.0, -500000.0], [-500000.0, 0.0]] as [$x, $z]) {
            self::assertEqualsWithDelta(100.0 * $x + $z, $raster->heightAt($x, $z), 1e-9, "({$x}, {$z})");
        }
    }

    public function testBlocksEndAtTheLimitAndADroppedPointMeasuresTheSame(): void
    {
        $sample = static fn(float $x, float $z): array => [sin($x * 0.13) * cos($z * 0.07) * 40.0, 1 + ((int) $x + (int) $z) % 3];
        $free = new GrassGroundRaster($sample);
        $tight = new GrassGroundRaster($sample, maxBlocks: 3);
        for ($i = 0; $i < 3000; $i++) {
            $x = -200.0 + $i * 0.313;
            $z = 150.0 - $i * 0.177;
            self::assertSame($free->heightAt($x, $z), $tight->heightAt($x, $z), "height at ({$x}, {$z})");
            self::assertSame($free->coverage($x, $z, 2), $tight->coverage($x, $z, 2));
            self::assertSame($free->anyCoverage($x, $z), $tight->anyCoverage($x, $z));
            self::assertLessThanOrEqual(3, $tight->blockCount());
        }
        self::assertGreaterThan($free->samples, $tight->samples, 'what was dropped is measured again');
        self::assertGreaterThan(3, $free->blockCount());
    }

    public function testOneBlockIsEnoughForAReadAcrossFourBlocks(): void
    {
        $raster = new GrassGroundRaster(static fn(float $x, float $z): array => [$x + $z, 1], maxBlocks: 1);
        self::assertEqualsWithDelta(31.0, $raster->heightAt(15.5, 15.5), 1e-9);
        self::assertSame(1, $raster->blockCount());
    }

    public function testNoGroundIsMeasuredOnceAndDoesNotCountAsUnmeasured(): void
    {
        $raster = new GrassGroundRaster(static fn(float $x, float $z): array => [null, 7]);
        self::assertNull($raster->heightAt(1.5, 1.5));
        self::assertSame(0.0, $raster->coverage(1.5, 1.5, 7), 'no ground, no kind');
        self::assertSame(4, $raster->samples);
    }

    public function testStepAndLimitMustBePositive(): void
    {
        foreach ([[0.0, 16], [-1.0, 16], [1.0, 0]] as [$step, $blocks]) {
            try {
                new GrassGroundRaster(static fn(float $x, float $z): array => [0.0, 1], $step, $blocks);
                self::fail("step {$step}, {$blocks} blocks");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAPointBeyondTheKeysIsRefusedNotWrapped(): void
    {
        $raster = GrassGroundRaster::fromHeight(static fn(float $x, float $z): ?float => $x);
        $edge = GrassGroundRaster::MAX_INDEX - 8.0;
        self::assertEqualsWithDelta($edge, $raster->heightAt($edge, -$edge), 1e-3, 'the last valid point');
        self::assertEqualsWithDelta(-$edge, $raster->heightAt(-$edge, $edge), 1e-3);

        foreach ([[GrassGroundRaster::MAX_INDEX, 0.0], [0.0, -GrassGroundRaster::MAX_INDEX], [1e30, 0.0], [NAN, 0.0]] as [$x, $z]) {
            try {
                $raster->coverage($x, $z, 1);
                self::fail("({$x}, {$z})");
            } catch (\OutOfRangeException) {
                self::assertTrue(true);
            }
        }
        $coarse = GrassGroundRaster::fromHeight(static fn(float $x, float $z): ?float => 1.0, 1000.0);
        self::assertSame(1.0, $coarse->heightAt(GrassGroundRaster::MAX_INDEX, 0.0), 'the limit is in steps');
    }

    public function testTheStepScalesTheGrid(): void
    {
        $raster = new GrassGroundRaster(static fn(float $x, float $z): array => [sin($x * 0.3) + 0.2 * $z, 1], 0.5);

        self::assertEqualsWithDelta(sin(1.5 * 0.3) + 0.2 * 2.5, $raster->heightAt(1.5, 2.5), 1e-9, 'exact on the grid');
        $a = sin(1.5 * 0.3);
        $b = sin(2.0 * 0.3);
        self::assertEqualsWithDelta(($a + $b) / 2.0 + 0.5, $raster->heightAt(1.75, 2.5), 1e-9);
    }
}
