<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\SweepMesh;
use PHPolygon\Math\Vec2;
use PHPolygon\Math\Vec3;

final class SweepMeshTest extends TestCase
{
    public function testTubeProducesNonEmptyMesh(): void
    {
        $mesh = SweepMesh::tube(
            radius: 0.1,
            sides:  12,
            path: [new Vec3(0, 0, 0), new Vec3(1, 0, 0), new Vec3(2, 1, 0)],
        );
        $this->assertGreaterThan(0, $mesh->vertexCount());
        $this->assertGreaterThan(0, $mesh->triangleCount());
    }

    public function testStraightTubeNormalsAreRadial(): void
    {
        // Straight tube along +X. Normals at every section vertex must
        // lie in the YZ plane (no X component).
        $mesh = SweepMesh::tube(
            radius: 1.0,
            sides:  8,
            path: [new Vec3(0, 0, 0), new Vec3(1, 0, 0), new Vec3(2, 0, 0)],
            capEnds: false,
        );
        $count = $mesh->vertexCount();
        for ($i = 0; $i < $count; $i++) {
            $nx = $mesh->normals[$i * 3];
            $ny = $mesh->normals[$i * 3 + 1];
            $nz = $mesh->normals[$i * 3 + 2];
            $this->assertEqualsWithDelta(0.0, $nx, 1e-5, "Side normal must be radial (n_x = 0)");
            $len = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
            $this->assertEqualsWithDelta(1.0, $len, 1e-5, "Normal must be unit length");
        }
    }

    public function testCapEndsAddsCapTriangles(): void
    {
        $path = [new Vec3(0, 0, 0), new Vec3(1, 0, 0)];
        $sides = 8;
        $open   = SweepMesh::tube(0.1, $sides, $path, capEnds: false);
        $closed = SweepMesh::tube(0.1, $sides, $path, capEnds: true);
        // Two triangle fans of $sides triangles each = +2 * $sides.
        $this->assertSame(
            $open->triangleCount() + 2 * $sides,
            $closed->triangleCount(),
        );
    }

    public function testEmptyOnTooShortPath(): void
    {
        $mesh = SweepMesh::tube(0.1, 8, [new Vec3(0, 0, 0)]);
        $this->assertSame(0, $mesh->triangleCount());
    }

    public function testEmptyOnTooFewSides(): void
    {
        $mesh = SweepMesh::generate(
            crossSection: [new Vec2(1.0, 0.0), new Vec2(0.0, 1.0)], // only 2 points
            path: [new Vec3(0, 0, 0), new Vec3(1, 0, 0)],
        );
        $this->assertSame(0, $mesh->triangleCount());
    }

    public function testCurvedPathProducesNoNaNs(): void
    {
        // 3D arc with sharp curvature - parallel transport should not blow
        // up at the tightest bend.
        $path = [];
        $samples = 16;
        for ($i = 0; $i <= $samples; $i++) {
            $t = $i / $samples;
            $path[] = new Vec3(cos($t * M_PI) * 1.0, sin($t * M_PI) * 1.0, $t * 0.2);
        }
        $mesh = SweepMesh::tube(0.05, 8, $path);

        foreach ($mesh->vertices as $v) {
            $this->assertFalse(is_nan($v), 'No NaN in vertices');
        }
        foreach ($mesh->normals as $v) {
            $this->assertFalse(is_nan($v), 'No NaN in normals');
        }
    }
}
