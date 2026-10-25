<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\ConformingRegionMesh;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\RegionShape;
use PHPUnit\Framework\TestCase;

final class ConformingRegionMeshTest extends TestCase
{
    public function testCoveringSnapsTheOriginToTheCell(): void
    {
        $g = ConformingRegionMesh::covering(1.3, -2.7, 5.1, 3.2, 0.5, 0.4);
        self::assertSame(0.5, $g->x0);
        self::assertSame(-3.5, $g->z0);
        self::assertGreaterThanOrEqual(5.5, $g->gx($g->nx));
        self::assertGreaterThanOrEqual(3.6, $g->gz($g->nz));
    }

    public function testStampKeepsTheMinimum(): void
    {
        $g = new ConformingRegionMesh(0.0, 0.0, 4.0, 4.0, 1.0);
        $g->stamp('f', 0.0, 0.0, 4.0, 4.0, static fn (float $x, float $z): float => 5.0);
        $g->stamp('f', 1.0, 1.0, 2.0, 2.0, static fn (float $x, float $z): float => -1.0);
        self::assertSame(5.0, $g->valueAt('f', 0, 0));
        self::assertSame(-1.0, $g->valueAt('f', 1, 1));
        self::assertSame(ConformingRegionMesh::FAR, $g->valueAt('nothing', 1, 1));
        self::assertTrue($g->has('f'));
        self::assertFalse($g->has('nothing'));
    }

    public function testCircleAreaAndEveryVertexOnTheGroundPlusLift(): void
    {
        $ground = static fn (float $x, float $z): float => 0.3 * sin($x * 0.4) + 0.2 * cos($z * 0.3);
        $g = ConformingRegionMesh::covering(-6.0, -6.0, 6.0, 6.0, 0.5, 1.0);
        $g->stampShape('d', RegionShape::circle(0.0, 0.0, 5.0), 1.0);
        $m = $g->mesh([['d', 1]], ConformingRegionMesh::onGround($ground, 0.025));
        self::assertEqualsWithDelta(M_PI * 25.0, self::areaXz($m), 0.3, 'polygonal outline within the grid');
        $v = $m->vertices;
        for ($i = 0, $n = count($v); $i < $n; $i += 3) {
            self::assertEqualsWithDelta($ground($v[$i], $v[$i + 2]) + 0.025, $v[$i + 1], 1e-12);
            self::assertLessThanOrEqual(5.0 + 1e-9, hypot($v[$i], $v[$i + 2]), 'no vertex outside the outline');
        }
    }

    public function testRegionsWithOppositeConditionsTileWithoutGap(): void
    {
        $g = ConformingRegionMesh::covering(-6.0, -6.0, 6.0, 6.0, 0.5, 1.0);
        $g->stampShape('outer', RegionShape::circle(0.0, 0.0, 5.0), 1.0);
        $g->stampShape('inner', RegionShape::circle(0.7, 0.3, 2.3), 1.0);
        $h = static fn (float $x, float $z, array $v): float => 0.0;
        $inner = $g->mesh([['outer', 1], ['inner', 1]], $h);
        $ring = $g->mesh([['outer', 1], ['inner', -1]], $h);
        $all = $g->mesh([['outer', 1]], $h);
        self::assertEqualsWithDelta(self::areaXz($all), self::areaXz($inner) + self::areaXz($ring), 1e-9, 'no gap, no overlap');

        // The cut line: every open edge of the inner disc is an open edge of the ring, vertex for vertex.
        $ringEdges = self::openEdges($ring);
        $innerEdges = self::openEdges($inner);
        self::assertNotEmpty($innerEdges);
        foreach ($innerEdges as $e => $_) {
            self::assertArrayHasKey($e, $ringEdges, "seam edge {$e} not shared");
        }
    }

    public function testSplitsGiveNeighboursTheSameVerticesOnTheirJoint(): void
    {
        $g = ConformingRegionMesh::covering(-6.0, -6.0, 6.0, 6.0, 0.5, 1.0);
        $g->stampShape('a', RegionShape::circle(-1.0, 0.0, 3.0), 1.0);
        $g->stampShape('b', RegionShape::circle(2.0, 0.3, 3.0), 1.0);
        $g->derive('u', ['a', 'b'], static fn (array $v): float => min($v['a'], $v['b']));
        $g->derive('n', ['a', 'b'], static fn (array $v): float => $v['a'] - $v['b']);
        $h = static fn (float $x, float $z, array $v): float => 0.0;
        // Inside u the two tops split along a = 0, the ring outside u along
        // a − b = 0 (nearest shape). Both lines meet the outline at slightly
        // different points: without splits each side lacks the other's point.
        $mk = static fn (array $splits): array => [
            $g->mesh([['u', 1], ['a', 1]], $h, [], 1.0, $splits),
            $g->mesh([['u', 1], ['a', -1]], $h, [], 1.0, $splits),
            $g->mesh([['u', -1], ['n', 1]], $h, [], 1.0, $splits),
            $g->mesh([['u', -1], ['n', -1]], $h, [], 1.0, $splits),
        ];
        $x1 = $g->gx($g->nx);
        $z1 = $g->gz($g->nz);
        // Open edges of the merged meshes that are NOT on the grid's outer rim.
        $inner = static function (array $meshes) use ($g, $x1, $z1): int {
            $n = 0;
            foreach (array_keys(self::openEdges(MeshData::merge(...$meshes))) as $e) {
                $rim = true;
                foreach (explode('|', $e) as $p) {
                    [$x, $z] = array_map('floatval', explode(':', $p));
                    $rim = $rim && (abs($x - $g->x0) < 1e-6 || abs($x - $x1) < 1e-6 || abs($z - $g->z0) < 1e-6 || abs($z - $z1) < 1e-6);
                }
                $n += $rim ? 0 : 1;
            }

            return $n;
        };
        $with = $mk(['u', 'a', 'n']);
        $without = $mk([]);
        self::assertSame(0, $inner($with), 'merged with splits, only the grid rim is open');
        self::assertGreaterThan(0, $inner($without), 'T-junctions where the two split lines meet the outline');
        self::assertEqualsWithDelta(self::areaXz(MeshData::merge(...$without)), self::areaXz(MeshData::merge(...$with)), 1e-9, 'splits add vertices, not area');
    }

    public function testTrianglesFaceUpWithUnitNormals(): void
    {
        $ground = static fn (float $x, float $z): float => 0.4 * $x - 0.2 * $z;
        $g = ConformingRegionMesh::covering(-3.0, -3.0, 3.0, 3.0, 0.5, 1.0);
        $g->stampShape('d', RegionShape::polyline([[-3.0, -2.0], [3.0, 2.0]], 1.0), 1.0);
        $m = $g->mesh([['d', 1]], ConformingRegionMesh::onGround($ground));
        self::assertGreaterThan(10, $m->triangleCount());
        $v = $m->vertices;
        $n = $m->normals;
        $idx = $m->indices;
        for ($t = 0, $c = count($idx); $t < $c; $t += 3) {
            [$a, $b, $d] = [$idx[$t] * 3, $idx[$t + 1] * 3, $idx[$t + 2] * 3];
            $ux = $v[$b] - $v[$a];
            $uy = $v[$b + 1] - $v[$a + 1];
            $uz = $v[$b + 2] - $v[$a + 2];
            $wx = $v[$d] - $v[$a];
            $wy = $v[$d + 1] - $v[$a + 1];
            $wz = $v[$d + 2] - $v[$a + 2];
            $fx = $uy * $wz - $uz * $wy;
            $fy = $uz * $wx - $ux * $wz;
            $fz = $ux * $wy - $uy * $wx;
            self::assertGreaterThan(0.0, $fy, 'counter-clockwise seen from above');
            self::assertGreaterThan(0.0, $fx * $n[$a] + $fy * $n[$a + 1] + $fz * $n[$a + 2], 'winding agrees with the normal');
        }
        // A plane: every normal is the plane normal.
        $len = sqrt(0.4 ** 2 + 1.0 + 0.2 ** 2);
        for ($i = 0, $c = count($n); $i < $c; $i += 3) {
            self::assertEqualsWithDelta(-0.4 / $len, $n[$i], 1e-9);
            self::assertEqualsWithDelta(1.0 / $len, $n[$i + 1], 1e-9);
            self::assertEqualsWithDelta(0.2 / $len, $n[$i + 2], 1e-9);
        }
    }

    public function testUvIsWorldXzTimesScale(): void
    {
        $g = ConformingRegionMesh::covering(0.0, 0.0, 4.0, 4.0, 0.5, 1.0);
        $g->stampShape('d', RegionShape::rect(2.0, 2.0, 3.0, 3.0), 1.0);
        $m = $g->mesh([['d', 1]], ConformingRegionMesh::onGround(static fn (float $x, float $z): float => 0.0), [], 0.25);
        $v = $m->vertices;
        $uv = $m->uvs;
        self::assertSame(count($v) / 3 * 2, count($uv));
        for ($i = 0, $k = 0, $n = count($v); $i < $n; $i += 3, $k += 2) {
            self::assertEqualsWithDelta($v[$i] * 0.25, $uv[$k], 1e-12);
            self::assertEqualsWithDelta($v[$i + 2] * 0.25, $uv[$k + 1], 1e-12);
        }
    }

    public function testHemFallsFromTheLiftBelowTheGround(): void
    {
        $ground = static fn (float $x, float $z): float => 1.0 + 0.1 * $x;
        $g = ConformingRegionMesh::covering(-4.0, -4.0, 4.0, 4.0, 0.25, 1.0);
        $g->stampShape('d', RegionShape::circle(0.0, 0.0, 3.0), 1.0);
        $g->derive('o', ['d'], static fn (array $v): float => $v['d'] - 0.4);
        $m = $g->mesh([['o', 1], ['d', -1]], ConformingRegionMesh::hem($ground, 'd', 0.03, 0.4, 0.05));
        $lo = INF;
        $hi = -INF;
        $v = $m->vertices;
        for ($i = 0, $n = count($v); $i < $n; $i += 3) {
            $d = $v[$i + 1] - $ground($v[$i], $v[$i + 2]);
            $lo = min($lo, $d);
            $hi = max($hi, $d);
        }
        self::assertEqualsWithDelta(-0.05, $lo, 1e-9);
        self::assertEqualsWithDelta(0.03, $hi, 1e-9);
    }

    public function testOnlyOutsideConditionsVisitTheWholeGrid(): void
    {
        $g = new ConformingRegionMesh(0.0, 0.0, 4.0, 4.0, 1.0);
        $g->stampShape('hole', RegionShape::circle(2.0, 2.0, 1.0), 1.0);
        $m = $g->mesh([['hole', -1]], static fn (float $x, float $z, array $v): float => 0.0);
        self::assertEqualsWithDelta(16.0 - M_PI, self::areaXz($m), 0.5);
    }

    public function testInvalidInputThrows(): void
    {
        $g = new ConformingRegionMesh(0.0, 0.0, 1.0, 1.0, 0.5);
        $this->expectException(\InvalidArgumentException::class);
        $g->mesh([['d', 2]], static fn (float $x, float $z, array $v): float => 0.0);
    }

    public function testEmptyGridThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConformingRegionMesh(1.0, 0.0, 1.0, 1.0, 0.5);
    }

    public static function areaXz(MeshData $m): float
    {
        $v = $m->vertices;
        $idx = $m->indices;
        $a = 0.0;
        for ($t = 0, $n = count($idx); $t < $n; $t += 3) {
            [$p, $q, $r] = [$idx[$t] * 3, $idx[$t + 1] * 3, $idx[$t + 2] * 3];
            $a += abs(($v[$q] - $v[$p]) * ($v[$r + 2] - $v[$p + 2]) - ($v[$r] - $v[$p]) * ($v[$q + 2] - $v[$p + 2])) / 2.0;
        }

        return $a;
    }

    /**
     * Edges used by exactly one triangle, keyed by their rounded XZ endpoints.
     *
     * @return array<string, true>
     */
    public static function openEdges(MeshData $m): array
    {
        $v = $m->vertices;
        $idx = $m->indices;
        $key = static fn (int $i): string => sprintf('%.5f:%.5f', round($v[$i * 3], 5) + 0.0, round($v[$i * 3 + 2], 5) + 0.0);
        $count = [];
        for ($t = 0, $n = count($idx); $t < $n; $t += 3) {
            foreach ([[0, 1], [1, 2], [2, 0]] as [$a, $b]) {
                $ka = $key($idx[$t + $a]);
                $kb = $key($idx[$t + $b]);
                $e = $ka < $kb ? "{$ka}|{$kb}" : "{$kb}|{$ka}";
                $count[$e] = ($count[$e] ?? 0) + 1;
            }
        }

        return array_map(static fn (int $c): bool => true, array_filter($count, static fn (int $c): bool => $c === 1));
    }
}
