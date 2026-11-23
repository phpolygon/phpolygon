<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\EllipseLoftMesh;
use PHPolygon\Geometry\HullLoftMesh;
use PHPolygon\Geometry\HullStation;
use PHPolygon\Geometry\LoftRing;
use PHPolygon\Geometry\MeshData;
use PHPUnit\Framework\TestCase;

final class HullLoftMeshTest extends TestCase
{
    /**
     * A small double-ender: z −3 … 3, keel at −1 (rising to −0.5 at the ends),
     * sheer 0.5 amidships and 0.9 at the bow, 5 points per station.
     *
     * @return list<HullStation>
     */
    private static function hull(int $count = 9): array
    {
        $stations = [];
        for ($k = 0; $k < $count; $k++) {
            $t = $k / ($count - 1);
            $z = -3.0 + 6.0 * $t;
            $e = abs($z) / 3.0;            // 0 amidships, 1 at the ends
            $beam = 1.0 * (1.0 - $e ** 2); // plan taper
            $keel = -1.0 + 0.5 * $e ** 2;
            $sheer = 0.5 + 0.4 * max(0.0, -$z / 3.0);
            $stations[] = new HullStation($z, [
                [0.0, $keel],
                [0.55 * $beam, $keel + 0.2],
                [0.85 * $beam, -0.1],
                [0.95 * $beam, 0.2],
                [1.0 * $beam, $sheer],
            ]);
        }

        return $stations;
    }

    public function testCountsAndArrays(): void
    {
        $mesh = HullLoftMesh::generate(self::hull());

        self::assertGreaterThan(0, $mesh->triangleCount());
        self::assertCount($mesh->vertexCount() * 3, $mesh->normals);
        self::assertCount($mesh->vertexCount() * 2, $mesh->uvs);
        foreach ($mesh->indices as $i) {
            self::assertLessThan($mesh->vertexCount(), $i);
        }
    }

    public function testIsMirrorSymmetric(): void
    {
        $mesh = HullLoftMesh::generate(self::hull(), wall: 0.05);
        $set = [];
        for ($i = 0; $i < $mesh->vertexCount(); $i++) {
            $set[sprintf('%.5f|%.5f|%.5f', abs($mesh->vertices[$i * 3]), $mesh->vertices[$i * 3 + 1], $mesh->vertices[$i * 3 + 2])]
                = ($set[sprintf('%.5f|%.5f|%.5f', abs($mesh->vertices[$i * 3]), $mesh->vertices[$i * 3 + 1], $mesh->vertices[$i * 3 + 2])] ?? 0) + 1;
        }
        foreach ($set as $key => $n) {
            [$x] = explode('|', $key);
            self::assertTrue($n % 2 === 0 || (float) $x === 0.0, "unpaired vertex {$key}");
        }
    }

    public function testBoundsFollowTheStations(): void
    {
        $mesh = HullLoftMesh::generate(self::hull());
        $minX = $maxX = $minY = $maxY = $minZ = $maxZ = 0.0;
        for ($i = 0; $i < $mesh->vertexCount(); $i++) {
            $x = $mesh->vertices[$i * 3];
            $y = $mesh->vertices[$i * 3 + 1];
            $z = $mesh->vertices[$i * 3 + 2];
            $minX = min($minX, $x);
            $maxX = max($maxX, $x);
            $minY = min($minY, $y);
            $maxY = max($maxY, $y);
            $minZ = min($minZ, $z);
            $maxZ = max($maxZ, $z);
        }
        self::assertEqualsWithDelta(-1.0, $minX, 1e-9);
        self::assertEqualsWithDelta(1.0, $maxX, 1e-9);
        self::assertEqualsWithDelta(-1.0, $minY, 1e-9);
        self::assertEqualsWithDelta(0.9, $maxY, 1e-9);
        self::assertEqualsWithDelta(-3.0, $minZ, 1e-9);
        self::assertEqualsWithDelta(3.0, $maxZ, 1e-9);
    }

    public function testNormalsPointOutwardAndWindingMatchesTheLoftConvention(): void
    {
        $mesh = HullLoftMesh::generate(self::hull(13));
        for ($i = 0; $i < $mesh->vertexCount(); $i++) {
            [$x, $y, $z] = [$mesh->vertices[$i * 3], $mesh->vertices[$i * 3 + 1], $mesh->vertices[$i * 3 + 2]];
            [$nx, $ny, $nz] = [$mesh->normals[$i * 3], $mesh->normals[$i * 3 + 1], $mesh->normals[$i * 3 + 2]];
            self::assertEqualsWithDelta(1.0, sqrt($nx * $nx + $ny * $ny + $nz * $nz), 1e-6);
            // Away from the hull's inside point on the same station (centre line, y = −0.2).
            if (abs($z) < 2.9) {
                self::assertGreaterThan(0.0, $nx * $x + $ny * ($y + 0.2), "vertex {$i} faces inward");
            }
        }
        self::assertTrue(self::windingMatchesNormals($mesh), 'cross product points into the solid like EllipseLoftMesh');

        $loft = EllipseLoftMesh::generate([LoftRing::ellipse(0.0, 1.0, 1.0), LoftRing::ellipse(1.0, 1.0, 1.0)], 8);
        self::assertTrue(self::windingMatchesNormals($loft), 'reference convention');
    }

    public function testKeelSharesOneNormalAcrossTheCentrePlane(): void
    {
        $mesh = HullLoftMesh::generate(self::hull());
        $found = 0;
        for ($i = 0; $i < $mesh->vertexCount(); $i++) {
            if (abs($mesh->vertices[$i * 3]) < 1e-9 && abs($mesh->vertices[$i * 3 + 2]) < 0.2) {
                $found++;
                self::assertEqualsWithDelta(0.0, $mesh->normals[$i * 3], 1e-6, 'keel normal has no sideways part');
                self::assertLessThan(-0.9, $mesh->normals[$i * 3 + 1]);
            }
        }
        self::assertGreaterThan(0, $found);
    }

    public function testRowBandsShareTheirBorder(): void
    {
        $stations = self::hull();
        $low = HullLoftMesh::generate($stations, 0, 2);
        $high = HullLoftMesh::generate($stations, 2, 4);
        $all = HullLoftMesh::generate($stations);
        self::assertSame($all->triangleCount(), $low->triangleCount() + $high->triangleCount());
    }

    public function testWallAddsInnerSkinAndRim(): void
    {
        $plain = HullLoftMesh::generate(self::hull(), 3, 4);
        $walled = HullLoftMesh::generate(self::hull(), 3, 4, 0.05);
        self::assertGreaterThan(2 * $plain->triangleCount(), $walled->triangleCount());
        // The rim faces up.
        $up = 0;
        for ($i = 0; $i < $walled->vertexCount(); $i++) {
            if ($walled->normals[$i * 3 + 1] > 0.99) {
                $up++;
            }
        }
        self::assertGreaterThan(0, $up);
    }

    public function testRejectsUnevenStationsAndUnsortedZ(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HullLoftMesh::generate([
            new HullStation(0.0, [[0.0, 0.0], [1.0, 1.0]]),
            new HullStation(1.0, [[0.0, 0.0]]),
        ]);
    }

    public function testNeedsTwoStations(): void
    {
        $mesh = HullLoftMesh::generate([new HullStation(0.0, [[0.0, 0.0], [1.0, 1.0]])]);
        self::assertSame(0, $mesh->triangleCount());
    }

    /** True when (b−a)×(c−a) of every triangle points against the averaged vertex normal. */
    private static function windingMatchesNormals(MeshData $mesh): bool
    {
        for ($t = 0; $t < count($mesh->indices); $t += 3) {
            [$a, $b, $c] = [$mesh->indices[$t], $mesh->indices[$t + 1], $mesh->indices[$t + 2]];
            $v = $mesh->vertices;
            $ux = $v[$b * 3] - $v[$a * 3];
            $uy = $v[$b * 3 + 1] - $v[$a * 3 + 1];
            $uz = $v[$b * 3 + 2] - $v[$a * 3 + 2];
            $wx = $v[$c * 3] - $v[$a * 3];
            $wy = $v[$c * 3 + 1] - $v[$a * 3 + 1];
            $wz = $v[$c * 3 + 2] - $v[$a * 3 + 2];
            $cx = $uy * $wz - $uz * $wy;
            $cy = $uz * $wx - $ux * $wz;
            $cz = $ux * $wy - $uy * $wx;
            $n = $mesh->normals;
            $nx = $n[$a * 3] + $n[$b * 3] + $n[$c * 3];
            $ny = $n[$a * 3 + 1] + $n[$b * 3 + 1] + $n[$c * 3 + 1];
            $nz = $n[$a * 3 + 2] + $n[$b * 3 + 2] + $n[$c * 3 + 2];
            if ($cx * $nx + $cy * $ny + $cz * $nz >= 0.0) {
                return false;
            }
        }

        return true;
    }
}
