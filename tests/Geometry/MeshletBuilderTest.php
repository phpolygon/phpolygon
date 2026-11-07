<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\MeshletBuilder;
use PHPolygon\Geometry\MeshletData;
use PHPolygon\Geometry\SphereMesh;

final class MeshletBuilderTest extends TestCase
{
    public function testEveryTriangleLandsInExactlyOneMeshletWithinTheLimits(): void
    {
        $mesh = SphereMesh::generate(1.0, 32, 24);
        $m = MeshletBuilder::build($mesh);

        $want = [];
        for ($t = 0; $t < intdiv(count($mesh->indices), 3); $t++) {
            $want[] = [$mesh->indices[$t * 3], $mesh->indices[$t * 3 + 1], $mesh->indices[$t * 3 + 2]];
        }
        $got = [];
        for ($i = 0; $i < $m->count; $i++) {
            $info = $m->meshlet($i);
            self::assertLessThanOrEqual(MeshletBuilder::MAX_VERTICES, $info['vertexCount']);
            self::assertLessThanOrEqual(MeshletBuilder::MAX_TRIANGLES, $info['triangleCount']);
            self::assertGreaterThan(0, $info['triangleCount']);
            array_push($got, ...$m->triangles($i));
        }
        sort($want);
        sort($got);
        self::assertSame($want, $got, 'the meshlets keep every triangle exactly once, winding intact');
        self::assertGreaterThan(1, $m->count);
        self::assertSame(count($mesh->vertices) / 3 * MeshletData::VERTEX_FLOATS * 4, strlen($m->vertices));
    }

    public function testTheBoundingSphereHoldsEveryVertexOfItsMeshlet(): void
    {
        $mesh = SphereMesh::generate(2.0, 24, 16);
        $m = MeshletBuilder::build($mesh);
        for ($i = 0; $i < $m->count; $i++) {
            $info = $m->meshlet($i);
            foreach ($m->triangles($i) as $tri) {
                foreach ($tri as $v) {
                    $d = sqrt(
                        ($mesh->vertices[$v * 3] - $info['center'][0]) ** 2
                        + ($mesh->vertices[$v * 3 + 1] - $info['center'][1]) ** 2
                        + ($mesh->vertices[$v * 3 + 2] - $info['center'][2]) ** 2,
                    );
                    self::assertLessThanOrEqual($info['radius'] + 1e-4, $d);
                }
            }
        }
    }

    public function testMeshletsArePatchesNotRings(): void
    {
        // A sphere is generated ring by ring; in index order every meshlet would wrap
        // around it (radius ~ the sphere's). The Z-curve walk gives compact patches.
        $mesh = SphereMesh::generate(2.0, 64, 48);
        $m = MeshletBuilder::build($mesh);
        $sum = 0.0;
        for ($i = 0; $i < $m->count; $i++) {
            $sum += $m->meshlet($i)['radius'];
        }
        self::assertLessThan(0.6 * 2.0, $sum / $m->count);
    }

    public function testSmallLimitsSplitIntoMoreMeshlets(): void
    {
        $mesh = SphereMesh::generate(1.0, 16, 12);
        self::assertGreaterThan(MeshletBuilder::build($mesh)->count, MeshletBuilder::build($mesh, 16, 16)->count);
    }
}
