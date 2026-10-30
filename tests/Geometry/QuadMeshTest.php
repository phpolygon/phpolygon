<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\QuadMesh;

final class QuadMeshTest extends TestCase
{
    public function testUprightOneSidedFacingPlusZ(): void
    {
        $mesh = QuadMesh::generate(2.0, 1.0);

        self::assertTrue($mesh->facesOneWay());
        self::assertSame([0.0, 0.0, 1.0], array_slice($mesh->normals, 0, 3));

        // CCW seen from +Z: the cross product of the first triangle's edges is +Z.
        $v = $mesh->vertices;
        $i = $mesh->indices;
        $p = static fn(int $k): array => [$v[$i[$k] * 3], $v[$i[$k] * 3 + 1]];
        [$ax, $ay] = $p(0);
        [$bx, $by] = $p(1);
        [$cx, $cy] = $p(2);
        self::assertGreaterThan(0.0, ($bx - $ax) * ($cy - $ay) - ($by - $ay) * ($cx - $ax));
    }

    public function testUvsAreImageCoordinates(): void
    {
        $mesh = QuadMesh::generate(2.0, 1.0);
        for ($n = 0; $n < 4; $n++) {
            self::assertSame($mesh->vertices[$n * 3] > 0.0 ? 1.0 : 0.0, $mesh->uvs[$n * 2]);
            self::assertSame($mesh->vertices[$n * 3 + 1] > 0.0 ? 0.0 : 1.0, $mesh->uvs[$n * 2 + 1], 'top edge at v = 0');
        }
    }

    public function testUnitQuadIsRegisteredOnce(): void
    {
        MeshRegistry::clear();
        self::assertSame(QuadMesh::UNIT_ID, QuadMesh::unit());
        $first = MeshRegistry::get(QuadMesh::UNIT_ID);
        QuadMesh::unit();
        self::assertSame($first, MeshRegistry::get(QuadMesh::UNIT_ID));
        MeshRegistry::clear();
    }
}
