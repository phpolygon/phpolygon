<?php

/**
 * PHPolygon — Metal backend example
 *
 * Renders a spinning procedural box using the MetalRenderer3D backend.
 * Identical scene to vulkan_spinning_box.php — swap renderBackend3D to compare.
 *
 * Requirements:
 *   - ext-metal (hmennen90/php-metal-gpu via PIE), ext-glfw (php-glfw)
 *   - resources/shaders/compiled/mesh3d.metallib (run bin/compile-metal once)
 *   - macOS only — Metal is an Apple-exclusive API
 *   - Run: php examples/metal_spinning_box.php
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
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\Camera3DSystem;
use PHPolygon\System\Renderer3DSystem;

$engine = new Engine(new EngineConfig(
    title:           'PHPolygon — Metal spinning box',
    width:           1280,
    height:          720,
    is3D:            true,
    renderBackend3D: 'metal',   // <-- only this line differs from the Vulkan example
));

$engine->onInit(function () use ($engine): void {
    MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));

    MaterialRegistry::register('crate', new Material(
        albedo:    new Color(0.8, 0.55, 0.25),
        roughness: 0.7,
        metallic:  0.0,
    ));

    $commandList = $engine->commandList3D ?? new RenderCommandList();

    $engine->world->addSystem(new Camera3DSystem($commandList, 1280, 720));
    $engine->world->addSystem(new Renderer3DSystem($engine->renderer3D, $commandList));

    // Camera
    $camera = $engine->world->createEntity();
    $camera->attach(new Camera3DComponent(fov: 60.0, active: true));
    $camera->attach(new Transform3D(position: new Vec3(0.0, 1.5, 5.0)));

    // Sun
    $sun = $engine->world->createEntity();
    $sun->attach(new DirectionalLight(
        direction: new Vec3(-0.4, -1.0, -0.6),
        color:     new Color(1.0, 0.95, 0.85),
        intensity: 2.5,
    ));
    $sun->attach(new Transform3D());

    // Spinning box
    $box = $engine->world->createEntity();
    $box->attach(new MeshRenderer('box', 'crate'));
    $box->attach(new Transform3D(position: new Vec3(0.0, 0.0, 0.0)));
});

$engine->onUpdate(function (\PHPolygon\Engine $engine, float $dt): void {
    foreach ($engine->world->query(MeshRenderer::class, Transform3D::class) as $entity) {
        $transform = $entity->get(Transform3D::class);
        $transform->rotation = $transform->rotation->multiply(
            Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), $dt * 1.2)
        );
    }
});

$engine->run();
