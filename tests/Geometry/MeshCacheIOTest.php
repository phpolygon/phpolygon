<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\CylinderMesh;
use PHPolygon\Geometry\MeshCacheIO;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Geometry\WedgeMesh;

class MeshCacheIOTest extends TestCase
{
    public function testRoundTripBox(): void
    {
        $original = BoxMesh::generate(2.0, 3.0, 1.5);
        $encoded = MeshCacheIO::encode($original, 'v1');
        $decoded = MeshCacheIO::decode($encoded);

        $this->assertNotNull($decoded);
        $this->assertSame(count($original->vertices), count($decoded->vertices));
        $this->assertSame(count($original->normals), count($decoded->normals));
        $this->assertSame(count($original->uvs), count($decoded->uvs));
        $this->assertSame($original->indices, $decoded->indices);
        $this->assertFloatsEqual($original->vertices, $decoded->vertices);
        $this->assertFloatsEqual($original->normals, $decoded->normals);
        $this->assertFloatsEqual($original->uvs, $decoded->uvs);
    }

    public function testRoundTripSphere(): void
    {
        $original = SphereMesh::generate(1.0, 12, 16);
        $decoded = MeshCacheIO::decode(MeshCacheIO::encode($original, '1'));

        $this->assertNotNull($decoded);
        $this->assertSame($original->vertexCount(), $decoded->vertexCount());
        $this->assertSame($original->triangleCount(), $decoded->triangleCount());
        $this->assertFloatsEqual($original->vertices, $decoded->vertices);
    }

    public function testRoundTripCylinder(): void
    {
        $original = CylinderMesh::generate(1.0, 2.0, 16);
        $decoded = MeshCacheIO::decode(MeshCacheIO::encode($original, '1'));

        $this->assertNotNull($decoded);
        $this->assertSame($original->vertexCount(), $decoded->vertexCount());
        $this->assertFloatsEqual($original->vertices, $decoded->vertices);
    }

    public function testRoundTripPlane(): void
    {
        $original = PlaneMesh::generate(10.0, 10.0);
        $decoded = MeshCacheIO::decode(MeshCacheIO::encode($original, '1'));

        $this->assertNotNull($decoded);
        $this->assertSame($original->vertexCount(), $decoded->vertexCount());
        $this->assertFloatsEqual($original->vertices, $decoded->vertices);
    }

    public function testRoundTripWedge(): void
    {
        $original = WedgeMesh::generate(0.5);
        $decoded = MeshCacheIO::decode(MeshCacheIO::encode($original, '1'));

        $this->assertNotNull($decoded);
        $this->assertSame($original->vertexCount(), $decoded->vertexCount());
        $this->assertFloatsEqual($original->vertices, $decoded->vertices);
    }

    public function testEmptyMesh(): void
    {
        $original = new MeshData([], [], [], []);
        $encoded = MeshCacheIO::encode($original, 'v1');
        $decoded = MeshCacheIO::decode($encoded);

        $this->assertNotNull($decoded);
        $this->assertSame([], $decoded->vertices);
        $this->assertSame([], $decoded->normals);
        $this->assertSame([], $decoded->uvs);
        $this->assertSame([], $decoded->indices);
    }

    public function testDecodeCorruptMagic(): void
    {
        $encoded = MeshCacheIO::encode(BoxMesh::generate(1.0, 1.0, 1.0), 'v1');
        $corrupted = 'XXXX' . substr($encoded, 4);

        $this->assertNull(MeshCacheIO::decode($corrupted));
    }

    public function testDecodeTruncatedHeader(): void
    {
        $this->assertNull(MeshCacheIO::decode('PHMC'));
        $this->assertNull(MeshCacheIO::decode(''));
    }

    public function testCompactRoundTripKeepsTangentsAndUses16BitIndices(): void
    {
        $original = SphereMesh::generate(1.0, 12, 16)->withComputedTangents();
        $encoded = MeshCacheIO::encodeCompact($original, 'src', 0);
        $decoded = MeshCacheIO::decode($encoded);

        $this->assertNotNull($decoded);
        $this->assertSame($original->indices, $decoded->indices);
        $this->assertFloatsEqual($original->vertices, $decoded->vertices);
        $this->assertFloatsEqual($original->normals, $decoded->normals);
        $this->assertFloatsEqual($original->uvs, $decoded->uvs);
        $this->assertNotNull($decoded->tangents);
        $this->assertFloatsEqual($original->tangents ?? [], $decoded->tangents);

        $flags = unpack('v', $encoded, 6);
        $this->assertNotFalse($flags);
        $this->assertSame(MeshCacheIO::FLAG_INDEX16, $flags[1]);
        // 32-byte header + float32 streams + 2 bytes per index
        $floats = count($original->vertices) + count($original->normals) + count($original->uvs) + count($original->tangents ?? []);
        $this->assertSame(32 + $floats * 4 + count($original->indices) * 2, strlen($encoded));
    }

    public function testCompactFallsBackTo32BitIndicesAbove65535(): void
    {
        $mesh = new MeshData([0.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0], [0, 70000, 2]);
        $encoded = MeshCacheIO::encodeCompact($mesh, 'src', 0);

        $flags = unpack('v', $encoded, 6);
        $this->assertNotFalse($flags);
        $this->assertIsInt($flags[1]);
        $this->assertSame(0, $flags[1] & MeshCacheIO::FLAG_INDEX16);
        $decoded = MeshCacheIO::decode($encoded);
        $this->assertNotNull($decoded);
        $this->assertSame([0, 70000, 2], $decoded->indices);
        $this->assertNull($decoded->tangents);
    }

    public function testCompactZlibIsSmallerAndRoundTrips(): void
    {
        $original = SphereMesh::generate(1.0, 24, 32);
        $raw = MeshCacheIO::encodeCompact($original, 'src', 0);
        $zlib = MeshCacheIO::encodeCompact($original, 'src', 6);

        $this->assertLessThan(strlen($raw), strlen($zlib));
        $decoded = MeshCacheIO::decode($zlib);
        $this->assertNotNull($decoded);
        $this->assertSame($original->indices, $decoded->indices);
        $this->assertFloatsEqual($original->vertices, $decoded->vertices);
        $this->assertSame(MeshCacheIO::versionHash('src'), MeshCacheIO::readVersionHash($zlib));
    }

    public function testCompactRejectsCorruptPayload(): void
    {
        $zlib = MeshCacheIO::encodeCompact(BoxMesh::generate(1.0, 1.0, 1.0), 'src', 6);
        $this->assertNull(MeshCacheIO::decode(substr($zlib, 0, 40)));

        $raw = MeshCacheIO::encodeCompact(BoxMesh::generate(1.0, 1.0, 1.0), 'src', 0);
        $this->assertNull(MeshCacheIO::decode(substr($raw, 0, 40)));
    }

    public function testDecodeTruncatedData(): void
    {
        $encoded = MeshCacheIO::encode(BoxMesh::generate(1.0, 1.0, 1.0), 'v1');
        $truncated = substr($encoded, 0, 40);

        $this->assertNull(MeshCacheIO::decode($truncated));
    }

    public function testVersionHash(): void
    {
        $hash1 = MeshCacheIO::versionHash('v1');
        $hash2 = MeshCacheIO::versionHash('v2');
        $hash1Again = MeshCacheIO::versionHash('v1');

        $this->assertSame($hash1, $hash1Again);
        $this->assertNotSame($hash1, $hash2);
    }

    public function testReadVersionHash(): void
    {
        $encoded = MeshCacheIO::encode(BoxMesh::generate(1.0, 1.0, 1.0), 'myversion');
        $expected = MeshCacheIO::versionHash('myversion');

        $this->assertSame($expected, MeshCacheIO::readVersionHash($encoded));
    }

    public function testReadVersionHashFromCorruptData(): void
    {
        $this->assertNull(MeshCacheIO::readVersionHash('short'));
        $this->assertNull(MeshCacheIO::readVersionHash('XXXXxxxxxxxx'));
    }

    /**
     * @param float[] $expected
     * @param float[] $actual
     */
    private function assertFloatsEqual(array $expected, array $actual, float $delta = 1e-6): void
    {
        $this->assertSame(count($expected), count($actual));
        foreach ($expected as $i => $val) {
            $this->assertEqualsWithDelta($val, $actual[$i], $delta, "Float mismatch at index {$i}");
        }
    }
}
