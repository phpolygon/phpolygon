<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Scenarios;

use PHPolygon\Benchmarks\Scenario;
use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\DirectionalLight;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\PointLight;
use PHPolygon\Component\Transform3D;
use PHPolygon\Engine;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\CylinderMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\System\Camera3DSystem;
use PHPolygon\System\Renderer3DSystem;
use PHPolygon\System\Transform3DSystem;

/**
 * Representative gameplay scene: ground plane, 200 mixed primitives,
 * 8 point lights (the renderer's hard cap), one directional light,
 * a camera. Stresses the lighting / culling / sorting code in
 * Renderer3DSystem at a realistic entity count.
 */
final class MixedScene implements Scenario
{
    public function name(): string
    {
        return 'mixed-scene';
    }

    public function setUp(Engine $engine): void
    {
        MeshRegistry::register('bench_ground', PlaneMesh::generate(50.0, 50.0));
        MeshRegistry::register('bench_box', BoxMesh::generate(1.0, 1.0, 1.0));
        MeshRegistry::register('bench_cyl', CylinderMesh::generate(radius: 0.5, height: 1.5, segments: 16));
        MeshRegistry::register('bench_sph', SphereMesh::generate(radius: 0.5, stacks: 12, slices: 16));

        $world = $engine->world;
        $world->addSystem(new Transform3DSystem());

        if ($engine->renderer3D !== null && $engine->commandList3D !== null) {
            $world->addSystem(new Camera3DSystem($engine->commandList3D, $engine->getConfig()->width, $engine->getConfig()->height));
            $world->addSystem(new Renderer3DSystem($engine->renderer3D, $engine->commandList3D));
        }

        // Camera
        $cam = $world->createEntity();
        $world->attachComponent($cam->id, new Transform3D(
            new Vec3(0.0, 6.0, 18.0),
            Quaternion::identity(),
            new Vec3(1.0, 1.0, 1.0),
        ));
        $world->attachComponent($cam->id, new Camera3DComponent(fov: 60.0));

        // Directional sun
        $sun = $world->createEntity();
        $world->attachComponent($sun->id, new Transform3D(
            new Vec3(0.0, 20.0, 0.0),
            Quaternion::identity(),
            new Vec3(1.0, 1.0, 1.0),
        ));
        $world->attachComponent($sun->id, new DirectionalLight(
            new Vec3(-0.4, -1.0, -0.3),
            new Color(1.0, 0.95, 0.85),
            1.0,
        ));

        // Ground plane
        $ground = $world->createEntity();
        $world->attachComponent($ground->id, new Transform3D(
            new Vec3(0.0, 0.0, 0.0),
            Quaternion::identity(),
            new Vec3(1.0, 1.0, 1.0),
        ));
        $world->attachComponent($ground->id, new MeshRenderer('bench_ground', 'default'));

        // Point lights - twice the renderer cap so culling / sorting kicks in
        for ($i = 0; $i < 16; $i++) {
            $angle = ($i / 16.0) * 2.0 * M_PI;
            $light = $world->createEntity();
            $world->attachComponent($light->id, new Transform3D(
                new Vec3(\cos($angle) * 12.0, 3.0, \sin($angle) * 12.0),
                Quaternion::identity(),
                new Vec3(1.0, 1.0, 1.0),
            ));
            $world->attachComponent($light->id, new PointLight(
                color: new Color(1.0, 0.5 + 0.5 * \cos($angle), 0.5 + 0.5 * \sin($angle)),
                intensity: 1.5,
                radius: 8.0,
            ));
        }

        // 200 mixed primitives in a rough cluster around the origin
        $meshes = ['bench_box', 'bench_cyl', 'bench_sph'];
        for ($i = 0; $i < 200; $i++) {
            $meshId = $meshes[$i % 3];
            $angle = ($i / 200.0) * 8.0 * M_PI;
            $radius = 2.0 + ($i % 7) * 1.4;
            $height = 0.5 + (($i * 13) % 5) * 0.6;

            $entity = $world->createEntity();
            $world->attachComponent($entity->id, new Transform3D(
                new Vec3(\cos($angle) * $radius, $height, \sin($angle) * $radius),
                Quaternion::identity(),
                new Vec3(1.0, 1.0, 1.0),
            ));
            $world->attachComponent($entity->id, new MeshRenderer($meshId, 'default'));
        }
    }

    public function tickFrame(Engine $engine, int $frame, float $dt): void
    {
    }
}
