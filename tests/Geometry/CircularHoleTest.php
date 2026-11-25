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

    /** @return array<string, array{string, int, int, int}> axis => [axis, first in-plane index, axis index, second in-plane index] */
    public static function axes(): array
    {
        return ['x' => ['x', 1, 0, 2], 'y' => ['y', 0, 1, 2], 'z' => ['z', 0, 2, 1]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('axes')]
    public function testEveryAxisCutsTheSameAreaAndKeepsTheLevel(string $axis, int $u, int $a, int $v): void
    {
        $hole = new CircularHole(1.0, -1.0, 2.0, 24, $axis);
        $mk = static function (array $q) use ($u, $a, $v): array {
            $p = [0.0, 0.0, 0.0];
            $p[$u] = $q[0];
            $p[$a] = $q[1];
            $p[$v] = $q[2];

            return $p;
        };
        $square = [$mk([-9.0, 3.0, -11.0]), $mk([11.0, 3.0, -11.0]), $mk([11.0, 3.0, 9.0]), $mk([-9.0, 3.0, 9.0])];
        $sum = 0.0;
        foreach ($hole->outside($square) as $piece) {
            $area = 0.0;
            foreach ($piece as $k => $p) {
                $q = $piece[($k + 1) % count($piece)];
                $area += $p[$u] * $q[$v] - $q[$u] * $p[$v];
                self::assertEqualsWithDelta(3.0, $p[$a], 1e-9);
            }
            $sum += abs($area) * 0.5;
        }
        self::assertEqualsWithDelta(400.0 - 0.5 * 24 * 4.0 * sin(2.0 * M_PI / 24), $sum, 1e-6);
        self::assertTrue($hole->contains(1.0, -1.0));
        self::assertFalse($hole->contains(4.0, -1.0));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('axes')]
    public function testEveryAxisBuildsAWallThatFacesTheAxis(string $axis, int $u, int $a, int $v): void
    {
        $hole = new CircularHole(5.0, 7.0, 1.5, 12, $axis);
        $m = new MeshAccumulator();
        $hole->addWall($m, 0.0, 1.0);
        $mesh = $m->build();
        self::assertSame(24, $mesh->triangleCount());
        for ($i = 0; $i < count($mesh->vertices); $i += 3) {
            self::assertEqualsWithDelta(1.5, hypot($mesh->vertices[$i + $u] - 5.0, $mesh->vertices[$i + $v] - 7.0), 1e-9);
            self::assertEqualsWithDelta(0.0, $mesh->normals[$i + $a], 1e-9, 'no normal along the axis');
            self::assertGreaterThan(0.0, $mesh->normals[$i + $u] * (5.0 - $mesh->vertices[$i + $u]) + $mesh->normals[$i + $v] * (7.0 - $mesh->vertices[$i + $v]));
        }
    }

    public function testAMeshCutAlongX(): void
    {
        $m = new MeshAccumulator();
        // A wall in the YZ plane (normal +X).
        $m->quad([0.0, -9.0, -9.0], [0.0, 9.0, -9.0], [0.0, 9.0, 9.0], [0.0, -9.0, 9.0], [1.0, 0.0, 0.0]);
        $cut = (new CircularHole(0.0, 0.0, 2.0, 24, 'x'))->cutMesh($m->build());
        $area = 0.0;
        for ($t = 0; $t + 2 < count($cut->indices); $t += 3) {
            $p = [];
            foreach ([0, 1, 2] as $k) {
                $i = $cut->indices[$t + $k] * 3;
                $p[] = [$cut->vertices[$i + 1], $cut->vertices[$i + 2]];
            }
            $area += abs(($p[1][0] - $p[0][0]) * ($p[2][1] - $p[0][1]) - ($p[2][0] - $p[0][0]) * ($p[1][1] - $p[0][1])) * 0.5;
            self::assertSame(1.0, round($cut->normals[$cut->indices[$t] * 3]), 'the wall keeps facing +X');
        }
        self::assertEqualsWithDelta(324.0 - 0.5 * 24 * 4.0 * sin(2.0 * M_PI / 24), $area, 1e-6);
    }
}
