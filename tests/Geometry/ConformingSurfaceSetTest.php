<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\ConformingSurfaceSet;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\RegionShape;
use PHPolygon\Geometry\SurfaceFit;
use PHPUnit\Framework\TestCase;

final class ConformingSurfaceSetTest extends TestCase
{
    private const float LIFT = 0.025;
    private const float SINK = 0.05;
    private const float HEM = 0.35;

    private static function hill(float $x, float $z): float
    {
        return 3.0 * exp(-($x * $x + $z * $z) / 60.0) + 0.05 * $x;
    }

    private static function set(): ConformingSurfaceSet
    {
        return new ConformingSurfaceSet(self::hill(...), cell: 0.5, lift: self::LIFT, hemWidth: self::HEM, hemSink: self::SINK);
    }

    public function testNeighbouringSurfacesShareTheirJointAndTheFirstWins(): void
    {
        $set = self::set()
            ->add('paving', RegionShape::rect(0.0, 0.0, 6.0, 4.0))
            ->add('gravel', RegionShape::rect(4.0, 0.0, 6.0, 4.0)); // overlaps x 1..3
        $parts = $set->build();
        self::assertArrayHasKey('paving', $parts);
        self::assertArrayHasKey('gravel', $parts);
        self::assertEqualsWithDelta(24.0, ConformingRegionMeshTest::areaXz($parts['paving']), 1e-6, 'the first surface keeps the overlap');
        self::assertEqualsWithDelta(16.0, ConformingRegionMeshTest::areaXz($parts['gravel']), 1e-6);

        // Merged, the joint between the two closes: only the hem's outer rim stays open, below the ground.
        $fit = SurfaceFit::measure(MeshData::merge(...array_values($parts)), $set->ground());
        self::assertEqualsWithDelta(-self::SINK, $fit->openEdgeMax, 0.002, 'no visible edge anywhere (between the rim vertices the ground bends a little)');
        $joint = 0;
        $paving = ConformingRegionMeshTest::openEdges($parts['paving']);
        foreach (ConformingRegionMeshTest::openEdges($parts['gravel']) as $e => $_) {
            if (isset($paving[$e])) {
                $joint++;
            }
        }
        self::assertGreaterThanOrEqual(8, $joint, 'the 4 m joint at x = 3 is shared edge for edge');
    }

    public function testAJunctionWithBorderAndInlayHasNoOpenEdgeAboveTheGround(): void
    {
        $set = self::set()
            ->add('paving', RegionShape::polyline([[-14.0, 1.0], [0.0, -1.0], [10.0, -3.0]], 1.5))
            ->add('paving', RegionShape::circle(0.0, -1.0, 2.6))
            ->add('plaza', RegionShape::circle(-6.0, 14.0, 5.0))
            ->add('gravel', RegionShape::polyline([[0.0, -1.0], [-2.0, 6.0], [-6.0, 12.0]], 0.9))
            ->border('plaza', 0.45, 'plaza_border')
            ->inlay('marker', RegionShape::circle(0.0, -1.0, 0.75))
            ->inlay('ring', RegionShape::circle(0.0, -1.0, 0.95), layer: 1);
        $parts = $set->build();
        self::assertCount(9, $parts);
        // Every joint (surface–surface, surface–border, surface–inlay, top–hem, hem–hem)
        // shares its vertices: merged, only the hem's outer rim stays open – below the ground.
        $fit = SurfaceFit::measure(MeshData::merge(...array_values($parts)), $set->ground());
        self::assertLessThan(-self::SINK + 0.005, $fit->openEdgeMax);

        // A cut leaves the surface's own edge open there – at the lift, no higher.
        $set->cut(RegionShape::rect(9.0, -3.0, 2.0, 6.0));
        $fit = SurfaceFit::measure(MeshData::merge(...array_values($set->build())), $set->ground());
        self::assertNotNull($fit->openEdgeAt);
        self::assertTrue(abs($fit->openEdgeAt[0] - 8.0) < 1e-6 || abs($fit->openEdgeAt[0] - 10.0) < 1e-6, 'the highest open edge is at the cut (x = 8 or 10)');
        self::assertEqualsWithDelta(self::LIFT, $fit->openEdgeMax, 0.002);
    }

    public function testTopsFollowTheHillAndTheHemSinksIntoIt(): void
    {
        $set = self::set()
            ->add('path', RegionShape::polyline([[-12.0, 0.0], [0.0, 1.0], [12.0, -1.0]], 1.0))
            ->add('plaza', RegionShape::circle(0.0, 6.0, 4.0));
        $parts = $set->build();
        $g = $set->ground();
        foreach (['path', 'plaza'] as $s) {
            $v = $parts[$s]->vertices;
            for ($i = 0, $n = count($v); $i < $n; $i += 3) {
                self::assertEqualsWithDelta(self::LIFT, $v[$i + 1] - $g($v[$i], $v[$i + 2]), 1e-9, "{$s}: vertex off the ground");
            }
            $fit = SurfaceFit::measure($parts[$s], $g);
            // Between the vertices the planar cells sag below a convex hilltop by a few millimetres at a 0.5 m grid.
            self::assertLessThan(self::LIFT + 0.01, $fit->liftMax, $s);
            self::assertGreaterThan(self::LIFT - 0.01, $fit->liftMin, $s);

            $hem = SurfaceFit::measure($parts[ConformingSurfaceSet::hemOf($s)], $g);
            self::assertEqualsWithDelta(-self::SINK, $hem->liftMin, 0.005, "{$s}: hem ends below the ground");
            self::assertLessThanOrEqual(self::LIFT + 0.005, $hem->liftMax);
        }
    }

    public function testEachHemPointBelongsToTheNearestSurface(): void
    {
        $set = self::set()
            ->add('a', RegionShape::circle(-5.0, 0.0, 2.0))
            ->add('b', RegionShape::circle(5.0, 0.0, 2.0));
        $parts = $set->build();
        foreach (['a' => -5.0, 'b' => 5.0] as $s => $cx) {
            $v = $parts[ConformingSurfaceSet::hemOf($s)]->vertices;
            for ($i = 0, $n = count($v); $i < $n; $i += 3) {
                $r = hypot($v[$i] - $cx, $v[$i + 2]);
                self::assertGreaterThanOrEqual(2.0 - 0.01, $r, 'the outline is a chord polygon of the circle');
                self::assertLessThanOrEqual(2.0 + self::HEM + 1e-6, $r);
            }
            // A full ring: π((r + w)² − r²), minus the polygonal error.
            self::assertEqualsWithDelta(M_PI * ((2.0 + self::HEM) ** 2 - 4.0), ConformingRegionMeshTest::areaXz($parts[ConformingSurfaceSet::hemOf($s)]), 0.08);
        }
    }

    public function testBorderRunsAlongTheOuterOutlineOnly(): void
    {
        $set = self::set()
            ->add('plaza', RegionShape::rect(0.0, 0.0, 10.0, 6.0))
            ->add('lawn', RegionShape::rect(8.0, 0.0, 6.0, 6.0))
            ->border('plaza', 0.5, 'plaza_border');
        $parts = $set->build();
        // Plaza 10 × 6; the border runs on the three sides away from the lawn: 0.5 × (6 + 10 + 10) minus the two inner corners.
        $expected = 0.5 * (6.0 + 10.0 + 10.0) - 2 * 0.25;
        self::assertEqualsWithDelta($expected, ConformingRegionMeshTest::areaXz($parts['plaza_border']), 0.05);
        self::assertEqualsWithDelta(60.0 - $expected, ConformingRegionMeshTest::areaXz($parts['plaza']), 0.05);
        $v = $parts['plaza_border']->vertices;
        for ($i = 0, $n = count($v); $i < $n; $i += 3) {
            self::assertLessThanOrEqual(5.0 + 1e-6, $v[$i], 'no border at the plaza–lawn joint');
        }
    }

    public function testInlaysReplaceTheSurfaceByLayer(): void
    {
        $set = self::set()
            ->add('paving', RegionShape::rect(0.0, 0.0, 8.0, 8.0))
            ->inlay('ring', RegionShape::circle(0.0, 0.0, 1.5), layer: 1)
            ->inlay('disc', RegionShape::circle(0.0, 0.0, 1.0));
        $parts = $set->build();
        $disc = ConformingRegionMeshTest::areaXz($parts['disc']);
        $ring = ConformingRegionMeshTest::areaXz($parts['ring']);
        self::assertEqualsWithDelta(M_PI, $disc, 0.15, 'the lower layer wins although added later');
        self::assertEqualsWithDelta(M_PI * (2.25 - 1.0), $ring, 0.2);
        self::assertEqualsWithDelta(64.0, ConformingRegionMeshTest::areaXz($parts['paving']) + $disc + $ring, 1e-6);
    }

    public function testCutsKeepEverythingOut(): void
    {
        $set = self::set()
            ->add('quay', RegionShape::rect(0.0, 0.0, 10.0, 6.0))
            ->cut(RegionShape::rect(0.0, 4.0, 12.0, 4.0)); // z ≥ 2 is water
        foreach ($set->build() as $part => $m) {
            $v = $m->vertices;
            for ($i = 0, $n = count($v); $i < $n; $i += 3) {
                self::assertLessThanOrEqual(2.0 + 1e-6, $v[$i + 2], "{$part} reaches into the cut");
            }
        }
    }

    public function testUvScalePerPart(): void
    {
        $set = self::set()->add('a', RegionShape::circle(0.0, 0.0, 2.0))->uvScale('a', 0.5);
        $m = $set->build()['a'];
        self::assertEqualsWithDelta($m->vertices[0] * 0.5, $m->uvs[0], 1e-12);
        $hem = $set->build()[ConformingSurfaceSet::hemOf('a')];
        self::assertEqualsWithDelta($hem->vertices[0], $hem->uvs[0], 1e-12, 'default scale elsewhere');
    }

    public function testNoHemLeavesABareEdgeAtTheLift(): void
    {
        $set = new ConformingSurfaceSet(static fn (float $x, float $z): float => 0.0, cell: 0.5, lift: 0.04, hemWidth: 0.0);
        $parts = $set->add('slab', RegionShape::rect(0.0, 0.0, 2.0, 2.0))->build();
        self::assertSame(['slab'], array_keys($parts));
        self::assertEqualsWithDelta(0.04, SurfaceFit::measure($parts['slab'], $set->ground())->openEdgeMax, 1e-9);
    }

    public function testPartNamesMustNotCollide(): void
    {
        $set = self::set()->add('paving', RegionShape::circle(0.0, 0.0, 1.0));
        $this->expectException(\InvalidArgumentException::class);
        $set->inlay('paving', RegionShape::circle(0.0, 0.0, 0.5));
    }

    public function testEmptySetBuildsNothing(): void
    {
        self::assertSame([], self::set()->build());
    }
}
