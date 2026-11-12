<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\HairCardMesh;
use PHPolygon\Geometry\HairGuide;
use PHPolygon\Geometry\HairGuides;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\SphereMesh;
use PHPUnit\Framework\TestCase;

final class HairCardMeshTest extends TestCase
{
    /** @return list<HairGuide> */
    private function guides(int $count = 24, int $seed = 3): array
    {
        return HairGuides::grow(SphereMesh::generate(0.3, 12, 16), $count, 0.25, 5, 0.6, 0.6, HairGuides::partingFlow([0.0, 0.0, 0.0], [0.0, 0.0, 1.0]), 0.2, 0.0, 0.0, $seed);
    }

    public function testGuidesAreDeterministicAndLeaveTheGlobalRandomStateAlone(): void
    {
        mt_srand(42);
        $expected = [mt_rand(), mt_rand()];
        mt_srand(42);
        $a = $this->guides();
        self::assertSame($expected, [mt_rand(), mt_rand()]);
        self::assertEquals($a, $this->guides());
        self::assertNotEquals($a, $this->guides(24, 4));
        self::assertCount(24, $a);
        self::assertCount(5, $a[0]->points);
    }

    public function testCardsHaveRootToTipUvAndUnitNormals(): void
    {
        $mesh = HairCardMesh::generate($this->guides(), 0.02);
        self::assertSame(24 * 5 * 2, $mesh->vertexCount());
        self::assertSame(24 * 4 * 2, $mesh->triangleCount());
        self::assertNotNull($mesh->tangents);
        self::assertCount($mesh->vertexCount() * 4, $mesh->tangents);
        // v runs 0 at the root to 1 at the tip on every card, u spans 0..1.
        for ($c = 0; $c < 24; $c++) {
            $root = $c * 10;
            self::assertEqualsWithDelta(0.0, $mesh->uvs[$root * 2 + 1], 1e-9);
            self::assertEqualsWithDelta(1.0, $mesh->uvs[($root + 9) * 2 + 1], 1e-9);
            self::assertEqualsWithDelta(0.0, $mesh->uvs[$root * 2], 1e-9);
            self::assertEqualsWithDelta(1.0, $mesh->uvs[($root + 1) * 2], 1e-9);
        }
        for ($i = 0; $i < $mesh->vertexCount(); $i++) {
            $l = sqrt($mesh->normals[$i * 3] ** 2 + $mesh->normals[$i * 3 + 1] ** 2 + $mesh->normals[$i * 3 + 2] ** 2);
            self::assertEqualsWithDelta(1.0, $l, 1e-6);
            self::assertTrue(is_finite($mesh->vertices[$i * 3]) && is_finite($mesh->vertices[$i * 3 + 1]));
        }
    }

    public function testCardNarrowsTowardTheTipAndFaceNormalMatchesWinding(): void
    {
        $g = [new HairGuide([[0.0, 0.0, 0.0], [0.0, -0.1, 0.0], [0.0, -0.2, 0.0]], [0.0, 0.0, 1.0])];
        $m = HairCardMesh::generate($g, 0.1, 0, 0.2);
        $w = static fn (int $k): float => abs($m->vertices[($k * 2 + 1) * 3] - $m->vertices[($k * 2) * 3]);
        self::assertEqualsWithDelta(0.1, $w(0), 1e-9);
        self::assertEqualsWithDelta(0.02, $w(2), 1e-9);
        // Geometric face normal of the first triangle agrees with the stored normal.
        [$i0, $i1, $i2] = [$m->indices[0], $m->indices[1], $m->indices[2]];
        $p = static fn (int $i): array => [$m->vertices[$i * 3], $m->vertices[$i * 3 + 1], $m->vertices[$i * 3 + 2]];
        [$a, $b, $c] = [$p($i0), $p($i1), $p($i2)];
        $e1 = [$b[0] - $a[0], $b[1] - $a[1], $b[2] - $a[2]];
        $e2 = [$c[0] - $a[0], $c[1] - $a[1], $c[2] - $a[2]];
        $face = [$e1[1] * $e2[2] - $e1[2] * $e2[1], $e1[2] * $e2[0] - $e1[0] * $e2[2], $e1[0] * $e2[1] - $e1[1] * $e2[0]];
        $dot = $face[0] * $m->normals[0] + $face[1] * $m->normals[1] + $face[2] * $m->normals[2];
        self::assertGreaterThan(0.0, $dot);
        self::assertEqualsWithDelta(0.0, $m->normals[0], 1e-9);
        self::assertEqualsWithDelta(1.0, $m->normals[2], 1e-9);
    }

    public function testSegmentsResampleAndAtlasColumns(): void
    {
        $m = HairCardMesh::generate($this->guides(8), 0.02, 3, 0.25, 1.0, 0.5, 4);
        self::assertSame(8 * 4 * 2, $m->vertexCount());
        self::assertSame(8 * 3 * 2, $m->triangleCount());
        // Card 5 sits in atlas column 1: u spans 0.25..0.5.
        $v = 5 * 8;
        self::assertEqualsWithDelta(0.25, $m->uvs[$v * 2], 1e-9);
        self::assertEqualsWithDelta(0.5, $m->uvs[($v + 1) * 2], 1e-9);
    }

    public function testLodReducesCardsAndWidensTheRest(): void
    {
        $guides = $this->guides(64);
        $lod0 = HairCardMesh::generateLod($guides, 0.02, 0);
        $lod2 = HairCardMesh::generateLod($guides, 0.02, 2);
        self::assertEquals(HairCardMesh::generate($guides, 0.02), $lod0);
        self::assertLessThan($lod0->triangleCount() / 4, $lod2->triangleCount() + 1);
        self::assertSame(16 * 1 * 2, $lod2->triangleCount());
        $root = static fn (MeshData $m): float => sqrt(($m->vertices[3] - $m->vertices[0]) ** 2 + ($m->vertices[4] - $m->vertices[1]) ** 2 + ($m->vertices[5] - $m->vertices[2]) ** 2);
        self::assertEqualsWithDelta(0.02, $root($lod0), 1e-9);
        self::assertEqualsWithDelta(0.04, $root($lod2), 1e-9);
    }

    public function testDegenerateInputsGiveAnEmptyMesh(): void
    {
        self::assertSame(0, HairCardMesh::generate([], 0.02)->vertexCount());
        self::assertSame(0, HairCardMesh::generate([new HairGuide([[0.0, 0.0, 0.0]])], 0.02)->vertexCount());
        self::assertSame([], HairGuides::grow(new \PHPolygon\Geometry\MeshData([], [], [], []), 5, 0.2));
        // A guide running parallel to its root normal still gets a valid frame.
        $m = HairCardMesh::generate([new HairGuide([[0.0, 0.0, 0.0], [0.0, 0.1, 0.0]], [0.0, 1.0, 0.0])], 0.02);
        foreach ($m->normals as $n) {
            self::assertTrue(is_finite($n));
        }
    }
}
