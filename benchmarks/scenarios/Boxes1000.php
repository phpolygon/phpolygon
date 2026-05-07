<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Scenarios;

use PHPolygon\Benchmarks\Scenario;
use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\DirectionalLight;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\Engine;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\System\Camera3DSystem;
use PHPolygon\System\Renderer3DSystem;
use PHPolygon\System\Transform3DSystem;

/**
 * 1000 box entities laid out in a 10x10x10 grid, each with its own
 * MeshRenderer + Transform3D. Stresses the per-entity DrawMesh path
 * in Renderer3DSystem and the Transform3DSystem world-matrix update.
 */
final class Boxes1000 implements Scenario
{
    public function name(): string
    {
        return 'boxes-1000';
    }

    public function setUp(Engine $engine): void
    {
        MeshRegistry::register('bench_box', BoxMesh::generate(1.0, 1.0, 1.0));

        $world = $engine->world;
        $world->addSystem(new Transform3DSystem());

        if ($engine->renderer3D !== null && $engine->commandList3D !== null) {
            $world->addSystem(new Camera3DSystem($engine->commandList3D, $engine->getConfig()->width, $engine->getConfig()->height));
            $world->addSystem(new Renderer3DSystem($engine->renderer3D, $engine->commandList3D));
        }

        // Camera looking down the grid
        $cam = $world->createEntity();
        $world->attachComponent($cam->id, new Transform3D(
            new Vec3(0.0, 12.0, 35.0),
            Quaternion::identity(),
            new Vec3(1.0, 1.0, 1.0),
        ));
        $world->attachComponent($cam->id, new Camera3DComponent(fov: 60.0));

        // One directional light so Renderer3DSystem emits a SetDirectionalLight command
        $sun = $world->createEntity();
        $world->attachComponent($sun->id, new Transform3D(
            new Vec3(0.0, 10.0, 0.0),
            Quaternion::identity(),
            new Vec3(1.0, 1.0, 1.0),
        ));
        $world->attachComponent($sun->id, new DirectionalLight(
            new Vec3(-0.5, -1.0, -0.3),
            new Color(1.0, 1.0, 1.0),
            1.0,
        ));

        // 10x10x10 grid, evenly spaced
        for ($x = 0; $x < 10; $x++) {
            for ($y = 0; $y < 10; $y++) {
                for ($z = 0; $z < 10; $z++) {
                    $box = $world->createEntity();
                    $world->attachComponent($box->id, new Transform3D(
                        new Vec3(($x - 5) * 1.5, $y * 1.5, ($z - 5) * 1.5),
                        Quaternion::identity(),
                        new Vec3(1.0, 1.0, 1.0),
                    ));
                    $world->attachComponent($box->id, new MeshRenderer('bench_box', 'default'));
                }
            }
        }
    }

    public function tickFrame(Engine $engine, int $frame, float $dt): void
    {
    }
}
