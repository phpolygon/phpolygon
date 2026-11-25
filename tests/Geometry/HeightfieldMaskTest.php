<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\HeightfieldGrid;
use PHPolygon\Geometry\HeightfieldGround;
use PHPolygon\Geometry\HeightfieldHole;
use PHPolygon\Geometry\HeightfieldZones;
use PHPolygon\Geometry\LatticeCodec;
use PHPUnit\Framework\TestCase;

final class HeightfieldMaskTest extends TestCase
{
    private static function terrain(float $x, float $z): float
    {
        return 0.5 * $x + 0.25 * $z + sin($x * 0.3) * cos($z * 0.2);
    }

    /** Ground west of x = 12 and outside a 6 x 6 m pond at (20..26, 8..14), cell 2. */
    private static function masked(): HeightfieldGrid
    {
        return HeightfieldGrid::sample(0.0, 0.0, 32.0, 32.0, 2.0, static function (float $x, float $z): ?float {
            if ($x > 12.0 || ($x >= 8.0 && $x <= 10.0 && $z >= 8.0 && $z <= 12.0)) {
                return null;
            }

            return self::terrain($x, $z);
        });
    }

    public function testAGridWithoutNullsHasNoMaskAndHashesAsBefore(): void
    {
        $plain = HeightfieldGrid::sample(0.0, 0.0, 8.0, 8.0, 2.0, self::terrain(...));
        self::assertFalse($plain->isMasked());
        self::assertTrue($plain->hasGround(3, 3));
        self::assertTrue($plain->triangleHasGround(0, 0, 1));
        $copy = new HeightfieldGrid($plain->i0, $plain->j0, $plain->nx, $plain->nz, $plain->cell, $plain->heights);
        self::assertSame($plain->contentHash(), $copy->contentHash());
    }

    public function testNullCornersSetTheMaskAndTheHashSeesIt(): void
    {
        $g = self::masked();
        self::assertTrue($g->isMasked());
        self::assertFalse($g->hasGround(8, 5));
        self::assertTrue($g->hasGround(2, 2));
        $unmasked = new HeightfieldGrid($g->i0, $g->j0, $g->nx, $g->nz, $g->cell, $g->heights);
        self::assertNotSame($g->contentHash(), $unmasked->contentHash());
    }

    public function testAGroundlessCornerIsFilledWithTheMeanOfTheNearestGroundCorners(): void
    {
        // Corner (7, 3) = x 14 is void; the nearest ring (r = 1) holds ground at x 12 (i 6) only: (6, 2), (6, 3), (6, 4).
        $g = self::masked();
        $expected = (self::terrain(12.0, 4.0) + self::terrain(12.0, 6.0) + self::terrain(12.0, 8.0)) / 3.0;
        self::assertEqualsWithDelta($expected, $g->cornerHeight(7, 3), 1e-12);
    }

    public function testNoTriangleTouchesAGroundlessCorner(): void
    {
        $g = self::masked();
        $meshes = HeightfieldGround::build($g);
        $count = 0;
        foreach ($meshes as $m) {
            for ($t = 0; $t + 2 < count($m->indices); $t += 3) {
                $count++;
                for ($k = 0; $k < 3; $k++) {
                    $v = $m->indices[$t + $k] * 3;
                    $i = (int) round($m->vertices[$v] / 2.0);
                    $j = (int) round($m->vertices[$v + 2] / 2.0);
                    self::assertTrue($g->hasGround($i, $j), "vertex at lattice ($i, $j) has no ground");
                }
            }
        }
        // 12 columns of cells (x 0..24 of which 0..12 are all ground = 6 cells) minus the pond cells: counted by the rule itself.
        $expected = 0;
        for ($j = 0; $j < 16; $j++) {
            for ($i = 0; $i < 16; $i++) {
                $expected += ($g->triangleHasGround($i, $j, 0) ? 1 : 0) + ($g->triangleHasGround($i, $j, 1) ? 1 : 0);
            }
        }
        self::assertSame($expected, $count);
        self::assertLessThan(2 * 16 * 16, $count);
    }

    public function testGroundHeightAtFollowsTheMeshAndIsNullWithoutATriangle(): void
    {
        $g = self::masked();
        self::assertNull($g->groundHeightAt(20.0, 20.0), 'east of the coast');
        self::assertNull($g->groundHeightAt(-1.0, 4.0), 'outside the grid');
        self::assertEqualsWithDelta($g->heightAt(3.3, 4.1), (float) $g->groundHeightAt(3.3, 4.1), 1e-9);
        // A point on a shared edge does not fall through.
        self::assertNotNull($g->groundHeightAt(4.0, 5.0));
        // The corner of a triangle with a void corner: the neighbour that has all its corners still answers.
        self::assertEqualsWithDelta(self::terrain(12.0, 4.0), (float) $g->groundHeightAt(12.0, 4.0), 1e-9);
        // Holes remove ground strictly inside; the outline keeps it.
        $hole = HeightfieldHole::rect(3.0, 3.0, 2.0, 2.0);
        self::assertNull($g->groundHeightAt(3.0, 3.0, [$hole]));
        self::assertNotNull($g->groundHeightAt(4.0, 3.0, [$hole]));
    }

    public function testGroundHeightAtAgreesWithEveryMeshTriangleCentroid(): void
    {
        $g = self::masked();
        foreach (HeightfieldGround::build($g) as $m) {
            for ($t = 0; $t + 2 < count($m->indices); $t += 3) {
                $x = $y = $z = 0.0;
                for ($k = 0; $k < 3; $k++) {
                    $v = $m->indices[$t + $k] * 3;
                    $x += $m->vertices[$v] / 3.0;
                    $y += $m->vertices[$v + 1] / 3.0;
                    $z += $m->vertices[$v + 2] / 3.0;
                }
                self::assertEqualsWithDelta($y, (float) $g->groundHeightAt($x, $z), 1e-9);
            }
        }
    }

    public function testFromCornersMatchesSampleOnTheCornersAndCarriesTheMask(): void
    {
        $corners = [];
        for ($j = 0; $j <= 4; $j++) {
            for ($i = 0; $i <= 4; $i++) {
                $corners[] = $i === 2 && $j === 2 ? null : (float) ($i + $j);
            }
        }
        $g = HeightfieldGrid::fromCorners(-2, -2, 4, 4, 1.0, $corners);
        self::assertFalse($g->hasGround(0, 0));
        self::assertTrue($g->hasGround(-2, -2));
        // Corner (-1, -2) is the second of the first row: 1 + 0.
        self::assertSame(1.0, $g->cornerHeight(-1, -2));
        // The null corner (array position 2, 2 -> value 4 in the middle of a plane i + j) is filled with the mean of its eight neighbours = 4.
        self::assertEqualsWithDelta(4.0, $g->cornerHeight(0, 0), 1e-12);
    }

    public function testZonesGiveTriangleZonesAndFeedTheGroundBuild(): void
    {
        // 2 x 2 cells, corners 3 x 3: columns 0 grass, 1 grass, 2 sand; a void corner at (0, 0).
        $bytes = chr(0) . chr(1) . chr(2) . chr(1) . chr(1) . chr(2) . chr(1) . chr(1) . chr(2);
        $z = new HeightfieldZones(0, 0, 3, 3, $bytes);
        self::assertSame(HeightfieldZones::VOID, $z->zone(-1, 0));
        self::assertSame(2, $z->zone(2, 1));
        // Cell (0, 0) is rising: triangle 0 = (0,0),(0,1),(1,1) holds the void corner, triangle 1 = (0,0),(1,1),(1,0) too.
        self::assertSame(0, $z->triangleZone(0, 0, 0));
        self::assertSame(0, $z->triangleZone(0, 0, 1));
        // Cell (0, 1): corners (0,1),(1,1),(1,2),(0,2) all grass.
        self::assertSame(1, $z->triangleZone(0, 1, 0));
        // Cell (1, 0) is not rising: triangle 0 = (1,0),(1,1),(2,0): grass, grass, sand = mixed; the window holds more grass than sand.
        self::assertSame(1, $z->triangleZone(1, 0, 0));
    }

    public function testASmoothedBorderBeatsTheMajorityOfThreeCorners(): void
    {
        // Sand where x > 0.5 * z + 6 (an oblique border), grass elsewhere, on a 24 x 24 lattice.
        $w = 24;
        $bytes = '';
        for ($j = 0; $j < $w; $j++) {
            for ($i = 0; $i < $w; $i++) {
                $bytes .= chr($i > 0.5 * $j + 6.0 ? 2 : 1);
            }
        }
        $zones = new HeightfieldZones(0, 0, $w, $w, $bytes);
        // The smoothed zone of every mixed triangle follows the border line to within a cell or two: its centroid lies near it.
        $mixed = 0;
        for ($j = 3; $j < $w - 4; $j++) {
            for ($i = 3; $i < $w - 4; $i++) {
                for ($which = 0; $which < 2; $which++) {
                    $sand = 0;
                    foreach (HeightfieldGrid::triangleCorners($i, $j, $which) as [$ci, $cj]) {
                        $sand += $zones->zone($ci, $cj) === 2 ? 1 : 0;
                    }
                    if ($sand === 0 || $sand === 3) {
                        continue;
                    }
                    $mixed++;
                    // Every mixed triangle of a cell shares the cell's window: the zone follows the side of the border the cell centre is on.
                    $side = ($i + 0.5) - (0.5 * ($j + 0.5) + 6.0);
                    if (abs($side) < 0.7) {
                        continue;
                    }
                    $expected = $side > 0.0 ? 2 : 1;
                    self::assertSame($expected, $zones->triangleZone($i, $j, $which), "triangle ($i, $j, $which) at side $side");
                }
            }
        }
        self::assertGreaterThan(10, $mixed);

        $names = [1 => 'grass', 2 => 'sand'];
        $grid = HeightfieldGrid::sample(4.0, 4.0, 30.0, 30.0, 2.0, self::terrain(...));
        $meshes = HeightfieldGround::build($grid, [], (new HeightfieldZones(-20, -20, $w * 4, $w * 4, str_repeat(chr(1), $w * $w * 16)))->closure(2.0, $names));
        self::assertSame(['grass'], array_keys($meshes));
    }

    public function testLatticeAndMaskCarryAColdTileThroughTheCodecUnchanged(): void
    {
        $g = self::masked();
        $corners = [];
        for ($j = $g->j0; $j <= $g->j0 + $g->nz; $j++) {
            for ($i = $g->i0; $i <= $g->i0 + $g->nx; $i++) {
                $corners[] = $g->hasGround($i, $j) ? $g->cornerHeight($i, $j) : null;
            }
        }
        $packed = LatticeCodec::packHeights($corners, $g->nx + 1, 20.0, 1000.0);
        $back = LatticeCodec::unpackHeights($packed, $g->nx + 1, 20.0, 1000.0);
        $rebuilt = HeightfieldGrid::fromCorners($g->i0, $g->j0, $g->nx, $g->nz, $g->cell, $back);
        for ($j = $g->j0; $j <= $g->j0 + $g->nz; $j++) {
            for ($i = $g->i0; $i <= $g->i0 + $g->nx; $i++) {
                self::assertSame($g->hasGround($i, $j), $rebuilt->hasGround($i, $j));
                if ($g->hasGround($i, $j)) {
                    self::assertEqualsWithDelta($g->cornerHeight($i, $j), $rebuilt->cornerHeight($i, $j), 5.1e-4);
                }
            }
        }
    }
}
