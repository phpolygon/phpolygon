<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\EllipseLoftMesh;
use PHPolygon\Geometry\LoftRing;
use PHPUnit\Framework\TestCase;

final class MeshDataMirroredTest extends TestCase
{
    public function testMirroringReflectsPositionsNormalsAndReversesWinding(): void
    {
        $mesh = EllipseLoftMesh::generate([new LoftRing(0.0, 0.4, 0.15, 0.2, 0.2), new LoftRing(1.0, 0.3, 0.15, 0.2, 0.2)], 8, 0.1, 0.1);
        $mirror = $mesh->mirrored(0);

        self::assertSame($mesh->vertexCount(), $mirror->vertexCount());
        for ($i = 0, $n = count($mesh->vertices); $i < $n; $i += 3) {
            self::assertSame(-$mesh->vertices[$i], $mirror->vertices[$i]);
            self::assertSame($mesh->vertices[$i + 1], $mirror->vertices[$i + 1]);
            self::assertSame(-$mesh->normals[$i], $mirror->normals[$i]);
            self::assertSame($mesh->normals[$i + 2], $mirror->normals[$i + 2]);
        }
        for ($t = 0, $n = count($mesh->indices); $t < $n; $t += 3) {
            self::assertSame($mesh->indices[$t], $mirror->indices[$t]);
            self::assertSame($mesh->indices[$t + 1], $mirror->indices[$t + 2]);
            self::assertSame($mesh->indices[$t + 2], $mirror->indices[$t + 1]);
        }

        // The wide side swapped over: +0.4 on the original, -0.4 mirrored.
        $low = INF;
        $high = -INF;
        for ($i = 0, $n = count($mirror->vertices); $i < $n; $i += 3) {
            $low = min($low, $mirror->vertices[$i]);
            $high = max($high, $mirror->vertices[$i]);
        }
        self::assertEqualsWithDelta(-0.4, $low, 1e-9);
        self::assertEqualsWithDelta(0.15, $high, 1e-9);
    }

    public function testMirroringOnYAndZ(): void
    {
        $mesh = EllipseLoftMesh::generate([LoftRing::ellipse(0.0, 0.1, 0.2, 0.0, 0.3)], 6, 0.05, 0.05);

        self::assertSame(-$mesh->vertices[1], $mesh->mirrored(1)->vertices[1]);
        self::assertSame(-$mesh->vertices[2], $mesh->mirrored(2)->vertices[2]);
    }
}
