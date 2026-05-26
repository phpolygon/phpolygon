<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\OctahedronMesh;

class OctahedronMeshTest extends TestCase
{
    public function testEightFlatFaces(): void
    {
        $mesh = OctahedronMesh::generate(1.0);

        // 8 faces, emitted per-face (no shared vertices) -> 24 verts, 8 tris.
        $this->assertSame(8, $mesh->triangleCount());
        $this->assertSame(24, $mesh->vertexCount());
    }

    public function testVerticesSitOnTheRadiusAlongAxes(): void
    {
        $radius = 3.0;
        $mesh = OctahedronMesh::generate($radius);

        $count = $mesh->vertexCount();
        for ($v = 0; $v < $count; $v++) {
            $x = $mesh->vertices[$v * 3];
            $y = $mesh->vertices[$v * 3 + 1];
            $z = $mesh->vertices[$v * 3 + 2];
            // Every base vertex is an axis point at distance `radius`.
            $this->assertEqualsWithDelta($radius, sqrt($x * $x + $y * $y + $z * $z), 1e-9);
        }
    }

    public function testFaceNormalsAreUnitLength(): void
    {
        $mesh = OctahedronMesh::generate(2.0);
        $count = $mesh->vertexCount();
        for ($v = 0; $v < $count; $v++) {
            $nx = $mesh->normals[$v * 3];
            $ny = $mesh->normals[$v * 3 + 1];
            $nz = $mesh->normals[$v * 3 + 2];
            $this->assertEqualsWithDelta(1.0, sqrt($nx * $nx + $ny * $ny + $nz * $nz), 1e-9);
        }
    }
}
