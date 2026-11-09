<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\CylinderMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Geometry\SphereMesh;

class GeometryTest extends TestCase
{
    protected function setUp(): void
    {
        MeshRegistry::clear();
    }

    // ─── BoxMesh ──────────────────────────────────────────────────────────────

    public function testBoxMeshVertexCount(): void
    {
        $mesh = BoxMesh::generate(1.0, 1.0, 1.0);
        // 6 faces × 4 vertices = 24
        $this->assertEquals(24, $mesh->vertexCount());
    }

    public function testBoxMeshIndexCount(): void
    {
        $mesh = BoxMesh::generate(1.0, 1.0, 1.0);
        // 6 faces × 2 triangles × 3 = 36
        $this->assertCount(36, $mesh->indices);
        $this->assertEquals(12, $mesh->triangleCount());
    }

    public function testBoxMeshNormalsAreUnitVectors(): void
    {
        $mesh = BoxMesh::generate(2.0, 3.0, 4.0);
        $n = $mesh->normals;
        for ($i = 0; $i < count($n); $i += 3) {
            $len = sqrt($n[$i] ** 2 + $n[$i + 1] ** 2 + $n[$i + 2] ** 2);
            $this->assertEqualsWithDelta(1.0, $len, 1e-6, "Normal at index $i is not unit length");
        }
    }

    // ─── SphereMesh ───────────────────────────────────────────────────────────

    public function testSphereMeshVertexCount(): void
    {
        $stacks = 6;
        $slices = 8;
        $mesh = SphereMesh::generate(1.0, $stacks, $slices);
        // (stacks+1) × (slices+1) vertices
        $this->assertEquals(($stacks + 1) * ($slices + 1), $mesh->vertexCount());
    }

    public function testSphereMeshVerticesAreOnSurface(): void
    {
        $radius = 2.0;
        $mesh = SphereMesh::generate($radius, 6, 8);
        $v = $mesh->vertices;
        for ($i = 0; $i < count($v); $i += 3) {
            $dist = sqrt($v[$i] ** 2 + $v[$i + 1] ** 2 + $v[$i + 2] ** 2);
            $this->assertEqualsWithDelta($radius, $dist, 1e-5, "Vertex at index $i not on sphere surface");
        }
    }

    // ─── CylinderMesh ─────────────────────────────────────────────────────────

    public function testCylinderMeshHasExpectedTriangles(): void
    {
        $segments = 8;
        $mesh = CylinderMesh::generate(1.0, 2.0, $segments);
        // Side: segments × 2 triangles
        // Top cap: segments triangles
        // Bottom cap: segments triangles
        $expected = $segments * 2 + $segments + $segments;
        $this->assertEquals($expected, $mesh->triangleCount());
    }

    // ─── PlaneMesh ────────────────────────────────────────────────────────────

    public function testPlaneMeshHas4VerticesAnd6Indices(): void
    {
        $mesh = PlaneMesh::generate(10.0, 10.0);
        $this->assertEquals(4, $mesh->vertexCount());
        $this->assertCount(6, $mesh->indices);
        $this->assertEquals(2, $mesh->triangleCount());
    }

    public function testPlaneMeshNormalsPointUp(): void
    {
        $mesh = PlaneMesh::generate(5.0, 5.0);
        $n = $mesh->normals;
        for ($i = 0; $i < count($n); $i += 3) {
            $this->assertEqualsWithDelta(0.0, $n[$i],     1e-6);
            $this->assertEqualsWithDelta(1.0, $n[$i + 1], 1e-6);
            $this->assertEqualsWithDelta(0.0, $n[$i + 2], 1e-6);
        }
    }

    public function testPlaneMeshTrianglesFaceAlongTheirNormal(): void
    {
        // The shader flips N on back faces (gl_FrontFacing), so a plane wound
        // against its +Y normal would shade as its own underside from above.
        $mesh = PlaneMesh::generate(4.0, 2.0, 3);
        $v = $mesh->vertices;
        $idx = $mesh->indices;
        for ($t = 0; $t < count($idx); $t += 3) {
            [$a, $b, $c] = [$idx[$t] * 3, $idx[$t + 1] * 3, $idx[$t + 2] * 3];
            $e1 = [$v[$b] - $v[$a], $v[$b + 1] - $v[$a + 1], $v[$b + 2] - $v[$a + 2]];
            $e2 = [$v[$c] - $v[$a], $v[$c + 1] - $v[$a + 1], $v[$c + 2] - $v[$a + 2]];
            $ny = $e1[2] * $e2[0] - $e1[0] * $e2[2];
            $this->assertGreaterThan(0.0, $ny, "triangle {$t} faces up");
        }
    }

    // ─── MeshRegistry ─────────────────────────────────────────────────────────

    public function testMeshRegistryRegisterAndGet(): void
    {
        $mesh = BoxMesh::generate(1.0, 1.0, 1.0);
        MeshRegistry::register('box_1x1x1', $mesh);
        $retrieved = MeshRegistry::get('box_1x1x1');
        $this->assertNotNull($retrieved);
        $this->assertEquals($mesh->vertexCount(), $retrieved->vertexCount());
    }

    public function testMeshRegistryHasReturnsFalseForUnknown(): void
    {
        $this->assertFalse(MeshRegistry::has('nonexistent'));
    }

    public function testMeshRegistryHasReturnsTrueAfterRegister(): void
    {
        MeshRegistry::register('plane', PlaneMesh::generate(1.0, 1.0));
        $this->assertTrue(MeshRegistry::has('plane'));
    }

    public function testMeshRegistryClear(): void
    {
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MeshRegistry::clear();
        $this->assertFalse(MeshRegistry::has('box'));
        $this->assertEmpty(MeshRegistry::ids());
    }

    public function testMeshRegistryGetReturnsNullForMissing(): void
    {
        $this->assertNull(MeshRegistry::get('missing'));
    }
}
