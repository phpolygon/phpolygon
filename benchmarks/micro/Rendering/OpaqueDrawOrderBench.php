<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Micro\Rendering;

use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\OpaqueDrawOrder;

/**
 * Opaque submission order of one frame (~400 draws over 100 materials, the
 * size of a furnished interior): the former usort with a PHP comparator
 * (legacy, inline) against OpaqueDrawOrder's precomputed string keys.
 *
 * Run:
 *   vendor/bin/phpbench run benchmarks/micro/Rendering/OpaqueDrawOrderBench.php --report=aggregate
 */
final class OpaqueDrawOrderBench
{
    /** @var list<object> */
    private array $commands = [];

    public function setUp(): void
    {
        for ($m = 0; $m < 100; $m++) {
            MaterialRegistry::register("bench_mat_{$m}", new Material(albedo: new Color(0.5, 0.5, 0.5)));
        }
        mt_srand(11);
        for ($i = 0; $i < 400; $i++) {
            $material = 'bench_mat_' . mt_rand(0, 99);
            $mesh = 'bench_mesh_' . mt_rand(0, 150);
            $this->commands[] = $i % 3 === 0
                ? DrawMeshInstanced::flat($mesh, $material, array_fill(0, 16, 0.0), 1)
                : new DrawMesh($mesh, $material, Mat4::identity());
        }
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(100)
     * @Iterations(5)
     */
    public function benchLegacyComparator(): void
    {
        $opaque = [];
        foreach ($this->commands as $cmd) {
            if ($cmd instanceof DrawMesh || $cmd instanceof DrawMeshInstanced) {
                $material = MaterialRegistry::get($cmd->materialId);
                if ($material === null || $material->alpha < 1.0) {
                    continue;
                }
                $opaque[] = [$cmd->materialId, $cmd->meshId, $cmd, $material, 0];
            }
        }
        usort($opaque, static fn (array $a, array $b): int => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]) ?: ($a[4] <=> $b[4]));
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(100)
     * @Iterations(5)
     */
    public function benchStringKeys(): void
    {
        OpaqueDrawOrder::sort($this->commands, false);
    }
}
