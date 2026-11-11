<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\ArrayRun;
use PHPolygon\Geometry\BevelBoxMesh;
use PHPolygon\Geometry\MeshCleanup;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MetricMesh;
use PHPolygon\Geometry\PolygonExtrude;
use PHPolygon\Geometry\Profile;
use PHPolygon\Geometry\WallOpening;
use PHPolygon\Geometry\WallWithOpenings;
use PHPolygon\Math\Vec2;
use PHPolygon\Math\Vec3;
use PHPUnit\Framework\TestCase;

final class GeometryKitTest extends TestCase
{
    private function assertSound(MeshData $m, bool $closed = true): void
    {
        self::assertGreaterThan(0, $m->triangleCount());
        $vc = $m->vertexCount();
        self::assertCount($vc * 3, $m->normals);
        self::assertCount($vc * 2, $m->uvs);
        self::assertNotNull($m->tangents);
        self::assertCount($vc * 4, $m->tangents);
        foreach ($m->indices as $i) {
            self::assertLessThan($vc, $i);
        }
        for ($i = 0; $i < $vc; $i++) {
            $len = sqrt($m->normals[$i * 3] ** 2 + $m->normals[$i * 3 + 1] ** 2 + $m->normals[$i * 3 + 2] ** 2);
            self::assertEqualsWithDelta(1.0, $len, 1e-6);
        }
        $topo = MeshCleanup::topology($m);
        self::assertSame(0, $topo['nonManifoldEdges']);
        self::assertSame(0, $topo['flippedEdges'], 'consistent winding');
        self::assertSame(0, $topo['degenerateTriangles']);
        if ($closed) {
            self::assertSame(0, $topo['openEdges'], 'closed');
        }
    }

    /** Signed volume of a closed mesh; positive when the winding faces outward. */
    private function volume(MeshData $m): float
    {
        $v = $m->vertices;
        $sum = 0.0;
        for ($t = 0; $t < count($m->indices); $t += 3) {
            [$a, $b, $c] = [$m->indices[$t] * 3, $m->indices[$t + 1] * 3, $m->indices[$t + 2] * 3];
            $sum += $v[$a] * ($v[$b + 1] * $v[$c + 2] - $v[$b + 2] * $v[$c + 1])
                - $v[$a + 1] * ($v[$b] * $v[$c + 2] - $v[$b + 2] * $v[$c])
                + $v[$a + 2] * ($v[$b] * $v[$c + 1] - $v[$b + 1] * $v[$c]);
        }
        return $sum / 6.0;
    }

    public function testBounds(): void
    {
        $b = MetricMesh::box(2.0, 4.0, 6.0)->bounds();
        self::assertEqualsWithDelta(-1.0, $b['min']->x, 1e-12);
        self::assertEqualsWithDelta(3.0, $b['max']->z, 1e-12);
        $e = (new MeshData([], [], [], []))->bounds();
        self::assertSame(0.0, $e['max']->y);
    }

    public function testExtrudeSquareWithHole(): void
    {
        $outline = Profile::rect(4.0, 4.0);
        $hole = Profile::rect(1.0, 1.0);
        $m = PolygonExtrude::generate($outline, 0.5, [$hole]);
        $this->assertSound($m);
        // (16 - 1) m2 * 0.5 m.
        self::assertEqualsWithDelta(7.5, $this->volume($m), 1e-9);
        // 8 side quads (16 tris) + caps of the 8-gon-with-hole (8 tris each).
        self::assertSame(16 + 16, $m->triangleCount());
    }

    public function testExtrudeConcaveAndWindingInsensitive(): void
    {
        // L shape, given clockwise: must still come out outward facing.
        $l = [new Vec2(0, 0), new Vec2(0, 2), new Vec2(1, 2), new Vec2(1, 1), new Vec2(2, 1), new Vec2(2, 0)];
        $m = PolygonExtrude::generate($l, 1.0);
        $this->assertSound($m);
        self::assertEqualsWithDelta(3.0, $this->volume($m), 1e-9);
    }

    public function testExtrudeManyHoles(): void
    {
        $holes = [];
        for ($i = 0; $i < 5; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $holes[] = array_map(static fn(Vec2 $p) => new Vec2($p->x - 3.5 + $i * 1.5, $p->y - 2.0 + $j * 1.5), Profile::archedRect(1.0, 1.0, 0.3, 6));
            }
        }
        $m = PolygonExtrude::generate(Profile::rect(8.0, 5.0), 0.3, $holes);
        $this->assertSound($m);
        self::assertGreaterThan(0.0, $this->volume($m));
        self::assertLessThan(40.0 * 0.3, $this->volume($m));
    }

    public function testRandomWallsStayClosedManifold(): void
    {
        $rng = new \PHPolygon\Math\Rng(2024);
        for ($round = 0; $round < 12; $round++) {
            $openings = [];
            $cols = $rng->int(1, 6);
            $rows = $rng->int(1, 4);
            for ($i = 0; $i < $cols; $i++) {
                for ($j = 0; $j < $rows; $j++) {
                    if ($rng->bool(0.2)) {
                        continue;
                    }
                    $w = $rng->floatRange(0.6, 1.1);
                    $h = $rng->floatRange(0.8, 1.6);
                    $openings[] = new WallOpening(
                        0.3 + $i * 1.6 + $rng->floatRange(0.0, 0.2),
                        0.3 + $j * 2.0 + $rng->floatRange(0.0, 0.2),
                        $w,
                        $h,
                        $rng->bool() ? $rng->floatRange(0.1, $w / 2) : 0.0,
                        $rng->int(4, 12),
                    );
                }
            }
            $wall = WallWithOpenings::parts($cols * 1.6 + 0.6, $rows * 2.0 + 0.6, 0.35, $openings)['wall'];
            $this->assertSound($wall);
        }
    }

    public function testWallWithArchedOpeningsReveals(): void
    {
        $openings = [
            new WallOpening(1.0, 1.0, 1.0, 1.5, archRise: 0.4, sillHeight: 0.06, lintelHeight: 0.1),
            new WallOpening(3.0, 1.0, 1.0, 1.5),
            new WallOpening(5.0, 0.3, 1.2, 2.2, archRise: 0.6, archSegments: 10),
        ];
        $parts = WallWithOpenings::parts(7.0, 3.5, 0.4, $openings);
        $this->assertSound($parts['wall']);
        self::assertNotNull($parts['sills']);
        self::assertNotNull($parts['lintels']);
        $full = WallWithOpenings::generate(7.0, 3.5, 0.4, $openings);
        self::assertSame($parts['wall']->triangleCount() + $parts['sills']->triangleCount() + $parts['lintels']->triangleCount(), $full->triangleCount());
        self::assertGreaterThan(0.0, $this->volume($parts['wall']));

        // Volume = slab minus openings (arch area = rect + circular segment).
        $area = 7.0 * 3.5 - 1.0 * 1.1 - 1.0 * 1.5 - 1.2 * 1.6;
        $arch = static function (float $w, float $h): float {
            $r = ($w * $w / 4 + $h * $h) / (2 * $h);
            $theta = 2 * asin(($w / 2) / $r);
            return $r * $r / 2 * ($theta - sin($theta));
        };
        $area -= $arch(1.0, 0.4) + $arch(1.2, 0.6);
        // Polygonal arcs sit slightly inside the true arc: tolerance 2 percent.
        self::assertEqualsWithDelta($area * 0.4, $this->volume($parts['wall']), $area * 0.4 * 0.02);

        // Reveals: the wall has vertices at depth 0 and 0.4 around each opening.
        $b = $parts['wall']->bounds();
        self::assertEqualsWithDelta(0.4, $b['max']->z, 1e-12);
        // World-metre UVs: front cap u spans the wall width.
        $us = [];
        for ($i = 0; $i < $parts['wall']->vertexCount(); $i++) {
            if ($parts['wall']->normals[$i * 3 + 2] > 0.5) {
                $us[] = $parts['wall']->uvs[$i * 2];
            }
        }
        self::assertEqualsWithDelta(7.0, max($us), 1e-9);
    }

    public function testWallRejectsBadOpenings(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WallWithOpenings::generate(4.0, 3.0, 0.3, [new WallOpening(0.5, 0.5, 2.0, 1.0), new WallOpening(2.0, 0.6, 1.0, 1.0)]);
    }

    public function testBevelBox(): void
    {
        $m = BevelBoxMesh::generate(2.0, 1.0, 3.0, 0.1, 3);
        self::assertSame(6 * 64, $m->vertexCount());
        $welded = MeshCleanup::weld($m, 1e-6, false, false);
        $this->assertSound($welded);
        $b = $m->bounds();
        self::assertEqualsWithDelta(1.0, $b['max']->x, 1e-9);
        self::assertEqualsWithDelta(1.5, $b['max']->z, 1e-9);
        // Slightly less volume than the sharp box (6 m3), more than a sphere-ish lower bound.
        $vol = $this->volume($welded);
        self::assertLessThan(6.0, $vol);
        self::assertGreaterThan(5.9, $vol);
    }

    public function testMetricBoxUvScaleIsWorldMetres(): void
    {
        $m = MetricMesh::box(2.0, 3.0, 5.0);
        $this->assertSound($m);
        self::assertEqualsWithDelta(30.0, $this->volume($m), 1e-9);
        // +X face (first 4 vertices): u over depth 5, v over height 3.
        $us = [$m->uvs[0], $m->uvs[2], $m->uvs[4], $m->uvs[6]];
        $vs = [$m->uvs[1], $m->uvs[3], $m->uvs[5], $m->uvs[7]];
        self::assertEqualsWithDelta(5.0, max($us) - min($us), 1e-12);
        self::assertEqualsWithDelta(3.0, max($vs) - min($vs), 1e-12);
        $p = MetricMesh::plane(4.0, 2.0, 2, 3, 0.5);
        $this->assertSound($p, false);
        self::assertSame(12, $p->triangleCount());
        self::assertEqualsWithDelta(2.0, max(array_map(static fn($i) => $p->uvs[$i * 2], range(0, $p->vertexCount() - 1))), 1e-12);
    }

    public function testArrayRunModes(): void
    {
        $from = new Vec3(0, 0, 0);
        $to = new Vec3(10, 0, 0);
        self::assertCount(5, ArrayRun::placements([$from, $to], count: 5));
        self::assertCount(11, ArrayRun::placements([$from, $to], spacing: 1.0));
        self::assertCount(4, ArrayRun::placements([$from, $to], spacing: 3.0));
        $fit = ArrayRun::placements([$from, $to], spacing: 3.0, fit: true);
        self::assertCount(4, $fit);
        self::assertEqualsWithDelta(10.0, $fit[3]['position']->x, 1e-12);
        $c = ArrayRun::placements([$from, $to], spacing: 3.0, centered: true);
        self::assertEqualsWithDelta(0.5, $c[0]['position']->x, 1e-12);

        $bar = MetricMesh::box(0.1, 1.0, 0.1);
        $run = ArrayRun::line($bar, $from, $to, count: 5, local: static fn(int $i) => null);
        self::assertSame(5 * $bar->triangleCount(), $run->triangleCount());
        self::assertNotNull($run->tangents);
        $b = $run->bounds();
        self::assertEqualsWithDelta(-0.05, $b['min']->x, 1e-9);
        self::assertEqualsWithDelta(10.05, $b['max']->x, 1e-9);
        $this->assertSound($run);
    }

    public function testArrayRunAlignsToPathAndAlternates(): void
    {
        // Path turns 90 degrees at (4,0,0); a long bar (local X = along path) stays on its leg.
        $path = [new Vec3(0, 0, 0), new Vec3(4, 0, 0), new Vec3(4, 0, 4)];
        $pl = ArrayRun::placements($path, spacing: 2.0);
        self::assertCount(5, $pl);
        self::assertEqualsWithDelta(1.0, $pl[3]['tangent']->z, 1e-12);
        $bar = MetricMesh::box(1.0, 0.2, 0.2);
        $run = ArrayRun::build($bar, $pl, true, static fn(int $i) => $i % 2 === 1 ? \PHPolygon\Math\Mat4::translation(0, 0.5, 0) : null);
        $b = $run->bounds();
        // The last bar is yaw-turned: it extends along Z, not X, at x = 4.
        self::assertEqualsWithDelta(4.1, $b['max']->x, 1e-9);
        self::assertEqualsWithDelta(4.5, $b['max']->z, 1e-9);
        self::assertEqualsWithDelta(0.6, $b['max']->y, 1e-9);
    }

    public function testWeldAndDegenerateRemoval(): void
    {
        $box = MetricMesh::box(1, 1, 1);
        $two = MeshData::merge($box, $box);
        $w = MeshCleanup::weld($two, 1e-6);
        self::assertSame(24, $w->vertexCount());
        self::assertSame(24, $w->triangleCount());

        // Position-only weld of a box collapses to 8 corners.
        $corners = MeshCleanup::weld($box, 1e-6, false, false);
        self::assertSame(8, $corners->vertexCount());
        self::assertSame(12, $corners->triangleCount());
        self::assertSame(['openEdges' => 0, 'nonManifoldEdges' => 0, 'flippedEdges' => 0, 'degenerateTriangles' => 0], MeshCleanup::topology($corners));

        $deg = new MeshData([0, 0, 0, 1, 0, 0, 2, 0, 0, 0, 1, 0, 5, 5, 5], [0, 0, 1, 0, 0, 1, 0, 0, 1, 0, 0, 1, 0, 0, 1], [0, 0, 1, 0, 2, 0, 0, 1, 9, 9], [0, 1, 2, 0, 1, 3, 3, 3, 0]);
        $clean = MeshCleanup::removeDegenerate($deg);
        self::assertSame(1, $clean->triangleCount());
        self::assertSame(3, $clean->vertexCount());
    }

    public function testDeterministicBytes(): void
    {
        $make = static fn() => serialize([
            WallWithOpenings::generate(6.0, 3.0, 0.3, [new WallOpening(1.0, 0.8, 1.0, 1.6, archRise: 0.4, sillHeight: 0.05)]),
            BevelBoxMesh::generate(1.0, 2.0, 1.0, 0.1),
            ArrayRun::line(MetricMesh::box(0.1, 1, 0.1), new Vec3(0, 0, 0), new Vec3(3, 0, 1), spacing: 0.5, fit: true),
        ]);
        self::assertSame(md5($make()), md5($make()));
    }
}
