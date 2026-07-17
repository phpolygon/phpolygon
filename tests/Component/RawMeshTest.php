<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Component;

use PHPolygon\Component\RawMesh;
use PHPolygon\ECS\Serializer\AttributeSerializer;
use PHPUnit\Framework\TestCase;

class RawMeshTest extends TestCase
{
    public function testToMeshData(): void
    {
        $raw = new RawMesh();
        $raw->vertices = [0, 0, 0, 1, 0, 0, 0, 1, 0];
        $raw->normals = [0, 0, 1, 0, 0, 1, 0, 0, 1];
        $raw->uvs = [0, 0, 1, 0, 0, 1];
        $raw->indices = [0, 1, 2];

        $mesh = $raw->toMeshData();

        $this->assertSame(3, $mesh->vertexCount());
        $this->assertSame(1, $mesh->triangleCount());
    }

    public function testSerializesThroughTheComponentPipeline(): void
    {
        $raw = new RawMesh();
        $raw->vertices = [0, 0, 0, 1, 0, 0, 0, 1, 0];
        $raw->indices = [0, 1, 2];
        $raw->meshId = 'edited_mesh';

        $serializer = new AttributeSerializer();
        $restored = $serializer->fromArray($serializer->toArray($raw), RawMesh::class);

        $this->assertInstanceOf(RawMesh::class, $restored);
        $this->assertSame('edited_mesh', $restored->meshId);
        // Loose equality: integral values round-trip as int, which is fine for
        // the numeric geometry arrays.
        $this->assertEquals([0, 0, 0, 1, 0, 0, 0, 1, 0], $restored->vertices);
        $this->assertSame([0, 1, 2], $restored->indices);
    }
}
