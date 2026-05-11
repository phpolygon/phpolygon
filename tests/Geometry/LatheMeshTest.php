<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\LatheMesh;
use PHPolygon\Math\Vec2;

final class LatheMeshTest extends TestCase
{
    public function testProducesNonEmptyMesh(): void
    {
        $mesh = LatheMesh::generate([
            new Vec2(0.0, 0.0),
            new Vec2(0.5, 0.0),
            new Vec2(0.5, 1.0),
            new Vec2(0.0, 1.0),
        ], segments: 16);
        $this->assertGreaterThan(0, $mesh->vertexCount());
        $this->assertGreaterThan(0, $mesh->triangleCount());
    }

    public function testSegmentsScaleVertexCount(): void
    {
        $profile = [new Vec2(0.0, 0.0), new Vec2(1.0, 0.0), new Vec2(1.0, 1.0), new Vec2(0.0, 1.0)];
        $coarse = LatheMesh::generate($profile, segments: 8);
        $fine   = LatheMesh::generate($profile, segments: 32);
        $this->assertGreaterThan($coarse->vertexCount(), $fine->vertexCount());
    }

    public function testSegmentsClampedToMinimumThree(): void
    {
        $profile = [new Vec2(0.0, 0.0), new Vec2(1.0, 0.0), new Vec2(1.0, 1.0)];
        $mesh = LatheMesh::generate($profile, segments: 0);
        // 3 segments x 3 profile points x (segments+1 seam dup) = 12 verts.
        $this->assertSame(12, $mesh->vertexCount());
    }

    public function testEmptyOnDegenerateProfile(): void
    {
        $mesh = LatheMesh::generate([new Vec2(0.5, 0.0)], segments: 16);
        $this->assertSame(0, $mesh->vertexCount());
        $this->assertSame(0, $mesh->triangleCount());
    }

    public function testNormalsAreUnitLengthOnCylindricalProfile(): void
    {
        // Pure cylinder profile: outward normal must lie in the XZ plane
        // (n_y = 0) and have unit length.
        $mesh = LatheMesh::generate([
            new Vec2(1.0, 0.0),
            new Vec2(1.0, 1.0),
        ], segments: 16);

        $count = $mesh->vertexCount();
        for ($i = 0; $i < $count; $i++) {
            $nx = $mesh->normals[$i * 3];
            $ny = $mesh->normals[$i * 3 + 1];
            $nz = $mesh->normals[$i * 3 + 2];
            $len = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
            $this->assertEqualsWithDelta(1.0, $len, 1e-5, "Normal {$i} not unit length");
            $this->assertEqualsWithDelta(0.0, $ny, 1e-5, "Cylinder normal must have n_y = 0");
        }
    }

    public function testHollowCupProducesValidMesh(): void
    {
        // Mug-style hollow profile: outside-up, rim, inside-down, base.
        $mesh = LatheMesh::generate([
            new Vec2(0.00, 0.00),  // base center
            new Vec2(0.40, 0.00),  // base outer
            new Vec2(0.40, 1.00),  // outside top
            new Vec2(0.36, 1.00),  // rim crossing
            new Vec2(0.36, 0.05),  // inside bottom edge
            new Vec2(0.00, 0.05),  // inside center
        ], segments: 24);
        $this->assertGreaterThan(0, $mesh->triangleCount());
        foreach ($mesh->vertices as $v) {
            $this->assertFalse(is_nan($v));
        }
    }
}
