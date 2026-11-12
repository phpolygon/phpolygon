<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\HairMesh;
use PHPolygon\Geometry\SphereMesh;
use PHPUnit\Framework\TestCase;

final class HairMeshTest extends TestCase
{
    public function testSameSeedSameMesh(): void
    {
        $scalp = SphereMesh::generate(0.3, 8, 12);
        $a = HairMesh::generate($scalp, 40, 0.2, 0.01, 0.3, 5, 0.6, 9);
        $b = HairMesh::generate($scalp, 40, 0.2, 0.01, 0.3, 5, 0.6, 9);
        self::assertSame($a->vertices, $b->vertices);
        self::assertNotSame($a->vertices, HairMesh::generate($scalp, 40, 0.2, 0.01, 0.3, 5, 0.6, 10)->vertices);
    }

    public function testDoesNotTouchTheGlobalRandomState(): void
    {
        $scalp = SphereMesh::generate(0.3, 8, 12);
        mt_srand(1234);
        $expected = [mt_rand(), mt_rand(), mt_rand()];
        mt_srand(1234);
        HairMesh::generate($scalp, 40, 0.2, 0.01, 0.3, 5, 0.6, 77);
        self::assertSame($expected, [mt_rand(), mt_rand(), mt_rand()]);
    }

    /** The sequence is pinned: the Randomizer port must reproduce what mt_srand produced. */
    public function testOutputMatchesTheOldGlobalGeneratorPinned(): void
    {
        $scalp = SphereMesh::generate(0.3, 16, 24);
        $h = HairMesh::generate($scalp, 300, 0.25, 0.006, 0.3, 6, 0.7, 5);
        self::assertSame('de01cf7404bf3c56040627a7010ce77a', md5(serialize([$h->vertices, $h->normals, $h->uvs, $h->indices])));
    }
}
