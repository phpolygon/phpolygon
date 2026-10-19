<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Geometry\SphereMesh;

final class MeshDataTest extends TestCase
{
    public function testTangentsAreNullByDefault(): void
    {
        $box = BoxMesh::generate(1.0, 1.0, 1.0);
        $this->assertNull($box->tangents);
    }

    public function testWithComputedTangentsReturnsSelfWhenAlreadyPresent(): void
    {
        $with = new MeshData(
            vertices: [0.0, 0.0, 0.0,  1.0, 0.0, 0.0,  0.0, 1.0, 0.0],
            normals:  [0.0, 0.0, 1.0,  0.0, 0.0, 1.0,  0.0, 0.0, 1.0],
            uvs:      [0.0, 0.0,  1.0, 0.0,  0.0, 1.0],
            indices:  [0, 1, 2],
            tangents: [1.0, 0.0, 0.0, 1.0,  1.0, 0.0, 0.0, 1.0,  1.0, 0.0, 0.0, 1.0],
        );
        $this->assertSame($with, $with->withComputedTangents());
    }

    public function testComputeTangentsForSimpleQuad(): void
    {
        // Quad in the XY plane: U follows +X, V follows +Y → tangent must
        // be ±X with handedness +1 for a right-handed mesh.
        $vertices = [
            0.0, 0.0, 0.0,
            1.0, 0.0, 0.0,
            1.0, 1.0, 0.0,
            0.0, 1.0, 0.0,
        ];
        $normals = [
            0.0, 0.0, 1.0,
            0.0, 0.0, 1.0,
            0.0, 0.0, 1.0,
            0.0, 0.0, 1.0,
        ];
        $uvs = [
            0.0, 0.0,
            1.0, 0.0,
            1.0, 1.0,
            0.0, 1.0,
        ];
        $indices = [0, 1, 2, 0, 2, 3];

        $tangents = MeshData::computeTangents($vertices, $normals, $uvs, $indices);

        $this->assertCount(16, $tangents); // 4 vertices × vec4
        // First vertex tangent should be (1, 0, 0, ±1).
        $this->assertEqualsWithDelta(1.0, $tangents[0], 1e-5);
        $this->assertEqualsWithDelta(0.0, $tangents[1], 1e-5);
        $this->assertEqualsWithDelta(0.0, $tangents[2], 1e-5);
        // Handedness must be ±1 (never 0).
        $this->assertContains((int)round($tangents[3]), [-1, 1]);
    }

    public function testWithComputedTangentsOnBox(): void
    {
        $box = BoxMesh::generate(2.0, 1.0, 1.5);
        $withTangents = $box->withComputedTangents();

        $this->assertNotNull($withTangents->tangents);
        $this->assertSame(
            $box->vertexCount() * 4,
            count($withTangents->tangents),
            'Tangent buffer length must equal vertexCount * 4',
        );

        // Every tangent vec3 (xyz) must be (approximately) unit length.
        for ($i = 0; $i < $box->vertexCount(); $i++) {
            $tx = $withTangents->tangents[$i * 4];
            $ty = $withTangents->tangents[$i * 4 + 1];
            $tz = $withTangents->tangents[$i * 4 + 2];
            $len = sqrt($tx * $tx + $ty * $ty + $tz * $tz);
            $this->assertEqualsWithDelta(1.0, $len, 1e-4, "Tangent {$i} not unit length");

            $hand = $withTangents->tangents[$i * 4 + 3];
            $this->assertContains((int)round($hand), [-1, 1]);
        }
    }

    public function testComputeTangentsHandlesDegenerateUVs(): void
    {
        // All UVs at the same point — degenerate, det = 0. The fallback
        // should still produce a well-formed buffer (no NaNs).
        $vertices = [0.0, 0.0, 0.0,  1.0, 0.0, 0.0,  0.0, 1.0, 0.0];
        $normals  = [0.0, 0.0, 1.0,  0.0, 0.0, 1.0,  0.0, 0.0, 1.0];
        $uvs      = [0.5, 0.5,  0.5, 0.5,  0.5, 0.5];
        $indices  = [0, 1, 2];

        $tangents = MeshData::computeTangents($vertices, $normals, $uvs, $indices);

        $this->assertCount(12, $tangents); // 3 verts × vec4
        foreach ($tangents as $v) {
            $this->assertFalse(is_nan($v), 'Tangent buffer must not contain NaN');
        }
    }

    public function testFlatOneSidedMeshesFaceOneWay(): void
    {
        $this->assertTrue(PlaneMesh::generate(2.0, 2.0, 4)->facesOneWay(), 'a subdivided plane');
        $quad = new MeshData([0, 0, 0, 1, 0, 0, 1, 1, 0, 0, 1, 0], array_fill(0, 12, 0.0), array_fill(0, 8, 0.0), [0, 1, 2, 0, 2, 3]);
        $this->assertTrue($quad->facesOneWay(), 'a two-triangle quad');
    }

    public function testClosedOrDoubleSidedMeshesDoNotFaceOneWay(): void
    {
        $this->assertFalse(BoxMesh::generate(1.0, 1.0, 0.04)->facesOneWay(), 'a thin box has a front and a back face');
        $this->assertFalse(SphereMesh::generate(1.0, 8, 6)->facesOneWay(), 'a sphere');
        $front = PlaneMesh::generate(1.0, 1.0, 1);
        $this->assertFalse(MeshData::merge($front, $front->mirrored(1))->facesOneWay(), 'a double-sided plane');
    }

    public function testDegenerateTrianglesAreIgnored(): void
    {
        $mesh = new MeshData(
            [0, 0, 0, 1, 0, 0, 1, 1, 0, 2, 2, 2],
            array_fill(0, 12, 0.0),
            array_fill(0, 8, 0.0),
            [3, 3, 3, 0, 1, 2, 0, 0, 1],
        );
        $this->assertTrue($mesh->facesOneWay());
    }
}
