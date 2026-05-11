<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\SpokedRimMesh;

final class SpokedRimMeshTest extends TestCase
{
    public function testProducesNonEmptyMesh(): void
    {
        $rim = SpokedRimMesh::generate(
            outerRadius: 0.32 * 0.62,
            innerRadius: 0.32 * 0.62 * 0.88,
            width:       0.20,
            spokeCount:  5,
        );
        $this->assertGreaterThan(0, $rim->vertexCount());
        $this->assertGreaterThan(0, $rim->triangleCount());
    }

    public function testMoreSpokesProduceMoreVertices(): void
    {
        $five = SpokedRimMesh::generate(
            outerRadius: 0.20, innerRadius: 0.18, width: 0.20, spokeCount: 5,
        );
        $eight = SpokedRimMesh::generate(
            outerRadius: 0.20, innerRadius: 0.18, width: 0.20, spokeCount: 8,
        );
        $this->assertGreaterThan($five->vertexCount(), $eight->vertexCount());
    }

    public function testSpokeCountIsClampedToReasonableRange(): void
    {
        // Below 3: must still produce a valid mesh (clamped to 3 internally).
        $tooFew = SpokedRimMesh::generate(
            outerRadius: 0.20, innerRadius: 0.18, width: 0.20, spokeCount: 0,
        );
        $this->assertGreaterThan(0, $tooFew->triangleCount());

        // Above 12: also clamped, doesn't blow up vertex count.
        $tooMany = SpokedRimMesh::generate(
            outerRadius: 0.20, innerRadius: 0.18, width: 0.20, spokeCount: 100,
        );
        // 12 spokes (clamp limit) × 8 vertices each = 96 spoke vertices,
        // plus rings and discs (~140), so somewhere around 240. The exact
        // value isn't load-bearing - we just verify the clamp engaged.
        $this->assertLessThan(400, $tooMany->vertexCount());
    }

    public function testInnerRadiusIsClampedBelowOuterRadius(): void
    {
        // Pathological input: inner > outer should not produce inverted mesh.
        $rim = SpokedRimMesh::generate(
            outerRadius: 0.18, innerRadius: 0.30, width: 0.20, spokeCount: 5,
        );
        $this->assertGreaterThan(0, $rim->triangleCount());
        // No NaNs in vertex buffer.
        foreach ($rim->vertices as $v) {
            $this->assertFalse(is_nan($v));
        }
    }

    public function testNormalsAreUnitLengthOnRingShell(): void
    {
        $rim = SpokedRimMesh::generate(
            outerRadius: 0.20, innerRadius: 0.18, width: 0.20, spokeCount: 5,
        );
        // Spot-check the first ring vertex (outer ring, bottom edge).
        $nx = $rim->normals[0];
        $ny = $rim->normals[1];
        $nz = $rim->normals[2];
        $len = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
        $this->assertEqualsWithDelta(1.0, $len, 1e-5);
    }
}
