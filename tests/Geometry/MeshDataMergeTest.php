<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshData;

final class MeshDataMergeTest extends TestCase
{
    public function testMergeEmptyReturnsEmptyMesh(): void
    {
        $merged = MeshData::merge();
        $this->assertSame(0, $merged->vertexCount());
        $this->assertSame(0, $merged->triangleCount());
    }

    public function testMergeSingleReturnsInputUnchanged(): void
    {
        $box = BoxMesh::generate(1.0, 1.0, 1.0);
        $merged = MeshData::merge($box);
        $this->assertSame($box, $merged);
    }

    public function testMergeTwoBoxesPreservesCounts(): void
    {
        $a = BoxMesh::generate(1.0, 1.0, 1.0);
        $b = BoxMesh::generate(2.0, 1.0, 1.0);
        $merged = MeshData::merge($a, $b);
        $this->assertSame($a->vertexCount() + $b->vertexCount(), $merged->vertexCount());
        $this->assertSame($a->triangleCount() + $b->triangleCount(), $merged->triangleCount());
    }

    public function testMergeOffsetsIndices(): void
    {
        $a = BoxMesh::generate(1.0, 1.0, 1.0);
        $b = BoxMesh::generate(1.0, 1.0, 1.0);
        $merged = MeshData::merge($a, $b);

        // Last block of indices should reference vertices owned by $b,
        // i.e. all >= $a->vertexCount().
        $bIndexStart = count($a->indices);
        for ($i = $bIndexStart; $i < count($merged->indices); $i++) {
            $this->assertGreaterThanOrEqual($a->vertexCount(), $merged->indices[$i]);
        }
    }

    public function testMergeDropsTangentsWhenNotAllPresent(): void
    {
        $with    = BoxMesh::generate(1.0, 1.0, 1.0)->withComputedTangents();
        $without = BoxMesh::generate(1.0, 1.0, 1.0);
        $merged  = MeshData::merge($with, $without);
        $this->assertNull($merged->tangents);
    }

    public function testMergeKeepsTangentsWhenAllPresent(): void
    {
        $a = BoxMesh::generate(1.0, 1.0, 1.0)->withComputedTangents();
        $b = BoxMesh::generate(1.0, 1.0, 1.0)->withComputedTangents();
        $merged = MeshData::merge($a, $b);
        $this->assertNotNull($merged->tangents);
        $this->assertSame($merged->vertexCount() * 4, count($merged->tangents));
    }
}
