<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\CatmullClark;
use PHPolygon\Geometry\LandmarkWarp;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MorphTargets;
use PHPolygon\Geometry\PolyMesh;
use PHPolygon\Geometry\TemplateDeformer;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class LandmarkWarpTest extends TestCase
{
    /** @return list<float> */
    private static function points(int $n, int $seed): array
    {
        $r = new Randomizer(new Mt19937($seed));
        $out = [];
        for ($i = 0; $i < $n * 3; $i++) {
            $out[] = $r->getInt(0, 20000) / 10000.0 - 1.0;
        }

        return $out;
    }

    /** A smooth non-affine map used as the ground truth. */
    private static function truth(float $x, float $y, float $z): array
    {
        return [$x * 1.2 + 0.1 * sin(2.0 * $y) + 0.3, $y * 0.9 + 0.08 * $x * $z, $z * 1.1 - 0.05 * $x * $x + 0.2];
    }

    public function testTheLandmarksLandOnTheirTargets(): void
    {
        foreach ([LandmarkWarp::KERNEL_THIN_PLATE, LandmarkWarp::KERNEL_CUBIC] as $kernel) {
            $src = self::points(60, 4);
            $dst = [];
            for ($i = 0; $i < 60; $i++) {
                array_push($dst, ...self::truth($src[$i * 3], $src[$i * 3 + 1], $src[$i * 3 + 2]));
            }
            $warp = LandmarkWarp::fit($src, $dst, $kernel);
            self::assertSame(60, $warp->landmarkCount());
            $moved = $warp->apply($src);
            foreach ($dst as $i => $v) {
                self::assertEqualsWithDelta($v, $moved[$i], 1e-7, "{$kernel} landmark value {$i}");
            }
            // Between the landmarks it follows the truth closely (smooth map, 60 samples of the unit cube).
            $probe = self::points(40, 77);
            $out = $warp->apply($probe);
            $err = 0.0;
            for ($i = 0; $i < 40; $i++) {
                $t = self::truth($probe[$i * 3], $probe[$i * 3 + 1], $probe[$i * 3 + 2]);
                $err = max($err, abs($t[0] - $out[$i * 3]), abs($t[1] - $out[$i * 3 + 1]), abs($t[2] - $out[$i * 3 + 2]));
            }
            self::assertLessThan(0.25, $err, "{$kernel} stays near the underlying map between landmarks");
        }
    }

    public function testAnAffineTargetIsReproducedEverywhere(): void
    {
        $src = self::points(30, 9);
        $map = static fn (float $x, float $y, float $z): array => [2.0 * $x + 0.5 * $y + 1.0, -0.3 * $x + 1.5 * $z, 0.8 * $y - 0.2 * $z - 2.0];
        $dst = [];
        for ($i = 0; $i < 30; $i++) {
            array_push($dst, ...$map($src[$i * 3], $src[$i * 3 + 1], $src[$i * 3 + 2]));
        }
        $warp = LandmarkWarp::fit($src, $dst);
        $probe = [5.0, -3.0, 8.0, 0.1, 0.2, 0.3];
        $out = $warp->apply($probe);
        foreach ([0, 3] as $o) {
            $e = $map($probe[$o], $probe[$o + 1], $probe[$o + 2]);
            self::assertEqualsWithDelta($e[0], $out[$o], 1e-7);
            self::assertEqualsWithDelta($e[1], $out[$o + 1], 1e-7);
            self::assertEqualsWithDelta($e[2], $out[$o + 2], 1e-7);
        }
    }

    public function testRegularisationTradesExactnessForSmoothness(): void
    {
        $src = self::points(40, 3);
        $noise = self::points(40, 5);
        $dst = [];
        for ($i = 0; $i < 40 * 3; $i++) {
            $dst[] = $src[$i] * 1.5 + 0.05 * $noise[$i];
        }
        $exact = LandmarkWarp::fit($src, $dst, LandmarkWarp::KERNEL_THIN_PLATE, 0.0)->apply($src);
        $soft = LandmarkWarp::fit($src, $dst, LandmarkWarp::KERNEL_THIN_PLATE, 0.5)->apply($src);
        $resExact = 0.0;
        $resSoft = 0.0;
        foreach ($dst as $i => $v) {
            $resExact += abs($v - $exact[$i]);
            $resSoft += abs($v - $soft[$i]);
        }
        self::assertLessThan(1e-5, $resExact);
        self::assertGreaterThan(0.01, $resSoft);
    }

    public function testBadInputIsRefused(): void
    {
        $flat = [0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 1.0, 0.0, 1.0, 1.0, 0.0, 2.0, 2.0, 0.0];
        try {
            LandmarkWarp::fit($flat, $flat);
            self::fail('coplanar landmarks have no unique warp');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('degenerate', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        LandmarkWarp::fit([0.0, 0.0, 0.0], [0.0, 0.0, 0.0]);
    }

    public function testTheDeformerBlendsShapesThenWarpsAndMatchesTheStencils(): void
    {
        $quad = new PolyMesh(
            [0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 1.0, 1.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 1.0, 1.0, 0.0, 1.0, 1.0, 1.0, 1.0, 0.0, 1.0, 1.0],
            [[0, 3, 2, 1], [4, 5, 6, 7], [0, 1, 5, 4], [2, 3, 7, 6], [1, 2, 6, 5], [3, 0, 4, 7]],
        );
        $tall = MorphTargets::fromShapes(new MeshData($quad->positions, [], [], []), ['tall' => new MeshData(array_map(static fn (float $v, int $i): float => $i % 3 === 1 ? $v * 2.0 : $v, $quad->positions, array_keys($quad->positions)), [], [], [])]);
        $deformer = new TemplateDeformer($quad, $tall);
        $shaped = $deformer->deform(['tall' => 1.0]);
        self::assertEqualsWithDelta(2.0, $shaped->positions[10], 1e-12, 'the shape delta doubled the height');
        // A warp that shifts the landmarks 0.5 along x moves everything (affine + weights).
        $src = [0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 1.0, 1.0, 1.0, 1.0];
        $dst = [];
        for ($i = 0; $i < 5; $i++) {
            array_push($dst, $src[$i * 3] + 0.5, $src[$i * 3 + 1], $src[$i * 3 + 2]);
        }
        $warp = LandmarkWarp::fit($src, $dst);
        $both = $deformer->deform(['tall' => 1.0], $warp);
        self::assertEqualsWithDelta($shaped->positions[0] + 0.5, $both->positions[0], 1e-9);
        // Warping the base then re-smoothing with the stencils equals subdividing the warped base.
        $r = CatmullClark::subdivide($quad, 2);
        $viaStencils = $r->positions($both->positions);
        $direct = CatmullClark::subdivide($both, 2);
        foreach ($direct->outputToVertex as $o => $v) {
            self::assertEqualsWithDelta($direct->mesh->vertices[$o * 3], $viaStencils[$v * 3], 1e-9);
            self::assertEqualsWithDelta($direct->mesh->vertices[$o * 3 + 1], $viaStencils[$v * 3 + 1], 1e-9);
        }
    }
}
