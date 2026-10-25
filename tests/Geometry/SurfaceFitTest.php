<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\SurfaceFit;
use PHPUnit\Framework\TestCase;

final class SurfaceFitTest extends TestCase
{
    public function testFloatingSlabShowsItsLiftAndEdge(): void
    {
        // A 2 × 2 quad 0.1 above flat ground, sloped to 0.2 at x = 1.
        $quad = new MeshData(
            [-1.0, 0.1, -1.0, 1.0, 0.2, -1.0, 1.0, 0.2, 1.0, -1.0, 0.1, 1.0],
            array_fill(0, 12, 0.0),
            array_fill(0, 8, 0.0),
            [0, 2, 1, 0, 3, 2],
        );
        $fit = SurfaceFit::measure($quad, static fn (float $x, float $z): float => 0.0, above: 0.15);
        self::assertSame(2, $fit->triangles);
        self::assertSame(14, $fit->samples);
        self::assertEqualsWithDelta(0.2, $fit->liftMax, 1e-12);
        self::assertEqualsWithDelta(0.1, $fit->liftMin, 1e-12);
        self::assertNotNull($fit->liftMaxAt);
        self::assertEqualsWithDelta(1.0, $fit->liftMaxAt[0], 1e-12);
        self::assertSame(4, $fit->openEdges, 'the diagonal is shared');
        self::assertEqualsWithDelta(0.2, $fit->openEdgeMax, 1e-12);
        self::assertGreaterThan(0.0, $fit->shareAbove);
        self::assertSame(0.0, $fit->shareBelow);
        self::assertTrue($fit->within(0.25, 0.0));
        self::assertFalse($fit->within(0.25, 0.0, 0.03), 'a 20 cm slab edge is visible');
    }

    public function testVerticalFacesAndUnknownGroundAreSkipped(): void
    {
        $box = BoxMesh::generate(1.0, 1.0, 1.0);
        $fit = SurfaceFit::measure($box, static fn (float $x, float $z): ?float => $x > 0.4 ? null : -0.5);
        // Only top and bottom count (4 triangles); samples at x > 0.4 have no ground.
        self::assertSame(12, $fit->triangles);
        self::assertGreaterThan(0, $fit->samples);
        self::assertLessThan(28, $fit->samples);
        self::assertEqualsWithDelta(1.0, $fit->liftMax, 1e-12);
        self::assertEqualsWithDelta(0.0, $fit->liftMin, 1e-12);
        // The walls do not count, so the rims of top and bottom are the open edges – a wall's top is a visible edge.
        self::assertSame(8, $fit->openEdges);
        self::assertEqualsWithDelta(1.0, $fit->openEdgeMax, 1e-12);
    }

    public function testNoSamplesIsNeverWithin(): void
    {
        $fit = SurfaceFit::measure(new MeshData([], [], [], []), static fn (float $x, float $z): float => 0.0);
        self::assertSame(0, $fit->samples);
        self::assertFalse($fit->within(1.0, -1.0));
    }
}
