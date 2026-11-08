<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BirdMesh;

/** Gliding bird for swarms: head at +Z, back up, wings spanning X, unit normals. */
final class BirdMeshTest extends TestCase
{
    public function testShapeAndOrientation(): void
    {
        $m = BirdMesh::generate(1.2, 0.45, 0.18);
        $xs = $ys = $zs = [];
        for ($i = 0, $c = count($m->vertices); $i < $c; $i += 3) {
            $xs[] = $m->vertices[$i];
            $ys[] = $m->vertices[$i + 1];
            $zs[] = $m->vertices[$i + 2];
        }
        self::assertEqualsWithDelta(1.2, max($xs) - min($xs), 1e-6, 'wing span along X');
        self::assertEqualsWithDelta(0.225, max($zs), 1e-6, 'beak at +Z (half the length)');
        self::assertLessThanOrEqual(max($zs) + 1e-6, -min($zs), 'the tail is not longer than the head side');
        // The wing tips stand above the body (dihedral), never below it.
        $tipY = max($ys);
        self::assertGreaterThan(0.1, $tipY);
        self::assertGreaterThan(-0.05, min($ys));
        for ($i = 0, $c = count($m->normals); $i < $c; $i += 3) {
            $len = sqrt($m->normals[$i] ** 2 + $m->normals[$i + 1] ** 2 + $m->normals[$i + 2] ** 2);
            self::assertEqualsWithDelta(1.0, $len, 1e-6);
        }
        self::assertSame(0, count($m->indices) % 3);
        self::assertLessThan(400, $m->triangleCount(), 'low poly: hundreds of birds in a flock');
    }

    public function testRejectsNonPositiveSizes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BirdMesh::generate(0.0, 0.4);
    }
}
