<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\TorusMesh;

class TorusMeshTest extends TestCase
{
    public function testVertexAndTriangleCountsMatchSegments(): void
    {
        $mesh = TorusMesh::generate(1.0, 0.4, 12, 24);

        // (radialSegments + 1) * (tubularSegments + 1) vertices.
        $this->assertSame(13 * 25, $mesh->vertexCount());
        // radialSegments * tubularSegments * 2 triangles.
        $this->assertSame(12 * 24 * 2, $mesh->triangleCount());
        $this->assertCount($mesh->vertexCount() * 3, $mesh->normals);
        $this->assertCount($mesh->vertexCount() * 2, $mesh->uvs);
    }

    public function testVerticesLieOnTheTubeSurface(): void
    {
        $radius = 2.0;
        $tube = 0.5;
        $mesh = TorusMesh::generate($radius, $tube, 8, 16);

        $count = $mesh->vertexCount();
        for ($v = 0; $v < $count; $v++) {
            $x = $mesh->vertices[$v * 3];
            $y = $mesh->vertices[$v * 3 + 1];
            $z = $mesh->vertices[$v * 3 + 2];
            // Distance from the centre ring (in the XY plane) must equal `tube`.
            $ringDist = sqrt($x * $x + $y * $y);
            $dx = $ringDist - $radius;
            $distToTube = sqrt($dx * $dx + $z * $z);
            $this->assertEqualsWithDelta($tube, $distToTube, 1e-9);
        }
    }

    public function testNormalsAreUnitLength(): void
    {
        $mesh = TorusMesh::generate(1.0, 0.3, 6, 12);
        $count = $mesh->vertexCount();
        for ($v = 0; $v < $count; $v++) {
            $nx = $mesh->normals[$v * 3];
            $ny = $mesh->normals[$v * 3 + 1];
            $nz = $mesh->normals[$v * 3 + 2];
            $this->assertEqualsWithDelta(1.0, sqrt($nx * $nx + $ny * $ny + $nz * $nz), 1e-9);
        }
    }
}
