<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Fieldtracing;

use PHPolygon\Fieldtracing\Bake\MeshSdfBaker;
use PHPolygon\Fieldtracing\Sdf\BoxSdf;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Math\Vec3;
use PHPUnit\Framework\TestCase;

class MeshSdfBakerTest extends TestCase
{
    public function testThrowsOnEmptyMesh(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MeshSdfBaker::bake(new MeshData([], [], [], []), 8);
    }

    public function testBakedBoxSignMatchesInsideOutside(): void
    {
        $mesh = BoxMesh::generate(2.0, 2.0, 2.0); // half-extent 1, centred at origin
        $vol = MeshSdfBaker::bake($mesh, 24, 0.5);

        // Deep inside => negative; well outside => positive.
        $this->assertLessThan(0.0, $vol->sample(new Vec3(0, 0, 0)), 'centre is inside');
        $this->assertGreaterThan(0.0, $vol->sample(new Vec3(2.4, 0, 0)), 'outside +x');
        $this->assertGreaterThan(0.0, $vol->sample(new Vec3(0, 2.4, 0)), 'outside +y');
    }

    public function testBakedBoxDistanceApproximatesAnalyticBox(): void
    {
        $mesh = BoxMesh::generate(2.0, 2.0, 2.0);
        $analytic = new BoxSdf(new Vec3(2, 2, 2));
        // Padding 1.0 keeps every sample point inside the baked volume (max
        // corner = 2.0); points beyond it would clamp to the boundary cell.
        $vol = MeshSdfBaker::bake($mesh, 32, 1.0);

        // Rasterised SDF tracks the exact box SDF within ~1.5 cells away from
        // the surface (closest-triangle distance == exact box distance; the
        // error budget is the trilinear grid resolution).
        $tol = $vol->cellSize * 1.8;
        $samples = [
            [1.8, 0.0, 0.0],   // outside, near +x face
            [0.0, 1.7, 0.2],   // outside, near +y face
            [1.5, 1.5, 0.0],   // outside, near an edge
            [0.0, 0.0, 0.0],   // centre (inside)
        ];
        foreach ($samples as $c) {
            $p = new Vec3($c[0], $c[1], $c[2]);
            $this->assertEqualsWithDelta($analytic->distance($p), $vol->sample($p), $tol, "at {$p}");
        }
    }
}
