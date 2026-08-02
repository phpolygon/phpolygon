<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Terrain;

use InvalidArgumentException;
use PHPolygon\Terrain\HeightmapData;
use PHPolygon\Terrain\TerrainMeshBuilder;
use PHPUnit\Framework\TestCase;

class TerrainMeshBuilderTest extends TestCase
{
    public function testSingleMeshHasOneVertexPerGridSample(): void
    {
        $mesh = (new TerrainMeshBuilder())->buildSingle(HeightmapData::flat(5, 4, 10.0, 10.0));

        $this->assertSame(20, $mesh->vertexCount());
        // 4 quads across × 3 down × 2 triangles.
        $this->assertSame(24, $mesh->triangleCount());
        $this->assertSame(40, count($mesh->uvs));
    }

    public function testVertexPositionsFollowTheHeightmap(): void
    {
        $map = new HeightmapData(2, 2, 10.0, 10.0, 0.0, 100.0, [0.0, 0.25, 0.5, 1.0]);

        $mesh = (new TerrainMeshBuilder())->buildSingle($map);

        // Vertex 0 is grid (0,0): (-5, 0, -5). Vertex 3 is grid (1,1): (5, 100, 5).
        $this->assertSame([-5.0, 0.0, -5.0], array_slice($mesh->vertices, 0, 3));
        $this->assertSame([5.0, 100.0, 5.0], array_slice($mesh->vertices, 9, 3));
    }

    public function testUvsSpanZeroToOneAcrossTheWholeTerrain(): void
    {
        $mesh = (new TerrainMeshBuilder())->buildSingle(HeightmapData::flat(3, 3, 10.0, 10.0));

        $this->assertSame([0.0, 0.0], array_slice($mesh->uvs, 0, 2));
        $this->assertSame([1.0, 1.0], array_slice($mesh->uvs, -2));
    }

    public function testRejectsARegionWithoutQuads(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TerrainMeshBuilder())->buildRegion(HeightmapData::flat(5, 5), 2, 2, 2, 4);
    }

    public function testChunkGridCoversTheTerrainExactly(): void
    {
        $chunks = (new TerrainMeshBuilder())->buildChunks(HeightmapData::flat(9, 9, 16.0, 16.0), 4);

        $this->assertCount(4, $chunks, '8 quads / chunk size 4 = 2x2 chunks');
        $this->assertSame([0, 0], [$chunks[0]->chunkX, $chunks[0]->chunkZ]);
        $this->assertSame([1, 1], [$chunks[3]->chunkX, $chunks[3]->chunkZ]);

        foreach ($chunks as $chunk) {
            $this->assertSame(25, $chunk->mesh->vertexCount(), '4x4 quads = 5x5 vertices');
        }
    }

    public function testUnevenChunkSizeLeavesASmallerLastChunk(): void
    {
        // 6 quads with chunk size 4 → chunks of 4 and 2 quads per axis.
        $chunks = (new TerrainMeshBuilder())->buildChunks(HeightmapData::flat(7, 7, 12.0, 12.0), 4);

        $this->assertCount(4, $chunks);
        $this->assertSame(25, $chunks[0]->mesh->vertexCount(), '4x4 quads');
        $this->assertSame(9, $chunks[3]->mesh->vertexCount(), '2x2 quads');
    }

    public function testAdjacentChunksShareTheirBorderVerticesExactly(): void
    {
        $map = HeightmapData::fromFunction(
            static fn (float $x, float $z): float => sin($x * 0.3) * 4.0 + cos($z * 0.2) * 3.0,
            gridWidth: 9,
            gridDepth: 9,
            sizeX: 16.0,
            sizeZ: 16.0,
            minHeight: -10.0,
            maxHeight: 10.0,
        );

        $chunks = (new TerrainMeshBuilder())->buildChunks($map, 4);
        $left = $chunks[0];   // chunk (0,0)
        $right = $chunks[1];  // chunk (1,0)

        // A crack would show up as the shared grid column x=4 having different
        // positions or normals depending on which chunk built it.
        for ($row = 0; $row < 5; $row++) {
            $leftEdge = $this->vertexAt($left->mesh->vertices, $row * 5 + 4);
            $rightEdge = $this->vertexAt($right->mesh->vertices, $row * 5 + 0);
            $this->assertSame($leftEdge, $rightEdge, "position mismatch on shared edge at row {$row}");

            $leftNormal = $this->vertexAt($left->mesh->normals, $row * 5 + 4);
            $rightNormal = $this->vertexAt($right->mesh->normals, $row * 5 + 0);
            $this->assertSame($leftNormal, $rightNormal, "normal mismatch on shared edge at row {$row}");
        }
    }

    public function testChunkVerticesStayInTerrainSpace(): void
    {
        // Chunks are rendered with the terrain's transform, so the second chunk
        // must start where the first ended rather than at its own origin.
        $chunks = (new TerrainMeshBuilder())->buildChunks(HeightmapData::flat(9, 9, 16.0, 16.0), 4);

        $this->assertSame(-16.0 * 0.5, $chunks[0]->mesh->vertices[0]);
        $this->assertSame(0.0, $chunks[1]->mesh->vertices[0], 'chunk (1,0) starts at the terrain midpoint');
    }

    public function testChunkMeshIdsAreStableAndUnique(): void
    {
        $chunks = (new TerrainMeshBuilder())->buildChunks(HeightmapData::flat(9, 9), 4);

        $ids = array_map(static fn ($chunk): string => $chunk->meshId('island'), $chunks);

        $this->assertSame(['island_c0_0', 'island_c1_0', 'island_c0_1', 'island_c1_1'], $ids);
        $this->assertSame($ids, array_unique($ids));
    }

    public function testTrianglesFaceUpwards(): void
    {
        $mesh = (new TerrainMeshBuilder())->buildSingle(HeightmapData::flat(2, 2, 10.0, 10.0));

        $a = $this->vertexAt($mesh->vertices, $mesh->indices[0]);
        $b = $this->vertexAt($mesh->vertices, $mesh->indices[1]);
        $c = $this->vertexAt($mesh->vertices, $mesh->indices[2]);

        // Cross product of the triangle edges must point along +Y for a
        // counter-clockwise, upward-facing surface.
        $ab = [$b[0] - $a[0], $b[1] - $a[1], $b[2] - $a[2]];
        $ac = [$c[0] - $a[0], $c[1] - $a[1], $c[2] - $a[2]];
        $crossY = $ab[2] * $ac[0] - $ab[0] * $ac[2];

        $this->assertGreaterThan(0.0, $crossY, 'terrain triangles must wind counter-clockwise seen from above');
    }

    /**
     * @param  list<float>  $buffer
     * @return array{float, float, float}
     */
    private function vertexAt(array $buffer, int $index): array
    {
        return [$buffer[$index * 3], $buffer[$index * 3 + 1], $buffer[$index * 3 + 2]];
    }
}
