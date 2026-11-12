<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\MeshCleanup;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\WallOpening;
use PHPolygon\Geometry\WallWithOpenings;
use PHPolygon\Math\Vec2;
use PHPUnit\Framework\TestCase;

/** Floor-level (notch) and polygon/round openings of WallWithOpenings. */
final class WallOpeningKindsTest extends TestCase
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

    private function assertClosed(MeshData $m): void
    {
        $topo = MeshCleanup::topology($m);
        self::assertSame(0, $topo['openEdges'], 'closed');
        self::assertSame(0, $topo['nonManifoldEdges']);
        self::assertSame(0, $topo['flippedEdges']);
        self::assertSame(0, $topo['degenerateTriangles']);
    }

    /** @param list<Vec2> $ring */
    private function area(array $ring): float
    {
        $a = 0.0;
        for ($i = 0, $n = count($ring); $i < $n; $i++) {
            $p = $ring[$i];
            $q = $ring[($i + 1) % $n];
            $a += $p->x * $q->y - $q->x * $p->y;
        }
        return abs($a) / 2.0;
    }

    public function testFloorLevelDoorIsANotch(): void
    {
        $wall = WallWithOpenings::parts(5.0, 3.0, 0.3, [new WallOpening(1.5, 0.0, 1.0, 2.2)])['wall'];
        $this->assertClosed($wall);
        self::assertEqualsWithDelta((5.0 * 3.0 - 1.0 * 2.2) * 0.3, $this->volume($wall), 1e-9);
        // No cap triangle may cover the doorway: nothing of the wall lies inside x 1.5..2.5, y < 2.2.
        for ($t = 0; $t < count($wall->indices); $t += 3) {
            $cx = $cy = 0.0;
            for ($k = 0; $k < 3; $k++) {
                $cx += $wall->vertices[$wall->indices[$t + $k] * 3] / 3.0;
                $cy += $wall->vertices[$wall->indices[$t + $k] * 3 + 1] / 3.0;
            }
            $inside = $cx > 1.5 + 1e-6 && $cx < 2.5 - 1e-6 && $cy < 2.2 - 1e-6;
            self::assertFalse($inside, 'triangle inside the doorway');
        }
    }

    public function testFloorLevelOpeningTouchingBothEdgesAndArch(): void
    {
        // Notch in the left corner, arched notch in the middle, notch in the right corner.
        $openings = [
            new WallOpening(0.0, 0.0, 1.0, 2.0),
            new WallOpening(2.0, 0.0, 1.2, 2.4, archRise: 0.5),
            new WallOpening(5.0, 0.0, 1.0, 2.0),
        ];
        $wall = WallWithOpenings::parts(6.0, 3.0, 0.25, $openings)['wall'];
        $this->assertClosed($wall);
        $arch = $this->archArea(1.2, 2.4, 0.5);
        self::assertEqualsWithDelta((6.0 * 3.0 - 2.0 - 2.0 - $arch) * 0.25, $this->volume($wall), 1e-3);
        $b = $wall->bounds();
        self::assertEqualsWithDelta(0.0, $b['min']->x, 1e-9);
        self::assertEqualsWithDelta(6.0, $b['max']->x, 1e-9);
    }

    private function archArea(float $w, float $h, float $rise): float
    {
        $ring = \PHPolygon\Geometry\Profile::archedRect($w, $h, $rise, 12);
        return $this->area($ring);
    }

    public function testFloorLevelOpeningNextToWindowHole(): void
    {
        $openings = [
            new WallOpening(1.0, 0.0, 1.0, 2.0),
            new WallOpening(3.0, 0.9, 1.0, 1.2, sillHeight: 0.05),
        ];
        $parts = WallWithOpenings::parts(6.0, 3.0, 0.3, $openings);
        $this->assertClosed($parts['wall']);
        self::assertEqualsWithDelta((18.0 - 2.0 - 1.2) * 0.3, $this->volume($parts['wall']), 1e-9);
        self::assertNotNull($parts['sills']);
    }

    public function testFloorLevelOpeningHasNoSillButKeepsLintel(): void
    {
        $parts = WallWithOpenings::parts(5.0, 3.0, 0.3, [new WallOpening(1.0, 0.0, 1.0, 2.0, sillHeight: 0.1, lintelHeight: 0.1)]);
        self::assertNull($parts['sills']);
        self::assertNotNull($parts['lintels']);
    }

    public function testCircularOpeningPorthole(): void
    {
        $o = WallOpening::circle(2.0, 1.5, 0.3, 24);
        self::assertEqualsWithDelta(1.7, $o->x, 1e-9);
        self::assertEqualsWithDelta(0.6, $o->width, 1e-9);
        $wall = WallWithOpenings::parts(4.0, 3.0, 0.2, [$o])['wall'];
        $this->assertClosed($wall);
        self::assertEqualsWithDelta((12.0 - $this->area($o->ring ?? [])) * 0.2, $this->volume($wall), 1e-9);
    }

    public function testPolygonOpeningAnyWindingAndMetricUvs(): void
    {
        $tri = [new Vec2(1.0, 1.0), new Vec2(2.0, 1.0), new Vec2(1.5, 2.0)];
        $o = WallOpening::ring(array_reverse($tri));
        $wall = WallWithOpenings::parts(4.0, 3.0, 0.2, [$o], 1.0)['wall'];
        $this->assertClosed($wall);
        self::assertEqualsWithDelta((12.0 - 0.5) * 0.2, $this->volume($wall), 1e-9);
        // Front-face UVs are world metres (x, y).
        for ($i = 0; $i < $wall->vertexCount(); $i++) {
            if ($wall->normals[$i * 3 + 2] > 0.99) {
                self::assertEqualsWithDelta($wall->vertices[$i * 3], $wall->uvs[$i * 2], 1e-9);
                self::assertEqualsWithDelta($wall->vertices[$i * 3 + 1], $wall->uvs[$i * 2 + 1], 1e-9);
            }
        }
    }

    public function testRingOpeningWithLintelUsesBoundingBox(): void
    {
        $parts = WallWithOpenings::parts(4.0, 3.0, 0.2, [WallOpening::circle(2.0, 1.5, 0.3, 16, lintelHeight: 0.05)]);
        self::assertNotNull($parts['lintels']);
        $b = $parts['lintels']->bounds();
        self::assertEqualsWithDelta(1.5 + 0.3, $b['min']->y, 1e-9);
    }

    public function testBadFloorLevelOpeningsStillThrow(): void
    {
        foreach ([
            [new WallOpening(1.0, 0.0, 1.0, 3.0)],                                  // touches the top
            [new WallOpening(0.0, 1.0, 1.0, 1.0)],                                  // side edge, not floor-level
            [WallOpening::circle(0.2, 1.5, 0.3)],                                   // ring crossing the edge
            [new WallOpening(1.0, 0.0, 1.0, 2.0), new WallOpening(2.0, 0.0, 1.0, 2.0)], // shared jamb
            [new WallOpening(1.0, 0.0, 2.0, 2.0), new WallOpening(2.0, 0.5, 1.0, 1.0)], // overlap
            [new WallOpening(4.5, 0.0, 1.0, 2.0)],                                  // sticks out
        ] as $case) {
            try {
                WallWithOpenings::generate(5.0, 3.0, 0.3, $case);
                self::fail('expected InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
