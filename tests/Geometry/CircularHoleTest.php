<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\CircularHole;
use PHPolygon\Geometry\MeshAccumulator;
use PHPUnit\Framework\TestCase;

final class CircularHoleTest extends TestCase
{
    /** @param list<array{0: float, 1: float, 2: float}> $poly */
    private static function area(array $poly): float
    {
        $sum = 0.0;
        $n = count($poly);
        for ($i = 0; $i < $n; $i++) {
            $a = $poly[$i];
            $b = $poly[($i + 1) % $n];
            $sum += $a[0] * $b[2] - $b[0] * $a[2];
        }

        return abs($sum) * 0.5;
    }

    public function testAPolygonAwayFromTheHoleIsReturnedWhole(): void
    {
        $hole = new CircularHole(0.0, 0.0, 2.0);
        $poly = [[10.0, 1.0, 10.0], [14.0, 1.0, 10.0], [12.0, 1.0, 14.0]];
        self::assertSame([$poly], $hole->outside($poly));
        self::assertFalse($hole->reaches($poly));
    }

    public function testAPolygonInsideTheHoleVanishes(): void
    {
        $hole = new CircularHole(0.0, 0.0, 2.0);
        self::assertSame([], $hole->outside([[-0.5, 0.0, -0.5], [0.5, 0.0, -0.5], [0.0, 0.0, 0.5]]));
    }

    public function testTheCutPiecesAddUpToTheSquareMinusThePolygon(): void
    {
        $r = 2.0;
        $sides = 24;
        $hole = new CircularHole(1.0, -1.0, $r, $sides);
        $square = [[-9.0, 3.0, -11.0], [11.0, 3.0, -11.0], [11.0, 3.0, 9.0], [-9.0, 3.0, 9.0]];
        $pieces = $hole->outside($square);
        $sum = 0.0;
        foreach ($pieces as $piece) {
            self::assertGreaterThanOrEqual(3, count($piece));
            $sum += self::area($piece);
            foreach ($piece as $p) {
                self::assertEqualsWithDelta(3.0, $p[1], 1e-9, 'height is kept');
                // No corner of a piece lies inside the opening.
                self::assertFalse($hole->contains($p[0] + 1e-4 * ($p[0] - 1.0), $p[2] + 1e-4 * ($p[2] + 1.0)) && hypot($p[0] - 1.0, $p[2] + 1.0) < $r * cos(M_PI / $sides) - 1e-3);
            }
        }
        $ngon = 0.5 * $sides * $r * $r * sin(2.0 * M_PI / $sides);
        self::assertEqualsWithDelta(400.0 - $ngon, $sum, 1e-6);
    }

    public function testCutMeshKeepsFarTrianglesAndDropsTheOnesInTheHole(): void
    {
        $m = new MeshAccumulator();
        $m->quad([-9.0, 0.0, -9.0], [9.0, 0.0, -9.0], [9.0, 0.0, 9.0], [-9.0, 0.0, 9.0], [0.0, 1.0, 0.0]);
        $m->quad([20.0, 0.0, 20.0], [24.0, 0.0, 20.0], [24.0, 0.0, 24.0], [20.0, 0.0, 24.0], [0.0, 1.0, 0.0]);
        $cut = (new CircularHole(0.0, 0.0, 2.0))->cutMesh($m->build());
        $area = 0.0;
        for ($t = 0; $t + 2 < count($cut->indices); $t += 3) {
            $poly = [];
            foreach ([0, 1, 2] as $k) {
                $i = $cut->indices[$t + $k] * 3;
                $poly[] = [$cut->vertices[$i], $cut->vertices[$i + 1], $cut->vertices[$i + 2]];
            }
            $area += self::area($poly);
        }
        $ngon = 0.5 * 24 * 4.0 * sin(2.0 * M_PI / 24);
        self::assertEqualsWithDelta(324.0 + 16.0 - $ngon, $area, 1e-6);
    }

    public function testTheWallStandsOnTheRimAndFacesTheAxis(): void
    {
        $hole = new CircularHole(5.0, 7.0, 1.5, 12);
        $m = new MeshAccumulator();
        $hole->addWall($m, 0.0, 1.0);
        $mesh = $m->build();
        self::assertSame(12 * 2, $mesh->triangleCount());
        for ($i = 0; $i < count($mesh->vertices); $i += 3) {
            self::assertEqualsWithDelta(1.5, hypot($mesh->vertices[$i] - 5.0, $mesh->vertices[$i + 2] - 7.0), 1e-9);
            // The normal points to the axis.
            $toAxis = [5.0 - $mesh->vertices[$i], 7.0 - $mesh->vertices[$i + 2]];
            self::assertGreaterThan(0.0, $mesh->normals[$i] * $toAxis[0] + $mesh->normals[$i + 2] * $toAxis[1]);
        }
    }
}
