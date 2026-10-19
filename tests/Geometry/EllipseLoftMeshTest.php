<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\EllipseLoftMesh;
use PHPolygon\Geometry\LatheMesh;
use PHPolygon\Geometry\LoftRing;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Math\Vec2;
use PHPUnit\Framework\TestCase;

final class EllipseLoftMeshTest extends TestCase
{
    /** @return list<LoftRing> */
    private static function rings(): array
    {
        return [
            new LoftRing(0.0, 0.3, 0.2, 0.25, 0.15, 0.05, -0.02),
            new LoftRing(0.5, 0.4, 0.4, 0.3, 0.35),
            LoftRing::ellipse(1.0, 0.2, 0.1, 0.0, 0.05),
        ];
    }

    public function testCountsFollowRingsCapsAndSegments(): void
    {
        $mesh = EllipseLoftMesh::generate(self::rings(), 12, 0.1, 0.2, 3);
        $rows = 3 + 2 * 3;

        self::assertSame(13 * $rows, $mesh->vertexCount());
        self::assertSame(12 * ($rows - 1) * 2, $mesh->triangleCount());
        self::assertCount($mesh->vertexCount() * 3, $mesh->normals);
        self::assertCount($mesh->vertexCount() * 2, $mesh->uvs);
    }

    public function testRingsReachTheirFourRadiiAboutTheirCentre(): void
    {
        $ring = new LoftRing(0.0, 0.3, 0.2, 0.25, 0.15, 0.05, -0.02);

        [$x, $z] = $ring->pointAt(M_PI_2);
        self::assertEqualsWithDelta(0.05 + 0.3, $x, 1e-12);
        self::assertEqualsWithDelta(-0.02, $z, 1e-12);
        [$x, $z] = $ring->pointAt(-M_PI_2);
        self::assertEqualsWithDelta(0.05 - 0.2, $x, 1e-12);
        [$x, $z] = $ring->pointAt(0.0);
        self::assertEqualsWithDelta(-0.02 + 0.25, $z, 1e-12);
        self::assertEqualsWithDelta(0.05, $x, 1e-12);
        [, $z] = $ring->pointAt(M_PI);
        self::assertEqualsWithDelta(-0.02 - 0.15, $z, 1e-12);
    }

    /** Up to a 3:1 ratio between opposite radii the outline never passes the smaller one. */
    public function testTheOutlineStaysWithinItsRadiiUpToThreeToOne(): void
    {
        $ring = new LoftRing(0.0, 0.3, 0.1, 0.3, 0.1);
        $minX = INF;
        $minZ = INF;
        for ($k = 0; $k < 720; $k++) {
            [$x, $z] = $ring->pointAt(2.0 * M_PI * $k / 720);
            $minX = min($minX, $x);
            $minZ = min($minZ, $z);
        }

        self::assertGreaterThanOrEqual(-0.1 - 1e-12, $minX);
        self::assertGreaterThanOrEqual(-0.1 - 1e-12, $minZ);
    }

    public function testCapsBulgePastTheEndRings(): void
    {
        $mesh = EllipseLoftMesh::generate(self::rings(), 16, 0.1, 0.2);
        $low = INF;
        $high = -INF;
        for ($i = 1, $n = count($mesh->vertices); $i < $n; $i += 3) {
            $low = min($low, $mesh->vertices[$i]);
            $high = max($high, $mesh->vertices[$i]);
        }

        self::assertEqualsWithDelta(-0.1, $low, 1e-9);
        self::assertEqualsWithDelta(1.2, $high, 1e-9);
    }

    public function testNormalsAreUnitAndPointOutward(): void
    {
        $mesh = EllipseLoftMesh::generate(self::rings(), 16, 0.1, 0.1);
        $centres = [];
        foreach (self::rings() as $ring) {
            $centres[(string) $ring->y] = [$ring->cx, $ring->cz];
        }

        for ($v = 0, $n = $mesh->vertexCount(); $v < $n; $v++) {
            $nx = $mesh->normals[$v * 3];
            $ny = $mesh->normals[$v * 3 + 1];
            $nz = $mesh->normals[$v * 3 + 2];
            self::assertEqualsWithDelta(1.0, sqrt($nx * $nx + $ny * $ny + $nz * $nz), 1e-9);

            $y = $mesh->vertices[$v * 3 + 1];
            if (isset($centres[(string) $y])) {
                [$cx, $cz] = $centres[(string) $y];
                $out = ($mesh->vertices[$v * 3] - $cx) * $nx + ($mesh->vertices[$v * 3 + 2] - $cz) * $nz;
                self::assertGreaterThan(0.0, $out, 'a side normal points into the loft');
            }
        }
    }

    public function testTheSeamSharesOneNormal(): void
    {
        $segments = 10;
        $mesh = EllipseLoftMesh::generate(self::rings(), $segments, 0.1, 0.1, 2);
        $rows = 3 + 4;
        $seam = $segments * $rows;
        for ($i = 0; $i < $rows; $i++) {
            for ($k = 0; $k < 3; $k++) {
                self::assertEqualsWithDelta($mesh->normals[$i * 3 + $k], $mesh->normals[($seam + $i) * 3 + $k], 1e-12);
                self::assertEqualsWithDelta($mesh->vertices[$i * 3 + $k], $mesh->vertices[($seam + $i) * 3 + $k], 1e-12);
            }
        }
    }

    /**
     * Lofts and lathes can be mixed in one model: the same face orientation
     * relative to the vertex normals.
     */
    public function testWindingMatchesLatheMesh(): void
    {
        $lathe = LatheMesh::generate([new Vec2(0.0, 0.0), new Vec2(0.3, 0.1), new Vec2(0.3, 0.9), new Vec2(0.0, 1.0)], 16);
        $loft = EllipseLoftMesh::generate([LoftRing::ellipse(0.1, 0.3, 0.3), LoftRing::ellipse(0.9, 0.3, 0.3)], 16, 0.1, 0.1);

        self::assertSame(self::windingSign($lathe), self::windingSign($loft));
    }

    public function testEmptyRingsGiveAnEmptyMesh(): void
    {
        self::assertSame(0, EllipseLoftMesh::generate([])->vertexCount());
    }

    /** -1 when every face's cross product opposes its vertex normals, +1 when it agrees. */
    private static function windingSign(MeshData $mesh): int
    {
        $agree = 0;
        $oppose = 0;
        $v = $mesh->vertices;
        $n = $mesh->normals;
        for ($t = 0, $c = count($mesh->indices); $t < $c; $t += 3) {
            [$a, $b, $d] = [$mesh->indices[$t] * 3, $mesh->indices[$t + 1] * 3, $mesh->indices[$t + 2] * 3];
            $u = [$v[$b] - $v[$a], $v[$b + 1] - $v[$a + 1], $v[$b + 2] - $v[$a + 2]];
            $w = [$v[$d] - $v[$a], $v[$d + 1] - $v[$a + 1], $v[$d + 2] - $v[$a + 2]];
            $f = [$u[1] * $w[2] - $u[2] * $w[1], $u[2] * $w[0] - $u[0] * $w[2], $u[0] * $w[1] - $u[1] * $w[0]];
            if (sqrt($f[0] ** 2 + $f[1] ** 2 + $f[2] ** 2) < 1e-12) {
                continue;
            }
            $dot = $f[0] * ($n[$a] + $n[$b] + $n[$d]) + $f[1] * ($n[$a + 1] + $n[$b + 1] + $n[$d + 1]) + $f[2] * ($n[$a + 2] + $n[$b + 2] + $n[$d + 2]);
            $dot > 0 ? $agree++ : $oppose++;
        }
        self::assertTrue($agree === 0 || $oppose === 0, 'mixed winding');

        return $agree > 0 ? 1 : -1;
    }
}
