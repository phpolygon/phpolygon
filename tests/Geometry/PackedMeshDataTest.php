<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\PackedMeshData;
use PHPolygon\Geometry\SphereMesh;

class PackedMeshDataTest extends TestCase
{
    /** A mesh whose floats are float32 values, so the round trip must be bit exact. */
    private static function f32Mesh(MeshData $m, bool $tangents = false): MeshData
    {
        return PackedMeshData::fromMeshData($tangents ? $m->withComputedTangents() : $m)->toMeshData();
    }

    public function testMeshDataRoundTripIsBitExactForFloat32Input(): void
    {
        $mesh = self::f32Mesh(SphereMesh::generate(1.3, 12, 16), true);
        $packed = PackedMeshData::fromMeshData($mesh);
        $back = $packed->toMeshData();

        $this->assertSame($mesh->vertices, $back->vertices);
        $this->assertSame($mesh->normals, $back->normals);
        $this->assertSame($mesh->uvs, $back->uvs);
        $this->assertSame($mesh->indices, $back->indices);
        $this->assertSame($mesh->tangents, $back->tangents);
    }

    public function testDoublesAreRoundedToFloat32OnceThenStable(): void
    {
        $mesh = new MeshData([0.1, 0.2, 0.3, 1.0, 2.0, 3.0, 0.5, 0.25, 0.125], [0, 1, 0, 0, 1, 0, 0, 1, 0], [0.1, 0.1, 0.2, 0.2, 0.3, 0.3], [0, 1, 2]);
        $once = PackedMeshData::fromMeshData($mesh)->toMeshData();
        $this->assertNotSame($mesh->vertices, $once->vertices, '0.1 is not a float32 value');
        $this->assertEqualsWithDelta($mesh->vertices, $once->vertices, 1e-6);

        $twice = PackedMeshData::fromMeshData($once)->toMeshData();
        $this->assertSame($once->vertices, $twice->vertices);
        $this->assertSame($once->uvs, $twice->uvs);
    }

    public function testContainerToStringToContainerIsByteIdentical(): void
    {
        $mesh = self::f32Mesh(BoxMesh::generate(2.0, 3.0, 1.5), true);
        $colors = [];
        for ($i = 0; $i < $mesh->vertexCount(); $i++) {
            array_push($colors, 0.25, 0.5, 0.75, 1.0);
        }
        $a = PackedMeshData::fromMeshData($mesh, $colors);
        $bytes = $a->pack();
        $b = PackedMeshData::unpack($bytes);

        $this->assertSame($bytes, $b->pack());
        $this->assertSame($a->vertexCount, $b->vertexCount);
        $this->assertSame($a->bounds, $b->bounds);
        $this->assertSame($colors, $b->colorArray());
        $this->assertSame($mesh->tangents, $b->toMeshData()->tangents);
        $this->assertSame($a->byteSize(), strlen($bytes));
    }

    public function testBoundsMatchMeshDataBoundsOfTheRoundedPositions(): void
    {
        $mesh = self::f32Mesh(SphereMesh::generate(2.0, 8, 8));
        $packed = PackedMeshData::fromMeshData($mesh);
        $expected = $mesh->bounds();
        $actual = $packed->bounds();

        $this->assertSame($expected['min']->x, $actual['min']->x);
        $this->assertSame($expected['min']->y, $actual['min']->y);
        $this->assertSame($expected['max']->z, $actual['max']->z);
    }

    public function testOptionalStreamsAreOptional(): void
    {
        $mesh = new MeshData([0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 1.0, 0.0], [], [], [0, 1, 2]);
        $packed = PackedMeshData::unpack(PackedMeshData::fromMeshData($mesh)->pack());
        $back = $packed->toMeshData();

        $this->assertSame([], $back->normals);
        $this->assertSame([], $back->uvs);
        $this->assertNull($back->tangents);
        $this->assertNull($packed->colorArray());
        $this->assertSame(1, $packed->triangleCount());
        $this->assertSame(64 + 36 + 6, $packed->byteSize());
    }

    public function testEmptyMesh(): void
    {
        $packed = PackedMeshData::unpack(PackedMeshData::fromMeshData(new MeshData([], [], [], []))->pack());

        $this->assertSame(0, $packed->vertexCount);
        $this->assertSame(PackedMeshData::HEADER_SIZE, $packed->byteSize());
        $this->assertSame([], $packed->toMeshData()->vertices);
    }

    public function testIndicesAreU16UpTo65536VerticesThenU32(): void
    {
        $small = PackedMeshData::fromArrays(array_fill(0, 65536 * 3, 1.0), [], [], [0, 65535, 1]);
        $this->assertFalse($small->index32);
        $this->assertSame(6, strlen($small->gpuIndexBytes()));
        $this->assertSame([0, 65535, 1], $small->toMeshData()->indices);

        $big = PackedMeshData::fromArrays(array_fill(0, 65537 * 3, 1.0), [], [], [0, 65536, 1]);
        $this->assertTrue($big->index32);
        $this->assertSame(12, strlen($big->gpuIndexBytes()));
        $this->assertSame([0, 65536, 1], PackedMeshData::unpack($big->pack())->toMeshData()->indices);
    }

    public function testGpuVertexBytesInterleavePositionNormalUv(): void
    {
        $mesh = self::f32Mesh(BoxMesh::generate(1.0, 2.0, 3.0));
        $packed = PackedMeshData::fromMeshData($mesh);
        $bytes = $packed->gpuVertexBytes();

        $this->assertSame($mesh->vertexCount() * 32, strlen($bytes));
        $f = array_values(unpack('g*', $bytes));
        for ($i = 0; $i < $mesh->vertexCount(); $i++) {
            $this->assertSame([$mesh->vertices[$i * 3], $mesh->vertices[$i * 3 + 1], $mesh->vertices[$i * 3 + 2]], array_slice($f, $i * 8, 3));
            $this->assertSame([$mesh->normals[$i * 3], $mesh->normals[$i * 3 + 1], $mesh->normals[$i * 3 + 2]], array_slice($f, $i * 8 + 3, 3));
            $this->assertSame([$mesh->uvs[$i * 2], $mesh->uvs[$i * 2 + 1]], array_slice($f, $i * 8 + 6, 2));
        }
    }

    public function testGpuVertexBytesSpanSeveralChunks(): void
    {
        $n = 5000;
        $pos = [];
        for ($i = 0; $i < $n; $i++) {
            array_push($pos, (float) $i, 0.5, -1.0);
        }
        $bytes = PackedMeshData::fromArrays($pos, [], [], [])->gpuVertexBytes();
        $f = unpack('g1x/g1y/g1z', substr($bytes, 4999 * 32, 12));

        $this->assertSame(4999.0, $f['x']);
        $this->assertSame($n * 32, strlen($bytes));
    }

    public function testMalformedInputIsRejected(): void
    {
        $good = PackedMeshData::fromMeshData(BoxMesh::generate(1.0, 1.0, 1.0))->pack();

        foreach ([
            'short' => 'PPK1',
            'magic' => 'XXXX' . substr($good, 4),
            'truncated' => substr($good, 0, -1),
            'padded' => $good . "\0",
        ] as $label => $bytes) {
            try {
                PackedMeshData::unpack($bytes);
                $this->fail("{$label}: expected InvalidArgumentException");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testInconsistentArraysAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PackedMeshData::fromArrays([0.0, 0.0, 0.0], [0.0], [], []);
    }

    public function testIndexOutOfRangeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PackedMeshData::fromArrays([0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 1.0, 0.0], [], [], [0, 1, 3]);
    }

    public function testPackedStringCrossesAThreadBoundaryUnchanged(): void
    {
        if (!\PHPolygon\Thread\ParallelCapability::isAvailable()) {
            $this->markTestSkipped('ext-parallel (ZTS) not available');
        }
        $mesh = self::f32Mesh(SphereMesh::generate(1.0, 16, 16), true);
        $bytes = PackedMeshData::fromMeshData($mesh)->pack();

        $pool = new \PHPolygon\Thread\RuntimePool(1);
        try {
            // The worker unpacks, re-packs and returns the string: any layout drift shows.
            $out = $pool->map([$bytes, $bytes], static fn(string $b): string => \PHPolygon\Geometry\PackedMeshData::unpack($b)->pack());
        } finally {
            $pool->close();
        }

        $this->assertSame([$bytes, $bytes], $out);
    }
}
