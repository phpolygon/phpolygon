<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\ReedBladeMesh;

/**
 * ReedBladeMesh: the foot sits at the origin, the tip at the height and bent forward,
 * the blade tapers, both faces face outward and wind counter-clockwise from their side.
 */
final class ReedBladeMeshTest extends TestCase
{
    public function testFootTipTaperAndBend(): void
    {
        $m = ReedBladeMesh::generate(1.2, 0.04, 4, 0.1, 0.2);
        $v = $m->vertices;
        $n = intdiv(count($v), 3);
        self::assertSame(2 * 2 * 5, $n, 'two faces, two vertices per row, five rows');
        $minY = INF;
        $maxY = -INF;
        for ($i = 0; $i < $n; $i++) {
            $minY = min($minY, $v[$i * 3 + 1]);
            $maxY = max($maxY, $v[$i * 3 + 1]);
        }
        self::assertSame(0.0, $minY, 'the foot is at the origin');
        self::assertEqualsWithDelta(1.2, $maxY, 1e-9);
        // Foot row: full width, straight; tip row: 20 % wide, 0.12 m forward.
        self::assertEqualsWithDelta(0.04, $v[3] - $v[0], 1e-9);
        self::assertSame(0.0, $v[2]);
        $tip = 8 * 3;
        self::assertEqualsWithDelta(0.008, $v[$tip + 3] - $v[$tip], 1e-9);
        self::assertEqualsWithDelta(0.12, $v[$tip + 2], 1e-9);
        self::assertSame(1.0, $m->uvs[$tip / 3 * 2 + 1], 'uv.y 1 at the tip');
    }

    public function testFacesPointOutwardAndWindTowardTheirNormal(): void
    {
        $m = ReedBladeMesh::generate(1.0, 0.05);
        $v = $m->vertices;
        $nrm = $m->normals;
        for ($t = 0; $t < count($m->indices); $t += 3) {
            [$a, $b, $c] = [$m->indices[$t], $m->indices[$t + 1], $m->indices[$t + 2]];
            $e1 = [$v[$b * 3] - $v[$a * 3], $v[$b * 3 + 1] - $v[$a * 3 + 1], $v[$b * 3 + 2] - $v[$a * 3 + 2]];
            $e2 = [$v[$c * 3] - $v[$a * 3], $v[$c * 3 + 1] - $v[$a * 3 + 1], $v[$c * 3 + 2] - $v[$a * 3 + 2]];
            $cross = [$e1[1] * $e2[2] - $e1[2] * $e2[1], $e1[2] * $e2[0] - $e1[0] * $e2[2], $e1[0] * $e2[1] - $e1[1] * $e2[0]];
            $dot = $cross[0] * $nrm[$a * 3] + $cross[1] * $nrm[$a * 3 + 1] + $cross[2] * $nrm[$a * 3 + 2];
            self::assertGreaterThan(0.0, $dot, "triangle {$t} winds toward its normal");
        }
        self::assertGreaterThan(0.9, $nrm[2], 'front face at the foot looks +Z');
        $back = intdiv(count($v), 6) * 3;
        self::assertLessThan(-0.9, $nrm[$back + 2], 'back face looks -Z');
    }

    public function testTheCrossStripFollowsTheCurveAndFacesOutward(): void
    {
        $flat = ReedBladeMesh::generate(1.0, 0.05, 4, 0.1);
        $m = ReedBladeMesh::generate(1.0, 0.05, 4, 0.1, 0.15, true);
        self::assertSame(2 * count($flat->vertices), count($m->vertices), 'two strips');
        $v = $m->vertices;
        $nrm = $m->normals;
        for ($t = 0; $t < count($m->indices); $t += 3) {
            [$a, $b, $c] = [$m->indices[$t], $m->indices[$t + 1], $m->indices[$t + 2]];
            $e1 = [$v[$b * 3] - $v[$a * 3], $v[$b * 3 + 1] - $v[$a * 3 + 1], $v[$b * 3 + 2] - $v[$a * 3 + 2]];
            $e2 = [$v[$c * 3] - $v[$a * 3], $v[$c * 3 + 1] - $v[$a * 3 + 1], $v[$c * 3 + 2] - $v[$a * 3 + 2]];
            $cross = [$e1[1] * $e2[2] - $e1[2] * $e2[1], $e1[2] * $e2[0] - $e1[0] * $e2[2], $e1[0] * $e2[1] - $e1[1] * $e2[0]];
            self::assertGreaterThan(0.0, $cross[0] * $nrm[$a * 3] + $cross[1] * $nrm[$a * 3 + 1] + $cross[2] * $nrm[$a * 3 + 2], "triangle {$t}");
        }
        // Last vertex: tip of the cross strip, on the bent centre line (z 0.1 +- half tip width).
        $last = count($v) - 3;
        self::assertSame(0.0, $v[$last]);
        self::assertEqualsWithDelta(1.0, $v[$last + 1], 1e-9);
        self::assertEqualsWithDelta(0.1, $v[$last + 2], 0.005);
    }

    public function testRejectsAZeroBlade(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReedBladeMesh::generate(0.0, 0.04);
    }
}
