<?php

declare(strict_types=1);

/**
 * Memory of loading a large scene through SceneManager: peak, and what stays alive once onLoad runs.
 *
 *   php benchmarks/scenarios/SceneLoadMemory.php [entities=90000] [progressive=1]
 *
 * Every entity gets a Transform3D, a MeshRenderer and a BoxCollider3D, every tenth a child.
 * "during onLoad" is the heap a scene's onLoad sees; "peak" is the whole load. Run it in a fresh process.
 */

use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Math\Vec3;
use PHPolygon\Scene\LoadMode;
use PHPolygon\Scene\Scene;
use PHPolygon\Scene\SceneBuilder;
use PHPolygon\Scene\SceneManager;

require __DIR__ . '/../../vendor/autoload.php';

ini_set('memory_limit', '-1');

final class BenchLoadScene extends Scene
{
    public static int $count = 90000;
    public static int $atOnLoad = 0;
    public static int $atStart = 0;

    public function getName(): string
    {
        return 'bench_load';
    }

    public function build(SceneBuilder $builder): void
    {
        for ($i = 0; $i < self::$count; $i++) {
            $e = $builder->entity("e{$i}")
                ->with(new Transform3D(new Vec3($i % 300, 0.0, intdiv($i, 300))))
                ->with(new MeshRenderer('box', 'mat_' . ($i % 40)))
                ->with(new BoxCollider3D(new Vec3(1.0, 1.0, 1.0)));
            if ($i % 10 === 0) {
                $e->child("e{$i}_part")->with(new Transform3D())->with(new MeshRenderer('box', 'mat_1'));
            }
        }
    }

    public function onLoad(Engine $engine): void
    {
        gc_collect_cycles();
        self::$atOnLoad = memory_get_usage();
    }
}

BenchLoadScene::$count = (int) ($argv[1] ?? 90000);
$progressive = (int) ($argv[2] ?? 1) === 1;

$engine = new Engine(new EngineConfig(headless: true, is3D: true));
$scenes = new SceneManager($engine);
$scenes->register('bench_load', BenchLoadScene::class);

gc_collect_cycles();
$base = memory_get_usage();
memory_reset_peak_usage();
$t = hrtime(true);
if ($progressive) {
    foreach ($scenes->loadSceneProgressive('bench_load', LoadMode::Single) as $_) {
    }
} else {
    $scenes->loadScene('bench_load', LoadMode::Single);
}
$ms = (hrtime(true) - $t) / 1e6;
gc_collect_cycles();

printf(
    "entities %d (%s): peak +%.1f MB, during onLoad +%.1f MB, after +%.1f MB, %.0f ms\n",
    BenchLoadScene::$count,
    $progressive ? 'progressive' : 'sync',
    (memory_get_peak_usage() - $base) / 1048576,
    (BenchLoadScene::$atOnLoad - $base) / 1048576,
    (memory_get_usage() - $base) / 1048576,
    $ms,
);
