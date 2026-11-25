<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\HeightfieldGrid;
use PHPolygon\Geometry\HeightfieldGround;
use PHPolygon\Geometry\HeightfieldHole;
use PHPolygon\Geometry\MeshData;
use PHPUnit\Framework\TestCase;

final class HeightfieldFlatBlockTest extends TestCase
{
    /** A level plateau for x <= 48, a slope with a ripple beyond it. */
    private static function plateau(float $x, float $z): float
    {
        return $x <= 48.0 ? 3.0 : 3.0 + 0.2 * ($x - 48.0) + 0.3 * sin($z * 0.4);
    }

    private static function pos(MeshData $m, int $index): string
    {
        $i = $index * 3;

        return round($m->vertices[$i] * 1e6) . ':' . round($m->vertices[$i + 2] * 1e6);
    }

    /**
     * @return array<string, int> undirected edge key => number of triangles using it
     */
    private static function edgeUse(MeshData $m): array
    {
        $use = [];
        for ($t = 0; $t + 2 < count($m->indices); $t += 3) {
            for ($k = 0; $k < 3; $k++) {
                $a = self::pos($m, $m->indices[$t + $k]);
                $b = self::pos($m, $m->indices[$t + (($k + 1) % 3)]);
                $key = $a < $b ? "$a|$b" : "$b|$a";
                $use[$key] = ($use[$key] ?? 0) + 1;
            }
        }

        return $use;
    }

    private static function area(MeshData $m): float
    {
        $sum = 0.0;
        for ($t = 0; $t + 2 < count($m->indices); $t += 3) {
            $a = $m->indices[$t] * 3;
            $b = $m->indices[$t + 1] * 3;
            $c = $m->indices[$t + 2] * 3;
            $sum += 0.5 * (($m->vertices[$b + 2] - $m->vertices[$a + 2]) * ($m->vertices[$c] - $m->vertices[$a]) - ($m->vertices[$b] - $m->vertices[$a]) * ($m->vertices[$c + 2] - $m->vertices[$a + 2]));
        }

        return $sum;
    }

    public function testFlatBlocksCutTheTriangleCountAndKeepTheSurface(): void
    {
        $grid = HeightfieldGrid::sample(0.0, 0.0, 96.0, 64.0, 2.0, self::plateau(...));
        $plain = HeightfieldGround::build($grid)['ground'];
        $blocks = HeightfieldGround::build($grid, [], null, 1.0, 0.05, 8)['ground'];

        // The plateau holds 3 x 4 blocks of 16 m (8 cells): 12 blocks of 128 triangles become 12 blocks of 32.
        self::assertSame($plain->triangleCount() - 12 * (128 - 32), $blocks->triangleCount());
        self::assertEqualsWithDelta(self::area($plain), self::area($blocks), 1e-6);
        // Every vertex lies on the grid's own surface (the blocks are exactly flat).
        for ($v = 0; $v < count($blocks->vertices); $v += 3) {
            self::assertEqualsWithDelta($grid->heightAt($blocks->vertices[$v], $blocks->vertices[$v + 2]), $blocks->vertices[$v + 1], 1e-9);
        }
        for ($t = 0; $t + 2 < count($blocks->indices); $t += 3) {
            $a = $blocks->indices[$t] * 3;
            $b = $blocks->indices[$t + 1] * 3;
            $c = $blocks->indices[$t + 2] * 3;
            $cross = ($blocks->vertices[$b + 2] - $blocks->vertices[$a + 2]) * ($blocks->vertices[$c] - $blocks->vertices[$a]) - ($blocks->vertices[$b] - $blocks->vertices[$a]) * ($blocks->vertices[$c + 2] - $blocks->vertices[$a + 2]);
            self::assertGreaterThan(0.0, $cross, 'counter-clockwise from above');
        }
    }

    public function testBordersStayWatertightAgainstSingleCells(): void
    {
        $grid = HeightfieldGrid::sample(0.0, 0.0, 96.0, 64.0, 2.0, self::plateau(...));
        $plain = self::edgeUse(HeightfieldGround::build($grid)['ground']);
        $blocks = self::edgeUse(HeightfieldGround::build($grid, [], null, 1.0, 0.05, 8)['ground']);
        $open = static fn (array $use): array => array_keys(array_filter($use, static fn (int $n): bool => $n === 1));
        $openPlain = $open($plain);
        $openBlocks = $open($blocks);
        sort($openPlain);
        sort($openBlocks);
        // Open edges only on the outer border, the same ones as without blocks; no edge is used by more than two triangles.
        self::assertSame($openPlain, $openBlocks);
        self::assertSame([], array_filter($blocks, static fn (int $n): bool => $n > 2));
    }

    public function testNeighbouringTilesAgreeOnTheirSharedBorder(): void
    {
        $left = HeightfieldGrid::sample(0.0, 0.0, 48.0, 32.0, 2.0, self::plateau(...));
        $right = HeightfieldGrid::sample(48.0, 0.0, 96.0, 32.0, 2.0, self::plateau(...));
        $borders = [];
        foreach ([[$left, 48.0], [$right, 48.0]] as [$g, $x]) {
            $m = HeightfieldGround::build($g, [], null, 1.0, 0.05, 8)['ground'];
            $set = [];
            for ($v = 0; $v < count($m->vertices); $v += 3) {
                if (abs($m->vertices[$v] - $x) < 1e-9) {
                    $set[] = round($m->vertices[$v + 2] * 1e6) . ':' . round($m->vertices[$v + 1] * 1e6);
                }
            }
            sort($set);
            $borders[] = array_values(array_unique($set));
        }
        self::assertSame($borders[0], $borders[1]);
        self::assertCount(17, $borders[0], 'every 2 m lattice corner of the 32 m border is a vertex');
    }

    public function testAHoleKeepsTheBlocksAroundItAsSingleCells(): void
    {
        $grid = HeightfieldGrid::sample(0.0, 0.0, 96.0, 64.0, 2.0, self::plateau(...));
        $hole = HeightfieldHole::circle(8.0, 8.0, 3.0);
        $plain = HeightfieldGround::build($grid, [$hole])['ground'];
        $blocks = HeightfieldGround::build($grid, [$hole], null, 1.0, 0.05, 8)['ground'];
        // The block at (0..16, 0..16) holds the hole: 11 blocks of 96 saved triangles remain.
        self::assertSame($plain->triangleCount() - 11 * (128 - 32), $blocks->triangleCount());
        $use = self::edgeUse($blocks);
        self::assertSame([], array_filter($use, static fn (int $n): bool => $n > 2));
    }

    public function testATolerantBlockDeviatesByAtMostTheTolerance(): void
    {
        $bump = static fn (float $x, float $z): float => abs($x - 8.0) < 0.1 && abs($z - 8.0) < 0.1 ? 0.01 : 1.0;
        $grid = HeightfieldGrid::sample(0.0, 0.0, 16.0, 16.0, 2.0, $bump);
        $exact = HeightfieldGround::build($grid, [], null, 1.0, 0.05, 8)['ground'];
        $loose = HeightfieldGround::build($grid, [], null, 1.0, 0.05, 8, 1.0)['ground'];
        self::assertSame(2 * 8 * 8, $exact->triangleCount(), 'not flat to 1 micrometre: single cells');
        self::assertSame(4 * 8, $loose->triangleCount());
    }

    public function testABlockOfTwoZonesStaysSingleCells(): void
    {
        $grid = HeightfieldGrid::sample(0.0, 0.0, 32.0, 16.0, 2.0, static fn (): float => 1.0);
        $zone = static fn (float $x, float $z): string => $x < 20.0 ? 'a' : 'b';
        $m = HeightfieldGround::build($grid, [], $zone, 1.0, 0.05, 8);
        self::assertSame(['a', 'b'], array_keys($m));
        // Block 0..16 is one zone: 32 triangles. Block 16..32 is split at x = 20 and stays single cells: columns 8 and 9 (x 16..20)
        // hold 2 x 8 x 2 = 32 triangles of zone a, columns 10..15 hold 6 x 8 x 2 = 96 of zone b.
        self::assertSame(32 + 32, $m['a']->triangleCount());
        self::assertSame(96, $m['b']->triangleCount());
    }

    public function testContentKeyChangesOnlyWhenFlatBlocksAreUsed(): void
    {
        $grid = HeightfieldGrid::sample(0.0, 0.0, 16.0, 16.0, 2.0, self::plateau(...));
        $base = HeightfieldGround::contentKey($grid);
        self::assertSame($base, HeightfieldGround::contentKey($grid, [], '', 1.0, 0.05, 0));
        self::assertNotSame($base, HeightfieldGround::contentKey($grid, [], '', 1.0, 0.05, 8));
        self::assertNotSame(HeightfieldGround::contentKey($grid, [], '', 1.0, 0.05, 8), HeightfieldGround::contentKey($grid, [], '', 1.0, 0.05, 4));
    }

    public function testBadOptionsThrow(): void
    {
        $grid = HeightfieldGrid::sample(0.0, 0.0, 16.0, 16.0, 2.0, self::plateau(...));
        $this->expectException(\InvalidArgumentException::class);
        HeightfieldGround::build($grid, [], null, 1.0, 0.05, 1);
    }

    public function testBestFitDiagonalFollowsACreaseTheChessboardCutsAcross(): void
    {
        // A valley along the line x + z = 6: it runs from corner (4, 2) to corner (2, 4) of cell (1, 1), whose chessboard
        // diagonal is the other one (i + j even = rising).
        $valley = static fn (float $x, float $z): float => 0.5 * abs($x + $z - 6.0);
        $chess = HeightfieldGrid::sample(0.0, 0.0, 8.0, 8.0, 2.0, $valley);
        $fit = HeightfieldGrid::sample(0.0, 0.0, 8.0, 8.0, 2.0, $valley, true);
        self::assertTrue($chess->risingAt(1, 1));
        self::assertFalse($fit->risingAt(1, 1));
        self::assertEqualsWithDelta(1.0, $chess->heightAt(3.0, 3.0), 1e-12, 'the chessboard diagonal lifts the valley floor');
        self::assertEqualsWithDelta(0.0, $fit->heightAt(3.0, 3.0), 1e-12);
        self::assertEqualsWithDelta(0.0, (float) $fit->groundHeightAt(3.0, 3.0), 1e-12);
        self::assertNotSame($chess->contentHash(), $fit->contentHash());
        self::assertSame($fit->contentHash(), HeightfieldGrid::sample(0.0, 0.0, 8.0, 8.0, 2.0, $valley, true)->contentHash());

        // The mesh uses the same diagonal: the edge (4, 2)-(2, 4) exists, the edge (2, 2)-(4, 4) does not.
        $m = HeightfieldGround::build($fit, [], null, 1.0, 0.0)['ground'];
        $edges = self::edgeUse($m);
        $key = static fn (string $a, string $b): string => $a < $b ? "$a|$b" : "$b|$a";
        $p = static fn (float $x, float $z): string => round($x * 1e6) . ':' . round($z * 1e6);
        self::assertArrayHasKey($key($p(4.0, 2.0), $p(2.0, 4.0)), $edges);
        self::assertArrayNotHasKey($key($p(2.0, 2.0), $p(4.0, 4.0)), $edges);
        // Every mesh vertex lies on the fitted surface.
        for ($v = 0; $v < count($m->vertices); $v += 3) {
            self::assertEqualsWithDelta($fit->heightAt($m->vertices[$v], $m->vertices[$v + 2]), $m->vertices[$v + 1], 1e-9);
        }
    }

    public function testBestFitNeverFitsWorseThanTheChessboardAtTheCellCentres(): void
    {
        $f = static fn (float $x, float $z): float => 2.0 * sin($x * 0.5) * cos($z * 0.4) + 0.3 * abs($x - $z);
        $chess = HeightfieldGrid::sample(0.0, 0.0, 40.0, 40.0, 2.0, $f);
        $fit = HeightfieldGrid::sample(0.0, 0.0, 40.0, 40.0, 2.0, $f, true);
        $worseChess = 0.0;
        $worseFit = 0.0;
        for ($j = 0; $j < 20; $j++) {
            for ($i = 0; $i < 20; $i++) {
                $x = $i * 2.0 + 1.0;
                $z = $j * 2.0 + 1.0;
                $eChess = abs($chess->heightAt($x, $z) - $f($x, $z));
                $eFit = abs($fit->heightAt($x, $z) - $f($x, $z));
                self::assertLessThanOrEqual($eChess + 1e-9, $eFit);
                $worseChess += $eChess;
                $worseFit += $eFit;
            }
        }
        self::assertLessThan($worseChess, $worseFit);
    }

    public function testBestFitDiagonalsAreWatertightWithNeighbouringTiles(): void
    {
        $f = static fn (float $x, float $z): float => 1.5 * sin($x * 0.3 + $z * 0.2) + 0.2 * abs($x - 2.0 * $z);
        $whole = HeightfieldGrid::sample(0.0, 0.0, 32.0, 16.0, 2.0, $f, true);
        $a = HeightfieldGrid::sample(0.0, 0.0, 16.0, 16.0, 2.0, $f, true);
        $b = HeightfieldGrid::sample(16.0, 0.0, 32.0, 16.0, 2.0, $f, true);
        for ($j = 0; $j < 8; $j++) {
            for ($i = 0; $i < 16; $i++) {
                $tile = $i < 8 ? $a : $b;
                self::assertSame($whole->risingAt($i, $j), $tile->risingAt($i, $j), "cell ($i, $j)");
            }
        }
    }
}
