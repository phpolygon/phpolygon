<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\DiscMesh;
use PHPolygon\Geometry\EllipsoidShell;
use PHPolygon\Geometry\MeshAccumulator;
use PHPolygon\Geometry\PlanarFrame;
use PHPolygon\Geometry\PrismClip;
use PHPUnit\Framework\TestCase;

final class MeshAccumulatorTest extends TestCase
{
    private static function frame(): PlanarFrame
    {
        return new PlanarFrame(10.0, -4.0, -0.951057, 0.309017);
    }

    public function testFrameRoundTripAndAxes(): void
    {
        $f = self::frame();
        [$x, $y, $z] = $f->point(3.0, 7.0, -2.0);
        [$u, $w] = $f->local($x, $z);
        self::assertEqualsWithDelta(3.0, $u, 1e-9);
        self::assertEqualsWithDelta(-2.0, $w, 1e-9);
        self::assertSame(7.0, $y);
        $a = $f->uAxis();
        $b = $f->wAxis();
        self::assertEqualsWithDelta(0.0, $a[0] * $b[0] + $a[2] * $b[2], 1e-9);
    }

    public function testFrameRejectsZeroDirection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PlanarFrame(0.0, 0.0, 0.0, 0.0);
    }

    public function testQuadIsWoundTowardsTheHint(): void
    {
        foreach ([1.0, -1.0] as $dir) {
            $m = new MeshAccumulator();
            $m->quad([0.0, 0.0, 0.0], [1.0, 0.0, 0.0], [1.0, 0.0, 1.0], [0.0, 0.0, 1.0], [0.0, $dir, 0.0]);
            $mesh = $m->build();
            self::assertSame(2, $mesh->triangleCount());
            self::assertSame(6, $mesh->vertexCount());
            self::assertEqualsWithDelta($dir, $mesh->normals[1], 1e-9);
            self::assertSame($dir > 0 ? 1.0 : -1.0, self::windingY($mesh->vertices, $mesh->indices, 0));
        }
    }

    public function testDegenerateFacesAreSkipped(): void
    {
        $m = new MeshAccumulator();
        $m->fan([[0.0, 0.0, 0.0], [1.0, 0.0, 0.0], [2.0, 0.0, 0.0]], [0.0, 1.0, 0.0]);
        self::assertSame(0, $m->build()->triangleCount());
    }

    public function testUvScaleIsMetric(): void
    {
        $m = new MeshAccumulator(1.0);
        $m->quad([2.0, 0.0, 4.0], [3.0, 0.0, 4.0], [3.0, 0.0, 5.0], [2.0, 0.0, 5.0], [0.0, 1.0, 0.0]);
        $mesh = $m->build();
        self::assertEqualsWithDelta(2.0, $mesh->uvs[0], 1e-9);
        self::assertEqualsWithDelta(4.0, $mesh->uvs[1], 1e-9);
    }

    public function testBoxIsClosedAndFacesOutward(): void
    {
        $f = self::frame();
        $m = new MeshAccumulator();
        $m->box($f, -1.0, 2.0, -1.5, 1.5, 0.0, 3.0);
        $mesh = $m->build();
        self::assertSame(12, $mesh->triangleCount());
        $centre = $f->point(0.5, 1.5, 0.0);
        self::assertSame(12, self::outwardTriangles($mesh->vertices, $mesh->indices, $centre));
    }

    public function testInwardWallsFaceTheAxis(): void
    {
        $f = self::frame();
        $m = new MeshAccumulator();
        $m->walls($f, -1.0, 1.0, -1.0, 1.0, 0.0, 2.0, true);
        $mesh = $m->build();
        self::assertSame(8, $mesh->triangleCount());
        self::assertSame(0, self::outwardTriangles($mesh->vertices, $mesh->indices, $f->point(0.0, 1.0, 0.0)));
    }

    public function testBevelFacesOutwardAndUp(): void
    {
        $f = self::frame();
        $m = new MeshAccumulator();
        $m->bevel($f, [-1.0, 1.0, 1.0], 1.0, [-2.0, 2.0, 2.0], 0.0);
        $mesh = $m->build();
        self::assertSame(8, $mesh->triangleCount());
        for ($t = 0; $t < $mesh->vertexCount(); $t++) {
            self::assertGreaterThan(0.0, $mesh->normals[$t * 3 + 1]);
        }
    }

    public function testPrismClipCutsOutTheHoleAndKeepsTheRest(): void
    {
        $clip = new PrismClip(self::frame(), 3.0);
        $f = $clip->frame;
        $poly = [$f->point(-10.0, 0.0, -10.0), $f->point(10.0, 0.0, -10.0), $f->point(10.0, 0.0, 10.0), $f->point(-10.0, 0.0, 10.0)];
        $pieces = $clip->outside($poly);
        self::assertCount(4, $pieces);
        $area = 0.0;
        foreach ($pieces as $piece) {
            self::assertGreaterThanOrEqual(3, count($piece));
            $area += self::areaXz($piece);
            foreach ($piece as $p) {
                [$u, $w] = $f->local($p[0], $p[2]);
                self::assertTrue(abs($u) >= 3.0 - 1e-9 || abs($w) >= 3.0 - 1e-9);
            }
        }
        self::assertEqualsWithDelta(400.0 - 36.0, $area, 1e-6);
    }

    public function testPrismClipLeavesUntouchedPolygonsWhole(): void
    {
        $clip = new PrismClip(self::frame(), 3.0);
        $f = $clip->frame;
        $beside = [$f->point(5.0, 0.0, 5.0), $f->point(7.0, 0.0, 5.0), $f->point(6.0, 0.0, 7.0)];
        self::assertSame([$beside], $clip->outside($beside));
        $far = [$f->point(500.0, 0.0, 5.0), $f->point(502.0, 0.0, 5.0), $f->point(501.0, 0.0, 7.0)];
        self::assertSame([$far], $clip->outside($far));
    }

    public function testClipHalfSpaceInterpolatesY(): void
    {
        $out = PrismClip::clipHalfSpace([[0.0, 0.0, 0.0], [2.0, 4.0, 0.0], [2.0, 4.0, 2.0]], 1.0, 0.0, -1.0);
        self::assertSame(4, count($out));
        self::assertEqualsWithDelta(2.0, $out[0][1], 1e-9);
    }

    public function testShellPointsLieOnTheEllipsoid(): void
    {
        $s = new EllipsoidShell(5.0, -2.0, 7.0, 20.0, 8.0, 16, 8);
        for ($j = 0; $j <= 8; $j++) {
            for ($k = 0; $k < 16; $k++) {
                [$x, $y, $z] = $s->point($k, $j);
                $q = (($x - 5.0) / 20.0) ** 2 + (($y + 2.0) / 8.0) ** 2 + (($z - 7.0) / 20.0) ** 2;
                self::assertEqualsWithDelta(1.0, $q, 1e-9);
            }
        }
        self::assertSame($s->point(0, 3), $s->point(16, 3));
        [$nx, $ny, $nz] = $s->normal(...$s->point(3, 5));
        self::assertEqualsWithDelta(1.0, sqrt($nx * $nx + $ny * $ny + $nz * $nz), 1e-9);
    }

    public function testShellTopAndPaneLookup(): void
    {
        $s = new EllipsoidShell(5.0, -2.0, 7.0, 20.0, 8.0, 16, 8);
        self::assertEqualsWithDelta(6.0, $s->topAt(5.0, 7.0) ?? -99.0, 1e-9);
        self::assertNull($s->topAtRadius(20.0));
        self::assertNull($s->upperPaneAt(40.0, 7.0));
        // A point on a pane's own surface lies in that pane (use the pane centre).
        [$k, $b] = [3, 5];
        $a = $s->point($k, $b);
        $c = $s->point($k + 1, $b + 1);
        $found = $s->upperPaneAt(($a[0] + $c[0]) / 2.0, ($a[2] + $c[2]) / 2.0);
        self::assertSame([$k, $b], $found);
    }

    public function testShellBuildCountsAndSelection(): void
    {
        $s = new EllipsoidShell(0.0, 0.0, 0.0, 10.0, 4.0, 8, 6);
        // 2 polar caps (1 tri) + 4 quad bands (2 tris) per azimuth segment.
        self::assertSame(8 * (1 + 1 + 4 * 2), $s->build()->triangleCount());
        self::assertSame(8 * (1 + 2 * 2), $s->build(null, false)->triangleCount());
        self::assertSame(2 * (1 + 2 * 2), $s->build(static fn (int $k, int $b): bool => $k < 2, false)->triangleCount());
    }

    public function testShellNormalsFaceOutward(): void
    {
        $s = new EllipsoidShell(0.0, 0.0, 0.0, 10.0, 4.0, 12, 6);
        $mesh = $s->build();
        self::assertSame($mesh->triangleCount(), self::outwardTriangles($mesh->vertices, $mesh->indices, [0.0, 0.0, 0.0]));
    }

    public function testShellPierceCutsOnlyTheUpperHalf(): void
    {
        $s = new EllipsoidShell(0.0, 0.0, 0.0, 40.0, 16.0, 16, 8);
        $clip = new PrismClip(new PlanarFrame(0.0, 0.0, 1.0, 0.0), 4.0);
        $whole = $s->build(null, true);
        $pierced = $s->build(null, true, $clip);
        self::assertNotSame($whole->triangleCount(), $pierced->triangleCount());
        // The lower half is untouched; the upper half has no triangle centre inside the prism.
        for ($t = 0; $t < $pierced->triangleCount(); $t++) {
            $cx = $cz = $cy = 0.0;
            for ($c = 0; $c < 3; $c++) {
                $i = $pierced->indices[$t * 3 + $c];
                $cx += $pierced->vertices[$i * 3] / 3.0;
                $cy += $pierced->vertices[$i * 3 + 1] / 3.0;
                $cz += $pierced->vertices[$i * 3 + 2] / 3.0;
            }
            if ($cy > 0.0) {
                self::assertFalse(abs($cx) < 4.0 - 1e-6 && abs($cz) < 4.0 - 1e-6);
            }
        }
    }

    public function testShellRejectsBadParameters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EllipsoidShell(0.0, 0.0, 0.0, 10.0, 4.0, 8, 5);
    }

    public function testDiscCountsAndHole(): void
    {
        $solid = DiscMesh::generate(1.0, 2.0, 10.0, 12, 0.5, 0.0);
        self::assertSame(12 * 4, $solid->triangleCount());
        $clip = new PrismClip(new PlanarFrame(1.0, 2.0, 1.0, 0.0), 1.5);
        $holed = DiscMesh::generate(1.0, 2.0, 10.0, 12, 0.5, 0.0, $clip);
        self::assertGreaterThan($solid->triangleCount(), $holed->triangleCount());
        // Top area = disc minus hole.
        $area = 0.0;
        for ($t = 0; $t < $holed->triangleCount(); $t++) {
            $p = [];
            for ($c = 0; $c < 3; $c++) {
                $i = $holed->indices[$t * 3 + $c];
                $p[] = [$holed->vertices[$i * 3], $holed->vertices[$i * 3 + 1], $holed->vertices[$i * 3 + 2]];
            }
            if ($p[0][1] === 0.5 && $p[1][1] === 0.5 && $p[2][1] === 0.5) {
                $area += self::areaXz($p);
            }
        }
        self::assertEqualsWithDelta(0.5 * 12 * 100.0 * sin(2.0 * M_PI / 12) - 9.0, $area, 1e-6);
    }

    /**
     * @param list<float> $vertices
     * @param list<int> $indices
     */
    private static function windingY(array $vertices, array $indices, int $tri): float
    {
        [$a, $b, $c] = [$indices[$tri * 3], $indices[$tri * 3 + 1], $indices[$tri * 3 + 2]];
        $ux = $vertices[$b * 3] - $vertices[$a * 3];
        $uz = $vertices[$b * 3 + 2] - $vertices[$a * 3 + 2];
        $wx = $vertices[$c * 3] - $vertices[$a * 3];
        $wz = $vertices[$c * 3 + 2] - $vertices[$a * 3 + 2];

        return ($uz * $wx - $ux * $wz) > 0.0 ? 1.0 : -1.0;
    }

    /**
     * Triangles whose geometric normal points away from `$centre`.
     *
     * @param list<float> $vertices
     * @param list<int> $indices
     * @param array{0: float, 1: float, 2: float} $centre
     */
    private static function outwardTriangles(array $vertices, array $indices, array $centre): int
    {
        $count = 0;
        for ($t = 0; $t < intdiv(count($indices), 3); $t++) {
            $p = [];
            for ($c = 0; $c < 3; $c++) {
                $i = $indices[$t * 3 + $c];
                $p[] = [$vertices[$i * 3], $vertices[$i * 3 + 1], $vertices[$i * 3 + 2]];
            }
            $u = [$p[1][0] - $p[0][0], $p[1][1] - $p[0][1], $p[1][2] - $p[0][2]];
            $w = [$p[2][0] - $p[0][0], $p[2][1] - $p[0][1], $p[2][2] - $p[0][2]];
            $n = [$u[1] * $w[2] - $u[2] * $w[1], $u[2] * $w[0] - $u[0] * $w[2], $u[0] * $w[1] - $u[1] * $w[0]];
            $mid = [($p[0][0] + $p[1][0] + $p[2][0]) / 3.0 - $centre[0], ($p[0][1] + $p[1][1] + $p[2][1]) / 3.0 - $centre[1], ($p[0][2] + $p[1][2] + $p[2][2]) / 3.0 - $centre[2]];
            if ($n[0] * $mid[0] + $n[1] * $mid[1] + $n[2] * $mid[2] > 0.0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param list<array{0: float, 1: float, 2: float}> $poly
     */
    private static function areaXz(array $poly): float
    {
        $sum = 0.0;
        $n = count($poly);
        for ($i = 0; $i < $n; $i++) {
            $sum += $poly[$i][0] * $poly[($i + 1) % $n][2] - $poly[($i + 1) % $n][0] * $poly[$i][2];
        }

        return abs($sum) * 0.5;
    }
}

