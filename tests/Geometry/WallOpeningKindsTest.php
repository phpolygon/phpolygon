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

    /** Random layouts with notches (plain, arched, corner) and windows/portholes above them. */
    public function testRandomNotchedWallsStayClosedAndExactVolume(): void
    {
        $rng = new \PHPolygon\Math\Rng(77);
        $notches = $holes = $corners = 0;
        for ($round = 0; $round < 300; $round++) {
            $width = $rng->floatRange(4.0, 12.0);
            $height = $rng->floatRange(3.0, 6.0);
            $thickness = $rng->floatRange(0.1, 0.5);
            $openings = [];
            $area = 0.0;
            // Notches: slots of the bottom edge, left to right, separated by wall.
            $x = $rng->bool(0.3) ? 0.0 : $rng->floatRange(0.3, 1.0);
            $notchTop = 0.0;
            while ($x < $width - 0.8) {
                $w = $rng->floatRange(0.6, 1.6);
                $last = $x + $w >= $width - 0.4;
                $touchRight = $last && $rng->bool(0.4);
                if ($touchRight) {
                    $w = $width - $x;
                } elseif ($last) {
                    break;
                }
                $h = $rng->floatRange(1.8, min(2.6, $height - 0.6));
                $rise = $w > 0.8 && $rng->bool(0.4) ? $rng->floatRange(0.1, min($w / 2.0, 0.5)) : 0.0;
                $openings[] = new WallOpening($x, 0.0, $w, $h, $rise, $rng->int(3, 10));
                $area += $this->area($this->archRing($w, $h, $rise, $openings[count($openings) - 1]->archSegments));
                $notchTop = max($notchTop, $h);
                $x += $w + $rng->floatRange(0.3, 1.2);
            }
            // Windows / portholes above the notch band, inside the wall.
            $wx = 0.4;
            while ($wx < $width - 1.2 && $notchTop + 0.9 < $height - 0.4) {
                $y = $notchTop + 0.3;
                if ($rng->bool(0.5)) {
                    $ww = $rng->floatRange(0.4, 0.8);
                    $hh = min($rng->floatRange(0.4, 0.8), $height - 0.3 - $y);
                    if ($hh > 0.2) {
                        $openings[] = new WallOpening($wx, $y, $ww, $hh);
                        $area += $ww * $hh;
                    }
                } else {
                    $r = $rng->floatRange(0.15, 0.3);
                    if ($y + 2 * $r < $height - 0.2) {
                        $o = WallOpening::circle($wx + $r, $y + $r, $r, $rng->int(6, 16));
                        $openings[] = $o;
                        $area += $this->area($o->ring ?? []);
                    }
                }
                $wx += $rng->floatRange(0.9, 1.6);
            }
            foreach ($openings as $o) {
                $notches += $o->isFloorLevel() ? 1 : 0;
                $holes += $o->isFloorLevel() ? 0 : 1;
                $corners += $o->isFloorLevel() && ($o->x <= 1e-9 || $o->x + $o->width >= $width - 1e-9) ? 1 : 0;
            }
            $wall = WallWithOpenings::parts($width, $height, $thickness, $openings)['wall'];
            $this->assertClosed($wall);
            self::assertEqualsWithDelta(($width * $height - $area) * $thickness, $this->volume($wall), 1e-6, "round $round");
        }
        // The loop must really exercise notches, corner notches and holes above them.
        self::assertGreaterThan(300, $notches);
        self::assertGreaterThan(50, $corners);
        self::assertGreaterThan(100, $holes);
    }

    /** @return list<Vec2> */
    private function archRing(float $w, float $h, float $rise, int $segments): array
    {
        return \PHPolygon\Geometry\Profile::archedRect($w, $h, $rise, $segments);
    }
}
