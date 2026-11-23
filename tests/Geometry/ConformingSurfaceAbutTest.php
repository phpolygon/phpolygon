<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\ConformingSurfaceSet;
use PHPolygon\Geometry\GroundMemo;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshSetCache;
use PHPolygon\Geometry\RegionShape;
use PHPUnit\Framework\TestCase;

/**
 * ConformingSurfaceSet::abut(): a path that runs into a neighbour surface fades
 * its lift to 0 over the lap, ends flush, and leaves kerb and hem off the
 * abutting stretch.
 */
final class ConformingSurfaceAbutTest extends TestCase
{
    private const float LIFT = 0.025;
    private const float LAP = 0.4;

    private static function ground(float $x, float $z): float
    {
        return 0.04 * $x + 0.02 * $z;
    }

    private static function neighbour(): RegionShape
    {
        return RegionShape::rect(10.0, 0.0, 8.0, 20.0); // x 6..14
    }

    private static function west(): RegionShape
    {
        return RegionShape::rect(-18.0, 0.0, 4.0, 20.0); // x -20..-16
    }

    /** The path runs from the west neighbour into the east one. */
    private static function set(bool $abut, bool $west = false, float $lap = self::LAP, ?callable $ground = null): ConformingSurfaceSet
    {
        $set = new ConformingSurfaceSet($ground ?? self::ground(...), cell: 0.5, lift: self::LIFT, hemWidth: 0.35, hemSink: 0.05);
        $set->add('main', RegionShape::polyline([[$west ? -17.0 : -12.0, 0.0], [8.0, 0.0]], 1.5))
            ->border('main', 0.3, 'kerb');
        if ($abut) {
            $set->abut(self::neighbour(), $lap);
            if ($west) {
                $set->abut(self::west(), $lap);
            }
        } else {
            $set->cut(RegionShape::field(6.0, -10.0, 14.0, 10.0, static fn (float $x, float $z): float => self::neighbour()->distance($x, $z) + $lap));
        }

        return $set;
    }

    /** @return list<array{float, float, float}> x, y, z of every vertex */
    private static function points(MeshData $m): array
    {
        $out = [];
        for ($i = 0, $n = count($m->vertices); $i < $n; $i += 3) {
            $out[] = [$m->vertices[$i], $m->vertices[$i + 1], $m->vertices[$i + 2]];
        }

        return $out;
    }

    private static function lift(array $p): float
    {
        return $p[1] - self::ground($p[0], $p[2]);
    }

    public function testNothingIsLaidDeeperThanTheLapAndTheCutEdgeIsFlush(): void
    {
        $parts = self::set(true)->build();
        $near = 0;
        foreach (['main', 'kerb', 'main.hem'] as $name) {
            self::assertArrayHasKey($name, $parts);
            foreach (self::points($parts[$name]) as $p) {
                $dn = self::neighbour()->distance($p[0], $p[2]);
                self::assertGreaterThanOrEqual(-self::LAP - 1e-6, $dn, "{$name}: nothing deeper than the lap");
                if ($name !== 'main.hem' && abs($dn + self::LAP) < 1e-6) {
                    $near++;
                    self::assertEqualsWithDelta(0.0, self::lift($p), 1e-7, 'on the cut line the surface lies on the neighbour ground');
                }
            }
        }
        self::assertGreaterThan(4, $near, 'the cut line has vertices');
    }

    public function testTheLiftFadesSmoothlyAndNeverRises(): void
    {
        $parts = self::set(true)->build();
        $row = [];
        foreach (self::points($parts['main']) as $p) {
            if (abs($p[2]) < 0.26) {
                $row[] = $p;
            }
        }
        usort($row, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        self::assertGreaterThan(10, count($row));
        $prev = self::LIFT + 1e-9;
        $sawFull = false;
        $sawZero = false;
        foreach ($row as $p) {
            $lift = self::lift($p);
            self::assertLessThanOrEqual($prev + 1e-9, $lift, sprintf('lift rises at x = %.3f', $p[0]));
            $prev = $lift;
            $dn = self::neighbour()->distance($p[0], $p[2]);
            if ($dn >= 0.0) {
                self::assertEqualsWithDelta(self::LIFT, $lift, 1e-9, 'outside the neighbour: the full lift');
                $sawFull = true;
            } else {
                $u = min(1.0, -$dn / self::LAP);
                self::assertEqualsWithDelta(self::LIFT * (1.0 - $u * $u * (3.0 - 2.0 * $u)), $lift, 2e-4, 'inside: smoothstep of the depth');
            }
            $sawZero = $sawZero || $lift < 1e-6;
        }
        self::assertTrue($sawFull && $sawZero);
        // No step: the largest jump between neighbouring vertices of the row is a fraction of the lift per 0.5 m cell.
        $max = 0.0;
        for ($i = 1, $n = count($row); $i < $n; $i++) {
            $max = max($max, abs(self::lift($row[$i]) - self::lift($row[$i - 1])));
        }
        self::assertLessThanOrEqual(self::LIFT + 1e-9, $max);
    }

    public function testNoKerbAndNoHemInsideTheNeighbour(): void
    {
        $abut = self::set(true)->build();
        foreach (['kerb', 'main.hem'] as $name) {
            foreach (self::points($abut[$name]) as $p) {
                self::assertGreaterThanOrEqual(-1e-6, self::neighbour()->distance($p[0], $p[2]), "{$name} stays out of the neighbour");
            }
        }
        // The plain cut of the same shape leaves kerb and hem running into it: the test would pass for nothing otherwise.
        $plain = self::set(false)->build();
        $inside = 0;
        foreach (['kerb', 'main.hem'] as $name) {
            foreach (self::points($plain[$name]) as $p) {
                $inside += self::neighbour()->distance($p[0], $p[2]) < -0.05 ? 1 : 0;
            }
        }
        self::assertGreaterThan(0, $inside, 'a plain cut keeps the kerb running into the neighbour');
    }

    public function testTwoNeighboursEachGetTheirOwnRamp(): void
    {
        $parts = self::set(true, west: true)->build();
        foreach ($parts as $name => $mesh) {
            foreach (self::points($mesh) as $p) {
                self::assertGreaterThanOrEqual(-self::LAP - 1e-6, self::neighbour()->distance($p[0], $p[2]), $name);
                self::assertGreaterThanOrEqual(-self::LAP - 1e-6, self::west()->distance($p[0], $p[2]), $name);
            }
        }
        $east = 0;
        $westEnd = 0;
        foreach (self::points($parts['main']) as $p) {
            if (abs(self::west()->distance($p[0], $p[2]) + self::LAP) < 1e-6) {
                $westEnd++;
                self::assertEqualsWithDelta(0.0, self::lift($p), 1e-7);
            }
            if (abs(self::neighbour()->distance($p[0], $p[2]) + self::LAP) < 1e-6) {
                $east++;
                self::assertEqualsWithDelta(0.0, self::lift($p), 1e-7);
            }
        }
        self::assertGreaterThan(2, $east);
        self::assertGreaterThan(2, $westEnd);
    }

    public function testTheKerbOpensWhereAPathJoinsFromOutside(): void
    {
        // A driveway (x -1..1, z 1..6) mouths into the north side of a kerbed road.
        $driveway = RegionShape::rect(0.0, 3.5, 2.0, 5.0);
        $build = static function (bool $abut) use ($driveway): array {
            $set = new ConformingSurfaceSet(self::ground(...), cell: 0.25, lift: self::LIFT, hemWidth: 0.35, hemSink: 0.05);
            $set->add('road', RegionShape::polyline([[-10.0, 0.0], [10.0, 0.0]], 1.5))->border('road', 0.3, 'kerb');
            if ($abut) {
                $set->abut($driveway, 0.4);
            }

            return $set->build();
        };
        // Kerb vertices in the middle of the mouth (the band may wrap round the two corners by its width, 0.3 m).
        $kerbInDriveway = static function (array $parts): int {
            $n = 0;
            foreach (self::points($parts['kerb']) as $p) {
                $n += abs($p[0]) < 0.6 && $p[2] > 1.0 ? 1 : 0;
            }

            return $n;
        };
        self::assertGreaterThan(0, $kerbInDriveway($build(false)), 'without abut the kerb crosses the mouth');
        $opened = $build(true);
        self::assertSame(0, $kerbInDriveway($opened), 'with abut the kerb band is open at the mouth');
        // ... and runs on both sides of it.
        $left = 0;
        $right = 0;
        foreach (self::points($opened['kerb']) as $p) {
            if (abs($p[2] - 1.35) < 0.2) {
                $left += $p[0] < -1.2 ? 1 : 0;
                $right += $p[0] > 1.2 ? 1 : 0;
            }
        }
        self::assertGreaterThan(0, $left);
        self::assertGreaterThan(0, $right);
    }

    public function testAFarNeighbourChangesNothing(): void
    {
        $plain = self::set(false);
        $plainParts = (new ConformingSurfaceSet(self::ground(...), cell: 0.5, lift: self::LIFT, hemWidth: 0.35, hemSink: 0.05))
            ->add('main', RegionShape::polyline([[-12.0, 0.0], [8.0, 0.0]], 1.5))
            ->border('main', 0.3, 'kerb')
            ->build();
        $far = (new ConformingSurfaceSet(self::ground(...), cell: 0.5, lift: self::LIFT, hemWidth: 0.35, hemSink: 0.05))
            ->add('main', RegionShape::polyline([[-12.0, 0.0], [8.0, 0.0]], 1.5))
            ->border('main', 0.3, 'kerb')
            ->abut(RegionShape::circle(500.0, 500.0, 5.0), 0.4)
            ->build();
        unset($plain);
        self::assertSame(array_keys($plainParts), array_keys($far));
        foreach ($plainParts as $name => $m) {
            self::assertSame($m->vertices, $far[$name]->vertices, $name);
            self::assertSame($m->indices, $far[$name]->indices, $name);
        }
    }

    public function testALapMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::set(false)->abut(self::neighbour(), 0.0);
    }

    public function testASharedGroundMemoGivesTheSameMeshesAndTheKeyMustHoldTheLap(): void
    {
        $memo = new GroundMemo(self::ground(...));
        $a = self::set(true, lap: 0.4, ground: $memo)->build();
        $b = self::set(true, lap: 0.8, ground: $memo)->build();
        $alone = self::set(true, lap: 0.8)->build();
        self::assertEqualsWithDelta($alone['main']->vertices, $b['main']->vertices, 1e-9);
        self::assertNotSame($a['main']->vertices, $b['main']->vertices, 'another lap, another mesh: the lap belongs into a cache key');

        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpolygon-abut-' . bin2hex(random_bytes(4));
        try {
            $cache = new MeshSetCache($dir);
            $first = $cache->remember('abut', 'k', static fn (): array => self::set(true)->build());
            $second = (new MeshSetCache($dir))->remember('abut', 'k', static function (): array {
                self::fail('warm start must not build');
            });
            self::assertSame($first['main']->vertices, $second['main']->vertices);
            self::assertSame($first['kerb']->indices, $second['kerb']->indices);
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }
}
