<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\MeshCleanup;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MetricMesh;
use PHPolygon\Geometry\PolygonExtrude;
use PHPolygon\Geometry\Profile;
use PHPolygon\Geometry\RoundMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec2;
use PHPolygon\Math\Vec3;
use PHPUnit\Framework\TestCase;

/** RoundMesh bodies, PolygonExtrude axis, MeshData::axisRotated and mergeTransformed. */
final class RoundMeshTest extends TestCase
{
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

    private function assertSound(MeshData $m, bool $closed = true): void
    {
        $vc = $m->vertexCount();
        self::assertCount($vc * 3, $m->normals);
        self::assertCount($vc * 2, $m->uvs);
        self::assertNotNull($m->tangents);
        self::assertCount($vc * 4, $m->tangents);
        foreach ($m->tangents as $t) {
            self::assertTrue(is_finite($t));
        }
        for ($i = 0; $i < $vc; $i++) {
            $len = sqrt($m->normals[$i * 3] ** 2 + $m->normals[$i * 3 + 1] ** 2 + $m->normals[$i * 3 + 2] ** 2);
            self::assertEqualsWithDelta(1.0, $len, 1e-6);
        }
        $topo = MeshCleanup::topology($m);
        self::assertSame(0, $topo['nonManifoldEdges']);
        self::assertSame(0, $topo['flippedEdges']);
        if ($closed) {
            self::assertSame(0, $topo['openEdges']);
        }
    }

    public function testCylinderOnEveryAxis(): void
    {
        $polyArea = 24 / 2.0 * 0.5 ** 2 * sin(2.0 * M_PI / 24);
        foreach (['x' => 0, 'y' => 1, 'z' => 2] as $axis => $i) {
            $m = RoundMesh::cylinder(0.5, 2.0, $axis, 24);
            $this->assertSound($m);
            self::assertEqualsWithDelta($polyArea * 2.0, $this->volume($m), 1e-9, "axis $axis");
            $b = $m->bounds();
            $min = [$b['min']->x, $b['min']->y, $b['min']->z];
            $max = [$b['max']->x, $b['max']->y, $b['max']->z];
            for ($k = 0; $k < 3; $k++) {
                $half = $k === $i ? 1.0 : 0.5;
                self::assertEqualsWithDelta(-$half, $min[$k], 1e-9);
                self::assertEqualsWithDelta($half, $max[$k], 1e-9);
            }
        }
    }

    public function testCylinderMetricUvsAndOpenTube(): void
    {
        $m = RoundMesh::cylinder(0.5, 3.0, 'y', 16, false, 2.0);
        $this->assertSound($m, false);
        self::assertSame(34, $m->vertexCount(), 'no caps: only the 2 x 17 side vertices');
        $us = [];
        $vs = [];
        for ($i = 0; $i < count($m->uvs); $i += 2) {
            $us[] = $m->uvs[$i];
            $vs[] = $m->uvs[$i + 1];
        }
        self::assertEqualsWithDelta(2.0 * M_PI * 0.5 * 2.0, max($us), 1e-9, 'u = circumference in metres * uvScale');
        self::assertEqualsWithDelta(6.0, max($vs), 1e-9, 'v = metres along the axis * uvScale');
    }

    public function testSphereLatheTorusVolumes(): void
    {
        $sphere = RoundMesh::sphere(1.0, 24, 48);
        $this->assertSound(RoundMesh::sphere(1.0, 8, 12), true);
        self::assertEqualsWithDelta(4.0 / 3.0 * M_PI, $this->volume($sphere), 0.05);

        // A lathe closed on the axis: a cone of radius 1, height 2.
        $cone = RoundMesh::lathe([new Vec2(0.0, 0.0), new Vec2(1.0, 0.0), new Vec2(0.0, 2.0)], 48);
        self::assertEqualsWithDelta(M_PI * 2.0 / 3.0, $this->volume($cone), 0.03);

        $torus = RoundMesh::torus(2.0, 0.5, 48, 24);
        $this->assertSound($torus);
        self::assertEqualsWithDelta(2.0 * M_PI * M_PI * 2.0 * 0.25, $this->volume($torus), 0.2);
        $b = $torus->bounds();
        self::assertEqualsWithDelta(0.5, $b['max']->y, 1e-9, 'axis y: ring lies in XZ');
        $z = RoundMesh::torus(2.0, 0.5, 48, 24, 'z')->bounds();
        self::assertEqualsWithDelta(0.5, $z['max']->z, 1e-9);
    }

    public function testLatheFacesOutwardWhenProfileRunsUp(): void
    {
        // Profile bottom-up with the closing disc at the bottom (x 0 -> r) and the top (r -> 0).
        $profile = [new Vec2(0.0, -1.0), new Vec2(0.5, -1.0), new Vec2(0.5, 1.0), new Vec2(0.0, 1.0)];
        $m = RoundMesh::lathe($profile, 32);
        self::assertGreaterThan(0.0, $this->volume($m));
    }

    public function testInvalidAxisThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RoundMesh::cylinder(1.0, 1.0, 'w');
    }

    public function testAxisRotatedKeepsWindingAndMapsAxes(): void
    {
        $m = PolygonExtrude::generate(Profile::roundedRect(2.0, 1.0, 0.2), 0.5);
        $vol = $this->volume($m);
        self::assertGreaterThan(0.0, $vol);
        foreach ([0 => [0.5, 2.0, 1.0], 1 => [1.0, 0.5, 2.0], 2 => [2.0, 1.0, 0.5]] as $axis => $ext) {
            $r = PolygonExtrude::generate(Profile::roundedRect(2.0, 1.0, 0.2), 0.5, [], true, true, 1.0, ['x', 'y', 'z'][$axis]);
            $b = $r->bounds();
            self::assertEqualsWithDelta($ext[0], $b['max']->x - $b['min']->x, 1e-9);
            self::assertEqualsWithDelta($ext[1], $b['max']->y - $b['min']->y, 1e-9);
            self::assertEqualsWithDelta($ext[2], $b['max']->z - $b['min']->z, 1e-9);
            self::assertEqualsWithDelta($vol, $this->volume($r), 1e-9);
            $this->assertSound($r);
        }
    }

    public function testPolygonExtrudeAlongXHasHolesAndCapsOnThatAxis(): void
    {
        $m = PolygonExtrude::generate(Profile::rect(2.0, 2.0), 1.0, [Profile::circle(0.3, 12)], true, true, 1.0, 'x');
        $this->assertSound($m);
        // The +X cap (front) faces +X.
        $front = 0;
        for ($i = 0; $i < $m->vertexCount(); $i++) {
            if ($m->normals[$i * 3] > 0.99 && abs($m->vertices[$i * 3] - 1.0) < 1e-9) {
                $front++;
            }
        }
        self::assertGreaterThan(0, $front);
    }

    /** @return list<array{0: MeshData, 1: Mat4}> */
    private function parts(): array
    {
        $box = MetricMesh::box(0.2, 0.3, 0.4);
        $cyl = RoundMesh::cylinder(0.1, 0.5, 'x', 8);
        $parts = [];
        for ($i = 0; $i < 12; $i++) {
            $m = Mat4::translation($i * 0.5, 0.1 * $i, -$i * 0.2)
                ->multiply(Mat4::rotationY(0.3 * $i))
                ->multiply(Mat4::scaling(1.0 + 0.1 * $i, 1.0, 2.0 - 0.05 * $i));
            $parts[] = [$i % 2 === 0 ? $box : $cyl, $m];
        }
        // A mirrored part: the winding must flip.
        $parts[] = [$box, Mat4::scaling(-1.0, 1.0, 1.0)];
        return $parts;
    }

    public function testMergeTransformedMatchesTransformedPlusMerge(): void
    {
        $parts = $this->parts();
        $fast = MeshData::mergeTransformed($parts);
        $ref = MeshData::merge(...array_map(static fn(array $p): MeshData => $p[0]->transformed($p[1]), $parts));
        self::assertSame($ref->indices, $fast->indices);
        self::assertSame($ref->uvs, $fast->uvs);
        self::assertCount(count($ref->vertices), $fast->vertices);
        foreach ($ref->vertices as $k => $v) {
            self::assertEqualsWithDelta($v, $fast->vertices[$k], 1e-9);
            self::assertEqualsWithDelta($ref->normals[$k], $fast->normals[$k], 1e-9);
        }
        self::assertNotNull($fast->tangents);
        self::assertCount($fast->vertexCount() * 4, $fast->tangents);
        self::assertGreaterThan(0.0, $this->volume(MeshData::mergeTransformed([[MetricMesh::box(1, 1, 1), Mat4::scaling(-1.0, 1.0, 1.0)]])));
    }

    public function testMergeTransformedEmptyAndNoTangents(): void
    {
        self::assertSame(0, MeshData::mergeTransformed([])->vertexCount());
        $plain = new MeshData([0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 1.0, 0.0], [0.0, 0.0, 1.0, 0.0, 0.0, 1.0, 0.0, 0.0, 1.0], [0.0, 0.0, 1.0, 0.0, 0.0, 1.0], [0, 1, 2]);
        $m = MeshData::mergeTransformed([[$plain, Mat4::translation(0.0, 0.0, 1.0)]]);
        self::assertNull($m->tangents);
        self::assertEqualsWithDelta(1.0, $m->vertices[2], 1e-12);
    }
}
