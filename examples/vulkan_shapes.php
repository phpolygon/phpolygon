<?php

/**
 * PHPolygon — Vulkan shapes showcase
 *
 * All built-in procedural geometry generators in one scene:
 *   Box · Sphere · Cylinder · Wedge · Plane (ground)
 *
 * Run: php examples/vulkan_shapes.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\DirectionalLight;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\CylinderMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Geometry\WedgeMesh;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Command\SetSkyColors;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\Camera3DSystem;
use PHPolygon\System\Renderer3DSystem;

$engine = new Engine(new EngineConfig(
    title:           'PHPolygon — Vulkan shapes',
    width:           1280,
    height:          720,
    is3D:            true,
    renderBackend3D: 'vulkan',
));

$engine->onInit(function () use ($engine): void {
    MeshRegistry::register('box',      BoxMesh::generate(1.0, 1.0, 1.0));
    MeshRegistry::register('sphere',   SphereMesh::generate(radius: 0.6, stacks: 20, slices: 28));
    MeshRegistry::register('cylinder', CylinderMesh::generate(radius: 0.5, height: 1.2, segments: 20));
    MeshRegistry::register('wedge',    WedgeMesh::generate(1.0));
    MeshRegistry::register('ground',   PlaneMesh::generate(14.0, 5.0));

    MaterialRegistry::register('red',   new Material(albedo: new Color(0.8, 0.25, 0.2),  roughness: 0.7, metallic: 0.0));
    MaterialRegistry::register('green', new Material(albedo: new Color(0.25, 0.7, 0.3),  roughness: 0.6, metallic: 0.0));
    MaterialRegistry::register('blue',  new Material(albedo: new Color(0.2, 0.4, 0.85),  roughness: 0.5, metallic: 0.0));
    MaterialRegistry::register('gold',  new Material(albedo: new Color(0.9, 0.75, 0.1),  roughness: 0.3, metallic: 1.0));
    MaterialRegistry::register('floor', new Material(albedo: new Color(0.45, 0.43, 0.40), roughness: 0.9, metallic: 0.0));

    $commandList = $engine->commandList3D ?? new RenderCommandList();
    $engine->world->addSystem(new Camera3DSystem($commandList, 1280, 720));
    $engine->world->addSystem(new Renderer3DSystem($engine->renderer3D, $commandList));

    $camera = $engine->world->createEntity();
    $camera->attach(new Camera3DComponent(fov: 58.0, active: true));
    $camera->attach(new Transform3D(position: new Vec3(0.0, 2.5, 9.0)));

    $sun = $engine->world->createEntity();
    $sun->attach(new DirectionalLight(
        direction: new Vec3(-0.7, -1.0, -0.5),
        color:     new Color(1.0, 0.97, 0.90),
        intensity: 1.3,
    ));
    $sun->attach(new Transform3D());

    // Ground
    $ground = $engine->world->createEntity();
    $ground->attach(new MeshRenderer('ground', 'floor'));
    $ground->attach(new Transform3D(position: new Vec3(0.0, -0.5, 0.0)));

    // Shapes in a row
    $shapes = [
        ['box',      'red',   -4.5, 0.0, 0.0],
        ['sphere',   'green', -1.5, 0.0, 0.0],
        ['cylinder', 'blue',   1.5, 0.0, 0.0],
        ['wedge',    'gold',   4.5, 0.0, 0.0],
    ];

    foreach ($shapes as [$mesh, $mat, $x, $y, $z]) {
        $e = $engine->world->createEntity();
        $e->attach(new MeshRenderer($mesh, $mat));
        $e->attach(new Transform3D(position: new Vec3($x, $y, $z)));
    }
});

$engine->onUpdate(function (Engine $engine, float $dt): void {
    $engine->commandList3D?->add(new SetSkyColors(
        skyColor:     new Color(0.35, 0.50, 0.75),
        horizonColor: new Color(0.65, 0.75, 0.88),
    ));
});

$engine->run();
