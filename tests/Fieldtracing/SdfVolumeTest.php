<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Fieldtracing;

use PHPolygon\Fieldtracing\Bake\SdfVolumeBaker;
use PHPolygon\Fieldtracing\Sdf\SphereSdf;
use PHPolygon\Fieldtracing\Volume\SdfVolume;
use PHPolygon\Math\Vec3;
use PHPUnit\Framework\TestCase;

class SdfVolumeTest extends TestCase
{
    public function testRejectsWrongDataLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SdfVolume(2, 2, 2, new Vec3(), 1.0, [0.0, 0.0]); // needs 8
    }

    public function testRejectsTooFewSamples(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SdfVolume(1, 2, 2, new Vec3(), 1.0, array_fill(0, 4, 0.0));
    }

    public function testGridSampleReturnsStoredValueAtCellCentres(): void
    {
        // 2x2x2 grid, corners hold their flat index as a marker.
        $data = range(0.0, 7.0);
        $vol = new SdfVolume(2, 2, 2, new Vec3(0, 0, 0), 1.0, $data);

        $this->assertEqualsWithDelta(0.0, $vol->sample(new Vec3(0, 0, 0)), 1e-9);
        $this->assertEqualsWithDelta(7.0, $vol->sample(new Vec3(1, 1, 1)), 1e-9);
        // (ix=1,iy=0,iz=0) -> flat index 1.
        $this->assertEqualsWithDelta(1.0, $vol->sample(new Vec3(1, 0, 0)), 1e-9);
    }

    public function testTrilinearMidpoint(): void
    {
        $data = range(0.0, 7.0);
        $vol = new SdfVolume(2, 2, 2, new Vec3(0, 0, 0), 1.0, $data);
        // Centre of the cube = average of all 8 corners = 3.5.
        $this->assertEqualsWithDelta(3.5, $vol->sample(new Vec3(0.5, 0.5, 0.5)), 1e-9);
    }

    public function testSampleClampsOutsideGrid(): void
    {
        $data = range(0.0, 7.0);
        $vol = new SdfVolume(2, 2, 2, new Vec3(0, 0, 0), 1.0, $data);
        // Far outside clamps to the nearest corner (index 0).
        $this->assertEqualsWithDelta(0.0, $vol->sample(new Vec3(-100, -100, -100)), 1e-9);
        $this->assertEqualsWithDelta(7.0, $vol->sample(new Vec3(100, 100, 100)), 1e-9);
    }

    public function testBakedSphereSamplesMatchAnalyticField(): void
    {
        $sphere = new SphereSdf(1.5, new Vec3(0, 0, 0));
        $vol = SdfVolumeBaker::bakeAuto($sphere, 33, 0.5);

        // Trilinear interpolation of a (locally near-linear) distance field
        // should track the analytic value within roughly one cell.
        $tol = $vol->cellSize * 1.5;
        foreach ([[0.3, 0.2, 0.1], [1.0, 0.0, 0.0], [0.0, 1.2, 0.4], [0.7, 0.7, 0.0]] as $c) {
            $p = new Vec3($c[0], $c[1], $c[2]);
            $this->assertEqualsWithDelta($sphere->distance($p), $vol->sample($p), $tol, "at {$p}");
        }
    }

    public function testBakeAutoThrowsOnUnboundedField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SdfVolumeBaker::bakeAuto(new \PHPolygon\Fieldtracing\Sdf\PlaneSdf(), 16);
    }

    public function testToRgba8LayoutAndEncoding(): void
    {
        // 2x2x2 grid: distance 0 -> mid-grey (128), +range -> 255, -range -> 0.
        $range = 4.0;
        $data = [0.0, $range, -$range, 0.0, 0.0, 0.0, 0.0, 0.0];
        $vol = new SdfVolume(2, 2, 2, new Vec3(), 1.0, $data);

        $bytes = $vol->toRgba8($range);
        $this->assertSame(8 * 4, strlen($bytes), 'width*height*depth*4 bytes');

        // Voxel 0: d=0 -> ~128 in RGB, 255 alpha.
        $this->assertEqualsWithDelta(128, ord($bytes[0]), 1);
        $this->assertSame(255, ord($bytes[3]));
        // Voxel 1: d=+range -> 255.
        $this->assertSame(255, ord($bytes[4]));
        // Voxel 2: d=-range -> 0.
        $this->assertSame(0, ord($bytes[8]));
    }

    public function testMaxCornerComputed(): void
    {
        $vol = new SdfVolume(3, 3, 3, new Vec3(1, 1, 1), 2.0, array_fill(0, 27, 0.0));
        $this->assertTrue($vol->max()->equals(new Vec3(5, 5, 5)));
    }
}
