<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\RectCut;
use PHPUnit\Framework\TestCase;

final class RectCutTest extends TestCase
{
    public function testNoHolesReturnsTheRectangle(): void
    {
        self::assertSame([[0.0, 4.0, 0.0, 3.0]], RectCut::pieces([0.0, 4.0, 0.0, 3.0], []));
    }

    public function testOneWindowLeavesFourPieces(): void
    {
        $p = RectCut::pieces([0.0, 10.0, 0.0, 3.0], [[4.0, 6.0, 1.0, 2.0]]);
        self::assertSame([
            [0.0, 4.0, 0.0, 3.0],
            [4.0, 6.0, 0.0, 1.0],
            [4.0, 6.0, 2.0, 3.0],
            [6.0, 10.0, 0.0, 3.0],
        ], $p);
    }

    public function testADoorOnTheGroundLeavesNoPieceBelowIt(): void
    {
        $p = RectCut::pieces([0.0, 10.0, 0.0, 3.0], [[4.0, 6.0, 0.0, 2.2]]);
        self::assertCount(3, $p);
        self::assertEqualsWithDelta(30.0 - 2.0 * 2.2, RectCut::area($p), 1e-12);
    }

    public function testIdenticalNeighbouringSlabsAreMerged(): void
    {
        // Two holes that touch with the same heights form one gap: no piece boundary between them.
        $p = RectCut::pieces([0.0, 10.0, 0.0, 3.0], [[2.0, 4.0, 1.0, 2.0], [4.0, 6.0, 1.0, 2.0]]);
        self::assertCount(4, $p);
        self::assertSame([2.0, 6.0, 0.0, 1.0], $p[1]);
    }

    public function testHolesOutsideAreIgnoredAndOverhangIsClipped(): void
    {
        $p = RectCut::pieces([0.0, 10.0, 0.0, 3.0], [[20.0, 22.0, 0.0, 1.0], [-1.0, 2.0, 2.0, 5.0]]);
        self::assertEqualsWithDelta(30.0 - 2.0 * 1.0, RectCut::area($p), 1e-12);
    }

    public function testRandomHolesConserveAreaNeverOverlapAndLeaveHolesUncovered(): void
    {
        mt_srand(1234);
        $rand = static fn (float $a, float $b): float => $a + ($b - $a) * mt_rand() / mt_getrandmax();
        for ($round = 0; $round < 60; $round++) {
            $rect = [0.0, 20.0, 0.0, 6.0];
            $holes = [];
            for ($k = 0, $n = mt_rand(0, 7); $k < $n; $k++) {
                $u = $rand(-2.0, 21.0);
                $v = $rand(-1.0, 6.5);
                $holes[] = [$u, $u + $rand(0.2, 4.0), $v, $v + $rand(0.2, 3.0)];
            }
            $pieces = RectCut::pieces($rect, $holes, 0.0);
            // Same input, same output.
            self::assertSame($pieces, RectCut::pieces($rect, $holes, 0.0));

            // Reference: union area on the compressed coordinate grid.
            $us = [0.0, 20.0];
            $vs = [0.0, 6.0];
            foreach ($holes as [$a, $b, $c, $d]) {
                array_push($us, ...array_filter([$a, $b], static fn (float $x): bool => $x > 0.0 && $x < 20.0));
                array_push($vs, ...array_filter([$c, $d], static fn (float $x): bool => $x > 0.0 && $x < 6.0));
            }
            sort($us);
            sort($vs);
            $expected = 0.0;
            for ($i = 0; $i + 1 < count($us); $i++) {
                for ($j = 0; $j + 1 < count($vs); $j++) {
                    $cu = ($us[$i] + $us[$i + 1]) * 0.5;
                    $cv = ($vs[$j] + $vs[$j + 1]) * 0.5;
                    $in = false;
                    foreach ($holes as [$a, $b, $c, $d]) {
                        if ($cu > $a && $cu < $b && $cv > $c && $cv < $d) {
                            $in = true;
                            break;
                        }
                    }
                    $covered = false;
                    foreach ($pieces as [$a, $b, $c, $d]) {
                        if ($cu > $a && $cu < $b && $cv > $c && $cv < $d) {
                            $covered = true;
                            break;
                        }
                    }
                    self::assertSame(!$in, $covered, 'cell centre covered exactly when it is not in a hole');
                    if (!$in) {
                        $expected += ($us[$i + 1] - $us[$i]) * ($vs[$j + 1] - $vs[$j]);
                    }
                }
            }
            self::assertEqualsWithDelta($expected, RectCut::area($pieces), 1e-9);

            // No two pieces overlap (touching borders are fine).
            $count = count($pieces);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $overlapU = min($pieces[$i][1], $pieces[$j][1]) - max($pieces[$i][0], $pieces[$j][0]);
                    $overlapV = min($pieces[$i][3], $pieces[$j][3]) - max($pieces[$i][2], $pieces[$j][2]);
                    self::assertFalse($overlapU > 1e-12 && $overlapV > 1e-12, 'pieces overlap');
                }
            }
        }
    }
}
