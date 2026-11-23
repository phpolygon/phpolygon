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
 * abut(..., minWidth): the thin strip a tangential fillet leaves OUTSIDE the
 * neighbour is removed (with its kerb and hem), wide surfaces and paths that
 * meet the edge head on stay, and the cut is flush.
 */
final class ConformingSurfaceSliverTest extends TestCase
{
    private const float LIFT = 0.025;
    private const float MIN = 1.2;

    private static function ground(float $x, float $z): float
    {
        return 0.03 * $x + 0.02 * $z;
    }

    /** The neighbour: x 6..14, its west edge at x = 6. */
    private static function neighbour(): RegionShape
    {
        return RegionShape::rect(10.0, 0.0, 8.0, 40.0);
    }

    /**
     * A path from the west meets the edge head on; a fillet strip hugs the edge to the
     * south of it and tapers: its outer outline lies $widthAtNorth beyond x = 6 at z = 12 and
     * 0.5 m beyond at z = 2.5 – thin at first, wide at the far end.
     */
    private static function set(float $minWidth, float $widthAtNorth = 3.4, ?callable $ground = null, float $lap = 0.4): ConformingSurfaceSet
    {
        $set = new ConformingSurfaceSet($ground ?? self::ground(...), cell: 0.5, lift: self::LIFT, hemWidth: 0.35, hemSink: 0.05);
        $set->add('main', RegionShape::polyline([[-8.0, 0.0], [8.0, 0.0]], 1.5))
            ->add('main', RegionShape::polygon([[6.5, 2.5], [6.0 - 0.5, 2.5], [6.0 - $widthAtNorth, 12.0], [7.0, 12.0]]))
            ->border('main', 0.3, 'kerb')
            ->abut(self::neighbour(), $lap, $minWidth);

        return $set;
    }

    /** @return list<array{float, float, float}> */
    private static function points(MeshData $m): array
    {
        $out = [];
        for ($i = 0, $n = count($m->vertices); $i < $n; $i += 3) {
            $out[] = [$m->vertices[$i], $m->vertices[$i + 1], $m->vertices[$i + 2]];
        }

        return $out;
    }

    /** Area in XZ of the triangles whose centroid satisfies $keep. */
    private static function area(MeshData $m, callable $keep): float
    {
        $p = self::points($m);
        $sum = 0.0;
        for ($t = 0, $n = count($m->indices); $t < $n; $t += 3) {
            [$a, $b, $c] = [$p[$m->indices[$t]], $p[$m->indices[$t + 1]], $p[$m->indices[$t + 2]]];
            $cx = ($a[0] + $b[0] + $c[0]) / 3.0;
            $cz = ($a[2] + $b[2] + $c[2]) / 3.0;
            if ($keep($cx, $cz)) {
                $sum += 0.5 * abs(($b[0] - $a[0]) * ($c[2] - $a[2]) - ($c[0] - $a[0]) * ($b[2] - $a[2]));
            }
        }

        return $sum;
    }

    /** Outside the neighbour, south of the path's side kerb: where the thin strip lies. */
    private static function inStrip(float $x, float $z): bool
    {
        return $z > 3.0 && $z < 4.0 && $x > 4.0 && $x < 6.0;
    }

    public function testMinWidthZeroIsTheOldBuildBitForBit(): void
    {
        $old = self::set(0.0)->build();
        $explicit = (new ConformingSurfaceSet(self::ground(...), cell: 0.5, lift: self::LIFT, hemWidth: 0.35, hemSink: 0.05))
            ->add('main', RegionShape::polyline([[-8.0, 0.0], [8.0, 0.0]], 1.5))
            ->add('main', RegionShape::polygon([[6.5, 2.5], [5.5, 2.5], [6.0 - 3.4, 12.0], [7.0, 12.0]]))
            ->border('main', 0.3, 'kerb')
            ->abut(self::neighbour(), 0.4)
            ->build();
        self::assertSame(array_keys($old), array_keys($explicit));
        foreach ($old as $name => $m) {
            self::assertSame($m->vertices, $explicit[$name]->vertices, $name);
            self::assertSame($m->indices, $explicit[$name]->indices, $name);
        }
        $this->expectException(\InvalidArgumentException::class);
        self::set(0.0)->abut(self::neighbour(), 0.4, -1.0);
    }

    public function testTheThinStripGoesWithItsKerbAndHem(): void
    {
        $kept = self::set(0.0)->build();
        $cut = self::set(self::MIN)->build();

        self::assertGreaterThan(0.05, self::area($kept['main'], self::inStrip(...)), 'without minWidth the strip is there');
        self::assertEqualsWithDelta(0.0, self::area($cut['main'], self::inStrip(...)), 1e-9, 'with minWidth it is gone');
        foreach (['kerb', 'main.hem'] as $name) {
            $before = 0;
            $after = 0;
            foreach (self::points($kept[$name]) as $p) {
                $before += self::inStrip($p[0], $p[2]) ? 1 : 0;
            }
            foreach (self::points($cut[$name]) as $p) {
                $after += self::inStrip($p[0], $p[2]) ? 1 : 0;
            }
            self::assertGreaterThan(0, $before, "{$name}: there was one to remove");
            self::assertSame(0, $after, "{$name}: no kerb or hem at the remnant");
        }
    }

    public function testThePathThatMeetsTheEdgeHeadOnKeepsItsCorners(): void
    {
        $kept = self::set(0.0)->build();
        $cut = self::set(self::MIN)->build();
        // The path at z -1.5..1.5, x up to the edge at 6 (and the lap beyond): same area, corners included.
        $path = static fn (float $x, float $z): bool => abs($z) < 1.6 && $x < 6.0;
        self::assertEqualsWithDelta(self::area($kept['main'], $path), self::area($cut['main'], $path), 1e-6);
        $corner = static fn (float $x, float $z): bool => abs($z) > 1.0 && abs($z) < 1.6 && $x > 5.0 && $x < 6.0;
        self::assertGreaterThan(0.4, self::area($cut['main'], $corner), 'the corners at the edge are not nibbled');
    }

    public function testTheWideEndOfTheFilletStaysAndTheCutIsFlush(): void
    {
        $kept = self::set(0.0)->build();
        $cut = self::set(self::MIN)->build();
        // Where the strip is wide (north of z = 10, 2.4 m or more beyond the edge) nothing changes in area.
        $wide = static fn (float $x, float $z): bool => $z > 10.0 && $x < 6.0;
        self::assertEqualsWithDelta(self::area($kept['main'], $wide), self::area($cut['main'], $wide), 1e-6);

        // Along the strip, outside the neighbour: lift is 0 on the cut line and never rises backwards.
        $row = [];
        foreach (self::points($cut['main']) as $p) {
            if (abs($p[0] - 6.0) < 1e-6 && $p[2] > 2.6) { // the vertex row on the neighbour's edge
                $row[] = $p;
            }
        }
        usort($row, static fn (array $a, array $b): int => $a[2] <=> $b[2]);
        self::assertGreaterThan(3, count($row));
        $first = $row[0];
        self::assertEqualsWithDelta(0.0, $first[1] - self::ground($first[0], $first[2]), 2e-3, 'the cut starts flush with the ground');
        $prev = -1.0;
        foreach ($row as $p) {
            $lift = $p[1] - self::ground($p[0], $p[2]);
            self::assertGreaterThanOrEqual($prev - 2e-3, $lift, sprintf('lift does not drop again at z = %.2f', $p[2]));
            $prev = $lift;
        }
        self::assertEqualsWithDelta(self::LIFT, end($row)[1] - self::ground(end($row)[0], end($row)[2]), 1e-3, 'full lift again on the wide part');
    }

    public function testAWideSurfaceBesideTheNeighbourIsNotRemoved(): void
    {
        $kept = self::set(0.0, widthAtNorth: 6.0)->build();
        $cut = self::set(self::MIN, widthAtNorth: 6.0)->build();
        // A fillet whose outer outline lies 2.5 m or more beyond the edge from z = 4 on: untouched in area.
        $region = static fn (float $x, float $z): bool => $z > 5.0 && $x < 6.0;
        self::assertEqualsWithDelta(self::area($kept['main'], $region), self::area($cut['main'], $region), 1e-6);
        $holes = static fn (float $x, float $z): bool => $z > 6.0 && $x < 5.5 && $x > 2.0;
        self::assertEqualsWithDelta(self::area($kept['main'], $holes), self::area($cut['main'], $holes), 1e-6, 'no hole in the wide surface');
    }

    public function testGroundMemoAndCacheKeys(): void
    {
        $memo = new GroundMemo(self::ground(...));
        $a = self::set(self::MIN, ground: $memo)->build();
        $b = self::set(self::MIN)->build();
        self::assertEqualsWithDelta($b['main']->vertices, $a['main']->vertices, 1e-9, 'a shared memo does not change the meshes');
        $other = self::set(2.0)->build();
        self::assertNotSame($b['main']->vertices, $other['main']->vertices, 'another minWidth, another mesh: minWidth belongs into the key');

        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpolygon-sliver-' . bin2hex(random_bytes(4));
        try {
            $first = (new MeshSetCache($dir))->remember('s', 'k', static fn (): array => self::set(self::MIN)->build());
            $second = (new MeshSetCache($dir))->remember('s', 'k', static function (): array {
                self::fail('warm start must not build');
            });
            self::assertSame($first['main']->vertices, $second['main']->vertices);
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }
}
