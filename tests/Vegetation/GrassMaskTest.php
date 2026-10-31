<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Vegetation;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Vec3;
use PHPolygon\Scene\Layout\Zone;
use PHPolygon\Vegetation\GrassMask;

final class GrassMaskTest extends TestCase
{
    public function testOpenMaskGrowsEverywhere(): void
    {
        $mask = new GrassMask();
        self::assertTrue($mask->isOpen());
        self::assertSame(1.0, $mask->factor(12.3, -40.0));
    }

    public function testPathHasNoGrassAndASoftEdge(): void
    {
        $mask = (new GrassMask())->excludePath([[0.0, 0.0], [10.0, 0.0], [10.0, 10.0]], 1.0, 0.5);
        self::assertSame(0.0, $mask->factor(5.0, 0.0), 'on the path');
        self::assertSame(0.0, $mask->factor(5.0, 0.99), 'inside the half width');
        self::assertSame(0.0, $mask->factor(10.5, 6.0), 'second segment');
        $edge = $mask->factor(5.0, 1.25);
        self::assertGreaterThan(0.0, $edge);
        self::assertLessThan(1.0, $edge, 'the feather thins the edge');
        self::assertSame(1.0, $mask->factor(5.0, 1.6), 'beyond the feather');
        self::assertSame(1.0, $mask->factor(-5.0, 5.0));
    }

    public function testRectCircleZoneAndPolygonExclude(): void
    {
        $mask = (new GrassMask())
            ->excludeRect(0.0, 0.0, 4.0, 2.0, 0.0)
            ->excludeCircle(20.0, 20.0, 3.0, 1.0)
            ->excludeZone(new Zone('house', new Vec3(-10.0, 0.0, -10.0), new Vec3(-6.0, 5.0, -6.0)), 0.0)
            ->excludePolygon([[30.0, 0.0], [40.0, 0.0], [35.0, 8.0]], 0.0);
        self::assertSame(0.0, $mask->factor(2.0, 1.0));
        self::assertSame(1.0, $mask->factor(4.5, 1.0));
        self::assertSame(0.0, $mask->factor(21.0, 21.0));
        self::assertGreaterThan(0.0, $mask->factor(23.5, 20.0));
        self::assertLessThan(1.0, $mask->factor(23.5, 20.0));
        self::assertSame(0.0, $mask->factor(-8.0, -8.0));
        self::assertSame(0.0, $mask->factor(35.0, 3.0));
        self::assertSame(1.0, $mask->factor(31.0, 6.0), 'outside the triangle');
    }

    public function testIncludeAreasLimitTheGrassAndFadeInward(): void
    {
        $mask = (new GrassMask())->includePolygon([[0.0, 0.0], [20.0, 0.0], [20.0, 20.0], [0.0, 20.0]], 2.0);
        self::assertSame(0.0, $mask->factor(-1.0, 5.0));
        self::assertSame(1.0, $mask->factor(10.0, 10.0));
        $edge = $mask->factor(1.0, 10.0);
        self::assertGreaterThan(0.0, $edge);
        self::assertLessThan(1.0, $edge);
        $mask->includeCircle(40.0, 40.0, 5.0);
        self::assertSame(1.0, $mask->factor(40.0, 41.0), 'any include area counts');
    }

    public function testCallablesMultiplyAndVersionBumps(): void
    {
        $mask = new GrassMask();
        $v = $mask->version;
        $mask->multiply(static fn(float $x, float $z): float => $x > 0.0 ? 0.25 : 3.0);
        self::assertGreaterThan($v, $mask->version);
        self::assertSame(0.25, $mask->factor(1.0, 0.0));
        self::assertSame(1.0, $mask->factor(-1.0, 0.0), 'clamped to 1');
    }

    public function testASmallMaskStaysWithoutTiles(): void
    {
        $mask = (new GrassMask())->excludePath([[0.0, 0.0], [10.0, 0.0]], 1.0)->excludeCircle(5.0, 5.0, 1.0);
        $mask->factor(1.0, 1.0);
        self::assertSame(0, $mask->tileCount());
    }

    public function testTilesGiveTheSameFactorAlsoInTheFeather(): void
    {
        foreach ([12.0, 7.3, 40.0] as $tileSize) {
            $mask = self::crowdedMask($tileSize);
            $feather = 0;
            for ($x = -70.0; $x <= 70.0; $x += 0.71) {
                for ($z = -45.0; $z <= 55.0; $z += 0.53) {
                    $plain = $mask->factorExhaustive($x, $z);
                    $feather += $plain > 0.0 && $plain < 1.0 ? 1 : 0;
                    self::assertEqualsWithDelta($plain, $mask->factor($x, $z), 1e-9, "({$x}, {$z}), tiles of {$tileSize} m");
                }
            }
            self::assertGreaterThan(1000, $feather, 'the comparison has to hit the feather');
            self::assertGreaterThan(4, $mask->tileCount(), 'the query has to run on tiles');
        }
    }

    public function testTileBordersAndPathKinksAreExact(): void
    {
        $mask = self::crowdedMask(12.0);
        // Points on and just beside the tile grid.
        foreach ([-36.0, -24.0, -12.0, 0.0, 12.0, 24.0] as $line) {
            for ($t = -60.0; $t <= 60.0; $t += 0.37) {
                foreach ([$line, $line - 1e-9, $line + 1e-9] as $c) {
                    self::assertEqualsWithDelta($mask->factorExhaustive($c, $t), $mask->factor($c, $t), 1e-9, "x = {$c}, z = {$t}");
                    self::assertEqualsWithDelta($mask->factorExhaustive($t, $c), $mask->factor($t, $c), 1e-9, "x = {$t}, z = {$c}");
                }
            }
        }
        // Inside a kink two segments are equally near: one feather, not two.
        $kink = (new GrassMask())->excludePath(self::zigzag(), 1.0, 2.0);
        for ($a = 0.0; $a < 6.3; $a += 0.13) {
            for ($r = 0.5; $r < 3.5; $r += 0.17) {
                $x = 5.0 + cos($a) * $r;
                $z = 3.0 + sin($a) * $r;
                self::assertEqualsWithDelta($kink->factorExhaustive($x, $z), $kink->factor($x, $z), 1e-9);
            }
        }
    }

    public function testATileHoldsOnlyWhatItTouches(): void
    {
        $ring = [];
        for ($i = 0; $i <= 80; $i++) {
            $t = $i / 80.0;
            $ring[] = [-60.0 + 120.0 * $t, 18.0 * sin(7.0 * $t) + 3.0];
        }
        for ($i = 80; $i >= 0; $i--) {
            $t = $i / 80.0;
            $ring[] = [-60.0 + 120.0 * $t, 18.0 * sin(7.0 * $t) + 12.0];
        }
        $mask = (new GrassMask())->excludePolygon($ring, 0.9)->excludeCircle(500.0, 500.0, 1.0);
        for ($x = -66.0; $x <= 66.0; $x += 6.0) {
            $mask->factor($x, 5.0);
        }
        $tiles = (new \ReflectionProperty($mask, 'tiles'))->getValue($mask);
        self::assertIsArray($tiles);
        self::assertNotSame([], $tiles);
        foreach ($tiles as $key => $tile) {
            self::assertLessThanOrEqual(1, count($tile[1]), "tile {$key}: the far circle stays out");
            foreach ($tile[1] as $area) {
                self::assertLessThan(60, count($area['data']), "tile {$key} still holds the whole ring");
            }
        }
    }

    public function testAnIncludeAreaOutsideTheTileMeansNoGrassThere(): void
    {
        $mask = new GrassMask();
        for ($i = 0; $i < 20; $i++) {
            $mask->includeCircle(100.0 + $i * 3.0, 100.0, 2.0);
        }
        self::assertSame(0.0, $mask->factor(0.0, 0.0));
        self::assertSame(1.0, $mask->factor(100.0, 100.0));
        self::assertSame(1, $mask->tileCount() - 1, 'both queries ran on tiles');
    }

    public function testAChangeDropsTheTiles(): void
    {
        $mask = self::crowdedMask(12.0);
        self::assertGreaterThan(0.0, $mask->factor(-30.0, 45.0));
        $mask->excludeCircle(-30.0, 45.0, 1.0, 0.0);
        self::assertSame(0.0, $mask->factor(-30.0, 45.0));
    }

    public function testSeveralFieldsShareTheTilesOfOneMask(): void
    {
        $shared = self::crowdedMask(12.0);
        $a = (new GrassMask())->multiplyMask($shared);
        $b = (new GrassMask())->multiply(static fn(float $x, float $z): float => 0.5)->multiplyMask($shared);
        self::assertSame($shared->factorExhaustive(3.0, 3.0), $a->factor(3.0, 3.0));
        $tiles = $shared->tileCount();
        self::assertSame(1, $tiles);
        $b->factor(3.5, 3.5);
        self::assertSame($tiles, $shared->tileCount(), 'the second field finds the tile built');
    }

    public function testAnEditOfAMultipliedMaskCountsAsAnEditOfEveryMaskOnIt(): void
    {
        $shared = self::crowdedMask(12.0);
        $middle = (new GrassMask())->multiplyMask($shared);
        $outer = (new GrassMask())->multiplyMask($middle);
        $versions = [$middle->version, $outer->version];
        self::assertGreaterThan(0.0, $outer->factor(-30.0, 45.0), 'tiles built');

        $shared->excludeCircle(-30.0, 45.0, 1.0, 0.0);

        self::assertGreaterThan($versions[0], $middle->version);
        self::assertGreaterThan($versions[1], $outer->version, 'through two levels');
        self::assertSame(0.0, $outer->factor(-30.0, 45.0), 'no tile keeps the old value');

        $version = $outer->version;
        $shared->multiply(static fn(float $x, float $z): float => 0.0);
        self::assertGreaterThan($version, $outer->version, 'a callable is an edit too');
        self::assertSame(0.0, $outer->factor(0.0, 50.0));
    }

    public function testAMultipliedMaskMultipliesAndKeepsTheReferenceFreeOfTiles(): void
    {
        $shared = self::crowdedMask(12.0);
        $outer = (new GrassMask())->multiply(static fn(float $x, float $z): float => 0.5)->multiplyMask($shared);
        self::assertEqualsWithDelta(0.5 * $shared->factorExhaustive(5.0, 45.0), $outer->factor(5.0, 45.0), 1e-12);

        $fresh = self::crowdedMask(12.0);
        (new GrassMask())->multiplyMask($fresh)->factorExhaustive(5.0, 45.0);
        self::assertSame(0, $fresh->tileCount());
    }

    public function testMasksCannotDependOnThemselves(): void
    {
        $a = new GrassMask();
        $b = (new GrassMask())->multiplyMask($a);
        $c = (new GrassMask())->multiplyMask($b);
        try {
            $a->multiplyMask($c);
            self::fail('a cycle has no value');
        } catch (\InvalidArgumentException) {
            self::assertSame(1.0, $c->factor(0.0, 0.0));
        }
        $this->expectException(\InvalidArgumentException::class);
        $a->multiplyMask($a);
    }

    public function testASharedMaskDoesNotKeepTheMasksOnItAlive(): void
    {
        $shared = new GrassMask();
        $outer = (new GrassMask())->multiplyMask($shared);
        $ref = \WeakReference::create($outer);
        unset($outer);
        self::assertNull($ref->get());
        $shared->excludeCircle(0.0, 0.0, 1.0);
        self::assertSame(1, $shared->version);
    }

    /** Paths with kinks, a concave and a self-crossing polygon, rects, circles, a soft include and a callable. */
    private static function crowdedMask(float $tileSize): GrassMask
    {
        $ring = [];
        for ($i = 0; $i <= 80; $i++) {
            $t = $i / 80.0;
            $ring[] = [-60.0 + 120.0 * $t, 18.0 * sin(7.0 * $t) + 3.0];
        }
        for ($i = 80; $i >= 0; $i--) {
            $t = $i / 80.0;
            $ring[] = [-60.0 + 120.0 * $t, 18.0 * sin(7.0 * $t) + 8.0 + 4.0 * cos(11.0 * $t)];
        }
        return (new GrassMask($tileSize))
            ->includePolygon([[-65.0, -40.0], [60.0, -44.0], [66.0, 10.0], [20.0, 12.0], [18.0, 52.0], [-66.0, 56.0]], 3.0)
            ->includeCircle(40.0, 40.0, 16.0, 2.0)
            ->excludePolygon($ring, 0.9)
            ->excludePolygon([[-20.0, -30.0], [10.0, -10.0], [10.0, -30.0], [-20.0, -10.0]], 0.7)
            ->excludePath(self::zigzag(), 1.2, 0.8)
            ->excludePath([[30.0, 30.0]], 2.0, 0.5)
            ->excludeRect(-50.0, 20.0, -35.0, 32.0, 0.5)
            ->excludeCircle(10.0, 30.0, 6.0, 0.8)
            ->multiply(static fn(float $x, float $z): float => 0.75 + 0.25 * sin($x * 0.2 + $z * 0.1));
    }

    /** @return list<array{0: float, 1: float}> */
    private static function zigzag(): array
    {
        $points = [];
        for ($i = 0; $i < 12; $i++) {
            $points[] = [-55.0 + $i * 10.0, 3.0 + ($i % 2) * 24.0];
        }
        return $points;
    }
}
