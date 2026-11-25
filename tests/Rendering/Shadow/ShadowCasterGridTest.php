<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Shadow;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Shadow\ShadowCasterGrid;

final class ShadowCasterGridTest extends TestCase
{
    public function testQueryReturnsOnlyTouchingCastersAscending(): void
    {
        $g = new ShadowCasterGrid(8.0);
        $g->add(0, 100.0, 0.0, 0.0, 1.0);
        $g->add(1, 2.0, 0.0, 0.0, 1.0);
        $g->add(2, 9.0, 0.0, 0.0, 1.0);
        $g->add(3, 0.0, 0.0, 0.0, 0.5);
        self::assertSame([1, 3], $g->query(0.0, 0.0, 0.0, 2.0));
        self::assertSame([1, 2, 3], $g->query(5.0, 0.0, 0.0, 5.0));
        self::assertSame([], $g->query(-50.0, 0.0, 0.0, 1.0));
    }

    public function testASphereReachingIntoTheQueryCounts(): void
    {
        $g = new ShadowCasterGrid(8.0);
        $g->add(0, 12.0, 0.0, 0.0, 3.0); // spans x 9..15
        self::assertSame([0], $g->query(7.0, 0.0, 0.0, 2.5)); // touches at 9.5 > 9
        self::assertSame([], $g->query(5.0, 0.0, 0.0, 2.0));
    }

    public function testLargeAndUnboundedCastersAreAlwaysCandidates(): void
    {
        $g = new ShadowCasterGrid(8.0);
        $g->add(0, 0.0, 0.0, 0.0, 500.0);          // ground
        $g->add(1, INF, 0.0, 0.0, INF);             // unknown bounds
        $g->add(2, 300.0, 0.0, 0.0, 1.0);
        self::assertSame([0, 1], $g->query(0.0, 0.0, 0.0, 1.0));
        self::assertSame([0, 1, 2], $g->query(300.0, 0.0, 0.0, 1.0));
        self::assertSame(3, $g->count());
    }

    public function testMatchesABruteForceScan(): void
    {
        mt_srand(7);
        $g = new ShadowCasterGrid(8.0);
        $all = [];
        for ($i = 0; $i < 300; $i++) {
            $s = [mt_rand(-400, 400) / 10.0, mt_rand(0, 100) / 10.0, mt_rand(-400, 400) / 10.0, mt_rand(1, 60) / 10.0];
            $all[$i] = $s;
            $g->add($i, ...$s);
        }
        for ($q = 0; $q < 30; $q++) {
            $c = [mt_rand(-400, 400) / 10.0, mt_rand(0, 100) / 10.0, mt_rand(-400, 400) / 10.0, mt_rand(20, 150) / 10.0];
            $expected = [];
            foreach ($all as $i => $s) {
                $d = ($c[0] - $s[0]) ** 2 + ($c[1] - $s[1]) ** 2 + ($c[2] - $s[2]) ** 2;
                if (!($d > ($c[3] + $s[3]) ** 2)) {
                    $expected[] = $i;
                }
            }
            self::assertSame($expected, $g->query(...$c));
        }
    }
}
