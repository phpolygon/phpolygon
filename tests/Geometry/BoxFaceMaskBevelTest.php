<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\BevelBoxMesh;
use PHPolygon\Geometry\MeshCleanup;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MetricMesh;
use PHPolygon\Math\Vec3;
use PHPUnit\Framework\TestCase;

/** MetricMesh::boxBetween face mask and BevelBoxMesh top-only bevel. */
final class BoxFaceMaskBevelTest extends TestCase
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

    public function testSkippedFacesAreNotEmittedAndIndicesStayValid(): void
    {
        $a = new Vec3(0.0, 0.0, 0.0);
        $b = new Vec3(2.0, 0.5, 3.0);
        $full = MetricMesh::boxBetween($a, $b, 1.0, 0);
        self::assertSame(24, $full->vertexCount());
        $noBottom = MetricMesh::boxBetween($a, $b, 1.0, MetricMesh::FACE_NEG_Y);
        self::assertSame(20, $noBottom->vertexCount());
        self::assertSame(10, $noBottom->triangleCount());
        foreach ($noBottom->indices as $i) {
            self::assertLessThan(20, $i);
        }
        foreach ($noBottom->normals as $k => $n) {
            if ($k % 3 === 1) {
                self::assertGreaterThan(-0.5, $n, 'no -Y normal left');
            }
        }
        // Every face kept is identical to its counterpart in the full box.
        $none = MetricMesh::boxBetween($a, $b, 1.0, 63);
        self::assertSame(0, $none->vertexCount());
        $sides = MetricMesh::boxBetween($a, $b, 1.0, MetricMesh::FACE_POS_Y | MetricMesh::FACE_NEG_Y | MetricMesh::FACE_POS_Z | MetricMesh::FACE_NEG_Z);
        self::assertSame(8, $sides->vertexCount());
        self::assertNotNull($sides->tangents);
    }

    public function testTopOnlyBevelIsClosedOutwardAndFlatBottomed(): void
    {
        foreach ([[2.0, 0.5, 3.0, 0.1, 3], [1.0, 1.0, 1.0, 0.3, 1], [2.0, 0.2, 2.0, 0.4, 2]] as [$w, $h, $d, $bevel, $seg]) {
            $m = BevelBoxMesh::generate($w, $h, $d, $bevel, $seg, 1.0, true);
            $topo = MeshCleanup::topology($m);
            self::assertSame(0, $topo['openEdges']);
            self::assertSame(0, $topo['nonManifoldEdges']);
            self::assertSame(0, $topo['flippedEdges']);
            $vol = $this->volume($m);
            self::assertGreaterThan(0.0, $vol);
            self::assertLessThan($w * $h * $d, $vol);
            $r = min($bevel, $w / 2, $d / 2, $h);
            // Upper bound of the removed material: the rim cross-section times the perimeter.
            self::assertGreaterThan($w * $h * $d - (1.0 - M_PI / 4.0) * $r * $r * 2.0 * ($w + $d) - 4.0 * $r ** 3, $vol);
            $b = $m->bounds();
            self::assertEqualsWithDelta(-$w / 2.0, $b['min']->x, 1e-9);
            self::assertEqualsWithDelta($w / 2.0, $b['max']->x, 1e-9, 'vertical edges are not rounded');
            self::assertEqualsWithDelta($h / 2.0, $b['max']->y, 1e-9);
            // Bottom is one flat quad at the lowest y.
            $bottom = 0;
            foreach ($m->normals as $k => $n) {
                if ($k % 3 === 1 && $n < -0.99) {
                    $bottom++;
                }
            }
            self::assertSame(4, $bottom);
            foreach ($m->tangents ?? [] as $t) {
                self::assertTrue(is_finite($t));
            }
        }
    }

    public function testTopOnlyBevelZeroAndDefaultUnchanged(): void
    {
        $m = BevelBoxMesh::generate(1.0, 1.0, 1.0, 0.0, 3, 1.0, true);
        self::assertSame(24, $m->vertexCount());
        self::assertSame(6 * 64, BevelBoxMesh::generate(2.0, 1.0, 3.0, 0.1, 3)->vertexCount());
    }
}
