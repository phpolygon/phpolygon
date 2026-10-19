<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Micro\System;

use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\TransparentDrawSorter;

/**
 * Per-frame cost of the global transparent sort (Renderer3DSystem::render):
 * 2000 opaque draws, 200 transparent panes and one 64-particle flat batch.
 *
 * Run:
 *   vendor/bin/phpbench run benchmarks/micro/System/TransparentSortBench.php --report=aggregate
 */
final class TransparentSortBench
{
    /** @var list<object> */
    private array $commands = [];

    public function setUp(): void
    {
        MeshRegistry::register('bench_box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('bench_opaque', new Material(albedo: new Color(0.5, 0.5, 0.5)));
        MaterialRegistry::register('bench_glass', new Material(albedo: new Color(0.2, 0.3, 0.3), alpha: 0.3));

        $this->commands = [new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 2.0, 0.0), new Vec3(0.0, 2.0, -1.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(1.0, 1.6, 0.1, 500.0),
        )];
        mt_srand(7);
        for ($i = 0; $i < 2000; $i++) {
            $this->commands[] = new DrawMesh('bench_box', 'bench_opaque', Mat4::translation(mt_rand(-100, 100), 0.0, -mt_rand(1, 200)));
        }
        for ($i = 0; $i < 200; $i++) {
            $this->commands[] = new DrawMesh('bench_box', 'bench_glass', Mat4::translation(mt_rand(-50, 50), 1.0, -mt_rand(1, 100))
                ->multiply(Mat4::scaling(2.0, 2.0, 0.03)));
        }
        $flat = [];
        for ($i = 0; $i < 64; $i++) {
            array_push($flat, 0.1, 0.0, 0.0, 0.0, 0.0, 0.1, 0.0, 0.0, 0.0, 0.0, 0.1, 0.0, (float) mt_rand(-5, 5), 1.0, (float) -mt_rand(1, 30), 1.0);
        }
        $this->commands[] = DrawMeshInstanced::flat('bench_box', 'bench_glass', $flat, 64);
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(50)
     * @Iterations(5)
     */
    public function benchSortFrame(): void
    {
        $list = new RenderCommandList();
        $list->replaceCommands($this->commands);
        TransparentDrawSorter::sortList($list);
    }
}
