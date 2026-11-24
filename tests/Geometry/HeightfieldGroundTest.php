<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\HeightfieldGrid;
use PHPolygon\Geometry\HeightfieldGround;
use PHPolygon\Geometry\HeightfieldHole;
use PHPolygon\Geometry\MeshData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HeightfieldGroundTest extends TestCase
{
    private static function terrain(float $x, float $z): float
    {
        return 3.0 * sin($x * 0.07) + 2.0 * cos($z * 0.05) + 0.01 * $x + 0.4 * sin($x * 0.9 + $z * 0.7);
    }

    private static function grid(float $x0 = 0.0, float $z0 = 0.0, float $x1 = 40.0, float $z1 = 40.0, float $cell = 2.0): HeightfieldGrid
    {
        return HeightfieldGrid::sample($x0, $z0, $x1, $z1, $cell, self::terrain(...));
    }

    /**
     * @param array<string, MeshData> $meshes
     * @return list<array{0: array{0: float, 1: float, 2: float}, 1: array{0: float, 1: float, 2: float}, 2: array{0: float, 1: float, 2: float}}>
     */
    private static function triangles(array $meshes): array
    {
        $out = [];
        foreach ($meshes as $m) {
            for ($t = 0; $t + 2 < count($m->indices); $t += 3) {
                $tri = [];
                for ($k = 0; $k < 3; $k++) {
                    $i = $m->indices[$t + $k] * 3;
                    $tri[] = [$m->vertices[$i], $m->vertices[$i + 1], $m->vertices[$i + 2]];
                }
                $out[] = $tri;
            }
        }

        return $out;
    }

    /** Signed XZ area, positive when the triangle is counter-clockwise seen from above (the mesh convention). */
    private static function area(array $tri): float
    {
        [$a, $b, $c] = $tri;

        return 0.5 * (($b[2] - $a[2]) * ($c[0] - $a[0]) - ($b[0] - $a[0]) * ($c[2] - $a[2]));
    }

    private static function key(float $x, float $z): string
    {
        return round($x * 1e6) . ':' . round($z * 1e6);
    }

    /**
     * Edges of the welded union of the meshes: key => [use count, midpoint x, midpoint z].
     *
     * @param array<string, MeshData> $meshes
     * @return array<string, array{0: int, 1: float, 2: float}>
     */
    private static function edges(array $meshes): array
    {
        $edges = [];
        foreach (self::triangles($meshes) as $tri) {
            for ($k = 0; $k < 3; $k++) {
                $a = $tri[$k];
                $b = $tri[($k + 1) % 3];
                $ka = self::key($a[0], $a[2]);
                $kb = self::key($b[0], $b[2]);
                $key = $ka < $kb ? $ka . '|' . $kb : $kb . '|' . $ka;
                $edges[$key] = [($edges[$key][0] ?? 0) + 1, ($a[0] + $b[0]) / 2, ($a[2] + $b[2]) / 2];
            }
        }

        return $edges;
    }

    /**
     * Every open edge must lie on the outer border or on a hole outline; nothing is used more than twice.
     *
     * @param array<string, MeshData> $meshes
     * @param list<HeightfieldHole> $holes
     */
    private function assertWatertightExceptBorderAndHoles(array $meshes, HeightfieldGrid $grid, array $holes): void
    {
        foreach (self::edges($meshes) as $key => [$uses, $mx, $mz]) {
            self::assertLessThanOrEqual(2, $uses, "edge {$key} used {$uses} times");
            if ($uses === 1) {
                $onBorder = abs($mx - $grid->minX()) < 1e-6 || abs($mx - $grid->maxX()) < 1e-6
                    || abs($mz - $grid->minZ()) < 1e-6 || abs($mz - $grid->maxZ()) < 1e-6;
                $onHole = false;
                foreach ($holes as $h) {
                    if (abs($h->signedDistance($mx, $mz)) < 0.1 + 1e-6) {
                        $onHole = true;
                    }
                }
                self::assertTrue($onBorder || $onHole, "open edge {$key} at ({$mx}, {$mz}) is neither on the border nor on a hole");
            }
        }
    }

    // --- grid ---------------------------------------------------------------------------------

    public function testGridSnapsToTheLatticeAndSamplesTheFunction(): void
    {
        $grid = HeightfieldGrid::sample(-3.1, 1.2, 9.9, 8.0, 2.0, self::terrain(...));
        self::assertSame(-4.0, $grid->minX());
        self::assertSame(0.0, $grid->minZ());
        self::assertSame(10.0, $grid->maxX());
        self::assertSame(8.0, $grid->maxZ());
        self::assertSame(self::terrain(-4.0, 0.0), $grid->cornerHeight(-2, 0));
        self::assertSame(self::terrain(-6.0, -2.0), $grid->cornerHeight(-3, -1), 'one corner of halo');
        self::assertEqualsWithDelta(self::terrain(4.0, 6.0), $grid->heightAt(4.0, 6.0), 1e-12);
    }

    public function testGridHashIsPinnedAndSensitive(): void
    {
        $flat = new HeightfieldGrid(0, 0, 1, 1, 2.0, array_fill(0, 16, 1.5));
        self::assertSame('7b8639276330357aa1f3d3eae6cf50bb', $flat->contentHash());
        $bumped = new HeightfieldGrid(0, 0, 1, 1, 2.0, [...array_fill(0, 15, 1.5), 1.5000001]);
        self::assertNotSame($flat->contentHash(), $bumped->contentHash());
        self::assertSame($flat->contentHash(), (new HeightfieldGrid(0, 0, 1, 1, 2.0, array_fill(0, 16, 1.5)))->contentHash());
    }

    // --- plain surface ------------------------------------------------------------------------

    public function testPlainGridIsTwoUpFacingTrianglesPerCellOnTheTerrain(): void
    {
        $grid = self::grid();
        $meshes = HeightfieldGround::build($grid);
        self::assertSame(['ground'], array_keys($meshes));
        $mesh = $meshes['ground'];
        self::assertSame(2 * 20 * 20, $mesh->triangleCount());
        self::assertSame(21 * 21, $mesh->vertexCount(), 'every lattice corner once');
        foreach (self::triangles($meshes) as $tri) {
            self::assertGreaterThan(0.0, self::area($tri), 'counter-clockwise seen from above');
        }
        for ($i = 0; $i < $mesh->vertexCount(); $i++) {
            self::assertGreaterThan(0.0, $mesh->normals[$i * 3 + 1]);
            self::assertEqualsWithDelta(1.0, sqrt($mesh->normals[$i * 3] ** 2 + $mesh->normals[$i * 3 + 1] ** 2 + $mesh->normals[$i * 3 + 2] ** 2), 1e-12);
            self::assertSame(self::terrain($mesh->vertices[$i * 3], $mesh->vertices[$i * 3 + 2]), $mesh->vertices[$i * 3 + 1], 'height = the ground at the corner, exactly');
        }
        $this->assertWatertightExceptBorderAndHoles($meshes, $grid, []);
    }

    public function testUvIsWorldXzTimesScale(): void
    {
        $mesh = HeightfieldGround::build(self::grid(), uvScale: 0.25)['ground'];
        for ($i = 0; $i < $mesh->vertexCount(); $i++) {
            self::assertEqualsWithDelta($mesh->vertices[$i * 3] * 0.25, $mesh->uvs[$i * 2], 1e-12);
            self::assertEqualsWithDelta($mesh->vertices[$i * 3 + 2] * 0.25, $mesh->uvs[$i * 2 + 1], 1e-12);
        }
    }

    // --- holes --------------------------------------------------------------------------------

    /** @return array<string, array{0: list<HeightfieldHole>}> */
    public static function holeCases(): array
    {
        return [
            'lift shaft 5 x 5' => [[HeightfieldHole::rect(21.3, 17.9, 5.0, 5.0)]],
            'smaller than a cell' => [[HeightfieldHole::rect(21.3, 17.9, 0.6, 0.9)]],
            'inside one triangle' => [[HeightfieldHole::rect(20.7, 20.4, 0.5, 0.5)]],
            'edges on lattice lines' => [[HeightfieldHole::rect(21.0, 19.0, 4.0, 2.0)]],
            'corner on a lattice corner' => [[HeightfieldHole::rect(22.0, 22.0, 4.0, 4.0)]],
            'yawed' => [[HeightfieldHole::rect(21.0, 18.0, 6.0, 3.0, 0.6)]],
            'circle' => [[HeightfieldHole::circle(20.4, 19.1, 3.3, 24)]],
            'two holes' => [[HeightfieldHole::rect(10.2, 10.2, 3.0, 3.0), HeightfieldHole::circle(28.7, 26.1, 2.5, 16)]],
            'hole larger than several cells' => [[HeightfieldHole::rect(20.0, 20.0, 17.3, 9.1, 0.3)]],
        ];
    }

    /** @param list<HeightfieldHole> $holes */
    #[DataProvider('holeCases')]
    public function testHoleRemovesExactlyItsAreaAndStaysWatertight(array $holes): void
    {
        $grid = self::grid();
        $meshes = HeightfieldGround::build($grid, $holes);
        $area = 0.0;
        foreach (self::triangles($meshes) as $tri) {
            self::assertGreaterThan(0.0, self::area($tri), 'every triangle faces up');
            $area += self::area($tri);
            $cx = ($tri[0][0] + $tri[1][0] + $tri[2][0]) / 3;
            $cz = ($tri[0][2] + $tri[1][2] + $tri[2][2]) / 3;
            foreach ($holes as $h) {
                self::assertGreaterThan(-1e-9, $h->signedDistance($cx, $cz), 'no triangle inside a hole');
            }
        }
        $expected = 1600.0;
        foreach ($holes as $h) {
            $expected -= $h->area();
        }
        // The hole outline may move by the snap length (5 % of a cell) at most.
        self::assertEqualsWithDelta($expected, $area, 0.2 * count($holes));
        $this->assertWatertightExceptBorderAndHoles($meshes, $grid, $holes);
    }

    /** @param list<HeightfieldHole> $holes */
    #[DataProvider('holeCases')]
    public function testHoledSurfaceIsTheSameSurfaceOutsideTheHole(array $holes): void
    {
        $grid = self::grid();
        $tris = self::triangles(HeightfieldGround::build($grid, $holes));
        foreach ($tris as $tri) {
            foreach ($tri as [$x, $y, $z]) {
                self::assertEqualsWithDelta($grid->heightAt($x, $z), $y, 1e-9, 'every vertex lies on the un-holed surface');
            }
        }
        mt_srand(7);
        $checked = 0;
        for ($n = 0; $n < 300; $n++) {
            $x = 0.2 + mt_rand() / mt_getrandmax() * 39.6;
            $z = 0.2 + mt_rand() / mt_getrandmax() * 39.6;
            $inside = false;
            foreach ($holes as $h) {
                $inside = $inside || $h->signedDistance($x, $z) < 0.3;
            }
            if ($inside) {
                continue;
            }
            $checked++;
            $found = false;
            foreach ($tris as [$a, $b, $c]) {
                $d = ($b[2] - $c[2]) * ($a[0] - $c[0]) + ($c[0] - $b[0]) * ($a[2] - $c[2]);
                $l1 = (($b[2] - $c[2]) * ($x - $c[0]) + ($c[0] - $b[0]) * ($z - $c[2])) / $d;
                $l2 = (($c[2] - $a[2]) * ($x - $c[0]) + ($a[0] - $c[0]) * ($z - $c[2])) / $d;
                if ($l1 >= -1e-9 && $l2 >= -1e-9 && 1 - $l1 - $l2 >= -1e-9) {
                    $found = true;
                    self::assertEqualsWithDelta($grid->heightAt($x, $z), $l1 * $a[1] + $l2 * $b[1] + (1 - $l1 - $l2) * $c[1], 1e-9);
                    break;
                }
            }
            self::assertTrue($found, "no triangle covers ({$x}, {$z})");
        }
        self::assertGreaterThan(100, $checked);
    }

    public function testHoleTrianglesAreNotSlivers(): void
    {
        foreach (self::holeCases() as $name => [$holes]) {
            $min = 180.0;
            foreach (self::triangles(HeightfieldGround::build(self::grid(), $holes)) as $tri) {
                for ($k = 0; $k < 3; $k++) {
                    $a = $tri[$k];
                    $b = $tri[($k + 1) % 3];
                    $c = $tri[($k + 2) % 3];
                    $ux = $b[0] - $a[0];
                    $uz = $b[2] - $a[2];
                    $vx = $c[0] - $a[0];
                    $vz = $c[2] - $a[2];
                    $min = min($min, rad2deg(abs(atan2($ux * $vz - $uz * $vx, $ux * $vx + $uz * $vz))));
                }
            }
            self::assertGreaterThan(1.0, $min, "{$name}: smallest angle {$min} degrees");
        }
    }

    public function testALargerSnapTradesOutlineForTriangleShape(): void
    {
        // Measured: default snap 0.05 -> smallest angle 1.7 degrees on the circle, snap 0.2 -> 7 degrees.
        foreach (self::holeCases() as $name => [$holes]) {
            $min = 180.0;
            foreach (self::triangles(HeightfieldGround::build(self::grid(), $holes, null, 1.0, 0.2)) as $tri) {
                for ($k = 0; $k < 3; $k++) {
                    [$a, $b, $c] = [$tri[$k], $tri[($k + 1) % 3], $tri[($k + 2) % 3]];
                    $ux = $b[0] - $a[0];
                    $uz = $b[2] - $a[2];
                    $vx = $c[0] - $a[0];
                    $vz = $c[2] - $a[2];
                    $min = min($min, rad2deg(abs(atan2($ux * $vz - $uz * $vx, $ux * $vx + $uz * $vz))));
                }
            }
            self::assertGreaterThan(6.0, $min, "{$name}: smallest angle {$min} degrees at snap 0.2");
        }
    }

    // --- zones --------------------------------------------------------------------------------

    public function testZonesSplitTheSurfaceIntoMeshesWithoutGapOrOverlap(): void
    {
        $grid = self::grid();
        $holes = [HeightfieldHole::rect(21.3, 17.9, 5.0, 5.0), HeightfieldHole::circle(8.4, 30.2, 3.0, 20)];
        $zone = static fn (float $x, float $z): string => $x + 0.4 * $z < 30.0 ? 'road' : 'grass';
        $whole = HeightfieldGround::build($grid, $holes);
        $zoned = HeightfieldGround::build($grid, $holes, $zone);
        self::assertSame(['grass', 'road'], array_keys($zoned), 'sorted by zone name');
        self::assertSame(count(self::triangles($whole)), count(self::triangles($zoned)), 'each triangle lands in exactly one zone');
        foreach ($zoned as $name => $mesh) {
            foreach (self::triangles([$name => $mesh]) as $tri) {
                $cx = ($tri[0][0] + $tri[1][0] + $tri[2][0]) / 3;
                $cz = ($tri[0][2] + $tri[1][2] + $tri[2][2]) / 3;
                self::assertSame($name, $zone($cx, $cz));
            }
        }
        $this->assertWatertightExceptBorderAndHoles($zoned, $grid, $holes);
        // Seam vertices of two zones carry the same normal.
        $normals = [];
        foreach ($zoned as $mesh) {
            for ($i = 0; $i < $mesh->vertexCount(); $i++) {
                $k = self::key($mesh->vertices[$i * 3], $mesh->vertices[$i * 3 + 2]);
                $n = [$mesh->normals[$i * 3], $mesh->normals[$i * 3 + 1], $mesh->normals[$i * 3 + 2]];
                if (isset($normals[$k])) {
                    self::assertSame($normals[$k], $n, "seam normal at {$k}");
                }
                $normals[$k] = $n;
            }
        }
    }

    public function testIntegerZonesBecomeStringKeys(): void
    {
        $zoned = HeightfieldGround::build(self::grid(), [], static fn (float $x, float $z): int => $x < 20.0 ? 1 : 2);
        self::assertSame(['1', '2'], array_map('strval', array_keys($zoned)));
    }

    // --- tiles --------------------------------------------------------------------------------

    public function testNeighbouringTilesShareTheirBorderBitForBit(): void
    {
        $a = self::grid(0.0, 0.0, 40.0, 40.0);
        $b = self::grid(40.0, 0.0, 80.0, 40.0);
        $hole = [HeightfieldHole::rect(40.0, 17.3, 6.0, 5.0, 0.4), HeightfieldHole::circle(40.3, 31.0, 2.2, 16)];
        $zone = static fn (float $x, float $z): string => $z < 20.0 ? 'p' : 'q';
        $ma = HeightfieldGround::build($a, $hole, $zone);
        $mb = HeightfieldGround::build($b, $hole, $zone);

        $border = static function (array $meshes): array {
            $out = [];
            foreach ($meshes as $m) {
                for ($i = 0; $i < $m->vertexCount(); $i++) {
                    if ($m->vertices[$i * 3] === 40.0) {
                        $out[sprintf('%.17g', $m->vertices[$i * 3 + 2])] = [$m->vertices[$i * 3 + 1], $m->normals[$i * 3], $m->normals[$i * 3 + 1], $m->normals[$i * 3 + 2]];
                    }
                }
            }
            ksort($out);

            return $out;
        };
        $ba = $border($ma);
        $bb = $border($mb);
        self::assertGreaterThan(20, count($ba));
        self::assertSame(array_keys($ba), array_keys($bb), 'the same border vertices, at the same bits');
        foreach ($ba as $k => $va) {
            self::assertEqualsWithDelta($va[0], $bb[$k][0], 0.0, 'height');
            foreach ([1, 2, 3] as $c) {
                self::assertEqualsWithDelta($va[$c], $bb[$k][$c], 1e-12, 'normal');
            }
        }

        // Welded union: open edges only on the outer border and the hole outlines.
        $both = [...array_map(static fn (MeshData $m): MeshData => $m, array_values($ma)), ...array_values($mb)];
        $union = new HeightfieldGrid(0, 0, 40, 20, 2.0, array_fill(0, 43 * 23, 0.0));
        $this->assertWatertightExceptBorderAndHoles($both, $union, $hole);
    }

    public function testASeamTileEqualsTheCornersOfOneBigTile(): void
    {
        $big = HeightfieldGround::build(self::grid(0.0, 0.0, 80.0, 40.0))['ground'];
        $a = HeightfieldGround::build(self::grid(0.0, 0.0, 40.0, 40.0))['ground'];
        $b = HeightfieldGround::build(self::grid(40.0, 0.0, 80.0, 40.0))['ground'];
        self::assertSame($big->triangleCount(), $a->triangleCount() + $b->triangleCount());
        $pos = static function (MeshData $m): array {
            $out = [];
            for ($i = 0; $i < $m->vertexCount(); $i++) {
                $out[sprintf('%.17g:%.17g', $m->vertices[$i * 3], $m->vertices[$i * 3 + 2])] = [$m->vertices[$i * 3 + 1], $m->normals[$i * 3], $m->normals[$i * 3 + 1], $m->normals[$i * 3 + 2]];
            }

            return $out;
        };
        $merged = $pos($a) + $pos($b);
        $bigPos = $pos($big);
        ksort($merged);
        ksort($bigPos);
        self::assertSame($bigPos, $merged, 'heights and normals of the tiles = the big tile');
    }

    // --- determinism and keys -----------------------------------------------------------------

    public function testBuildIsDeterministic(): void
    {
        $holes = [HeightfieldHole::rect(21.3, 17.9, 5.0, 5.0, 0.2), HeightfieldHole::circle(8.4, 30.2, 3.0, 20)];
        $zone = static fn (float $x, float $z): string => $x < 20.0 ? 'a' : 'b';
        self::assertEquals(HeightfieldGround::build(self::grid(), $holes, $zone), HeightfieldGround::build(self::grid(), $holes, $zone));
        $first = HeightfieldGround::build(self::grid(), $holes, $zone);
        $second = HeightfieldGround::build(self::grid(), $holes, $zone);
        self::assertSame(serialize($first), serialize($second), 'bit for bit');
    }

    public function testContentKeyFollowsEveryInput(): void
    {
        $grid = self::grid();
        $hole = [HeightfieldHole::rect(21.3, 17.9, 5.0, 5.0)];
        $key = HeightfieldGround::contentKey($grid, $hole, 'zones-v1', 1.0);
        self::assertSame($key, HeightfieldGround::contentKey(self::grid(), [HeightfieldHole::rect(21.3, 17.9, 5.0, 5.0)], 'zones-v1', 1.0));
        self::assertNotSame($key, HeightfieldGround::contentKey($grid, [HeightfieldHole::rect(21.31, 17.9, 5.0, 5.0)], 'zones-v1', 1.0));
        self::assertNotSame($key, HeightfieldGround::contentKey($grid, $hole, 'zones-v2', 1.0));
        self::assertNotSame($key, HeightfieldGround::contentKey($grid, $hole, 'zones-v1', 0.5));
        self::assertNotSame($key, HeightfieldGround::contentKey($grid, $hole, 'zones-v1', 1.0, 0.1));
        self::assertNotSame($key, HeightfieldGround::contentKey(self::grid(0.0, 0.0, 40.0, 42.0), $hole, 'zones-v1', 1.0));
        self::assertSame('747024237a96dcf890886f211c2c660a', HeightfieldGround::contentKey(new HeightfieldGrid(0, 0, 1, 1, 2.0, array_fill(0, 16, 1.5)), [HeightfieldHole::rect(1.0, 1.0, 0.5, 0.5)], 'z', 1.0));
    }

    public function testHoleGeometryIsValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HeightfieldHole::rect(0.0, 0.0, 0.0, 1.0);
    }
}
