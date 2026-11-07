<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\DecalMeshBuilder;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Math\Mat4;

/**
 * Mesh decals: the target's triangles inside the decal box, clipped to it, lifted off the
 * surface, UVs over the box; faces turned away from the projection get none.
 */
final class DecalMeshBuilderTest extends TestCase
{
    private static function quad(): MeshData
    {
        // A 4 x 4 m floor at y = 0, two triangles, facing +Y.
        return new MeshData(
            [-2.0, 0.0, -2.0, 2.0, 0.0, -2.0, 2.0, 0.0, 2.0, -2.0, 0.0, 2.0],
            [0.0, 1.0, 0.0, 0.0, 1.0, 0.0, 0.0, 1.0, 0.0, 0.0, 1.0, 0.0],
            [0.0, 0.0, 1.0, 0.0, 1.0, 1.0, 0.0, 1.0],
            [0, 2, 1, 0, 3, 2],
        );
    }

    /** Area of all triangles projected onto XZ. */
    private static function area(MeshData $m): float
    {
        $a = 0.0;
        for ($t = 0; $t + 2 < count($m->indices); $t += 3) {
            [$i, $j, $k] = [$m->indices[$t] * 3, $m->indices[$t + 1] * 3, $m->indices[$t + 2] * 3];
            $a += abs(($m->vertices[$j] - $m->vertices[$i]) * ($m->vertices[$k + 2] - $m->vertices[$i + 2])
                - ($m->vertices[$k] - $m->vertices[$i]) * ($m->vertices[$j + 2] - $m->vertices[$i + 2])) / 2.0;
        }
        return $a;
    }

    public function testTheDecalIsTheFloorInsideItsBox(): void
    {
        // Box: centre (0.5, 0, 0.5), half extents 1 x 0.5 x 0.5.
        $decal = Mat4::translation(0.5, 0.0, 0.5)->multiply(Mat4::scaling(1.0, 0.5, 0.5));
        $m = DecalMeshBuilder::build(self::quad(), Mat4::identity(), $decal);
        $this->assertEqualsWithDelta(2.0, self::area($m), 1e-6, '2 x 1 m of floor');
        for ($i = 0; $i < count($m->vertices); $i += 3) {
            $this->assertGreaterThanOrEqual(-0.5 - 1e-9, $m->vertices[$i]);
            $this->assertLessThanOrEqual(1.5 + 1e-9, $m->vertices[$i]);
            $this->assertEqualsWithDelta(0.005, $m->vertices[$i + 1], 1e-9, 'lifted off the floor');
        }
        foreach ($m->uvs as $uv) {
            $this->assertGreaterThanOrEqual(-1e-9, $uv);
            $this->assertLessThanOrEqual(1.0 + 1e-9, $uv);
        }
        $this->assertEqualsWithDelta(0.0, min($m->uvs), 1e-9);
        $this->assertEqualsWithDelta(1.0, max($m->uvs), 1e-9);
    }

    public function testFacesTurnedAwayGetNoDecal(): void
    {
        // A box: of its six faces only the top lies within 80 degrees of +Y.
        $box = BoxMesh::generate(1.0, 1.0, 1.0);
        $decal = Mat4::scaling(2.0, 2.0, 2.0);
        $m = DecalMeshBuilder::build($box, Mat4::identity(), $decal);
        $this->assertEqualsWithDelta(1.0, self::area($m), 1e-6, 'only the 1 x 1 top');
        for ($i = 1; $i < count($m->normals); $i += 3) {
            $this->assertGreaterThan(0.99, $m->normals[$i]);
        }
    }

    public function testATurnedBoxProjectsOntoAWall(): void
    {
        // The wall faces +Z; a box turned so its +Y points to +Z paints on it.
        $wall = (self::quad())->transformed(Mat4::rotationX(M_PI / 2.0));
        $decal = Mat4::rotationX(M_PI / 2.0)->multiply(Mat4::scaling(0.5, 0.5, 0.5));
        $m = DecalMeshBuilder::build($wall, Mat4::identity(), $decal);
        $this->assertGreaterThan(0, count($m->indices));
        for ($i = 2; $i < count($m->normals); $i += 3) {
            $this->assertGreaterThan(0.99, $m->normals[$i], 'normals of the wall, +Z');
        }
    }

    public function testATargetOutsideTheBoxGivesNothing(): void
    {
        $m = DecalMeshBuilder::build(self::quad(), Mat4::translation(10.0, 0.0, 0.0), Mat4::scaling(1.0, 1.0, 1.0));
        $this->assertSame([], $m->indices);
    }
}
