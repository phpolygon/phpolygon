<?php

/**
 * PHPolygon - SVG-imported Cars Showcase
 *
 * Renders the four OpenMoji vehicle SVGs converted via `bin/svg2mesh` so
 * you can see what the SVG-to-3D pipeline produces compared to the
 * procedural Car prefab.
 *
 * The OpenMoji SVGs are multi-layer (body, wheels, windows, headlights),
 * each layer extruded to the same depth. Result: a stacked silhouette
 * card rather than a fully sculpted vehicle - useful as a billboard /
 * preview asset, less so as a hero car.
 *
 * Run: php examples/svg_cars.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\DirectionalLight;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Geometry\Generated\OpenmojiCarMesh;
use PHPolygon\Geometry\Generated\OpenmojiRacingMesh;
use PHPolygon\Geometry\Generated\OpenmojiSuvMesh;
use PHPolygon\Geometry\Generated\OpenmojiTruckMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetFog;
use PHPolygon\Rendering\Command\SetSky;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Scene\SceneBuilder;
use PHPolygon\System\Camera3DSystem;
use PHPolygon\System\Renderer3DSystem;
use PHPolygon\System\Transform3DSystem;

$engine = new Engine(new EngineConfig(
    title:  'PHPolygon - SVG Cars',
    width:  1280,
    height: 720,
    is3D:   true,
    firstLaunchCalibration: false,
));

$engine->onInit(function () use ($engine): void {
    // -- Materials -------------------------------------------------------
    MaterialRegistry::register('asphalt', new Material(
        albedo: new Color(0.18, 0.18, 0.20), roughness: 0.95, metallic: 0.0,
    ));
    // The OpenMoji SVGs come in vivid colours; render each car in a
    // matching unlit-ish material so the original silhouette stays
    // recognisable. Slight roughness gives them just enough Fresnel.
    MaterialRegistry::register('svg_red',     Material::carpaint(new Color(0.85, 0.20, 0.20), metallic: 0.4, roughness: 0.45, flakes: 0.15));
    MaterialRegistry::register('svg_blue',    Material::carpaint(new Color(0.20, 0.45, 0.85), metallic: 0.4, roughness: 0.45, flakes: 0.15));
    MaterialRegistry::register('svg_yellow',  Material::carpaint(new Color(0.95, 0.85, 0.15), metallic: 0.4, roughness: 0.45, flakes: 0.15));
    MaterialRegistry::register('svg_white',   Material::carpaint(new Color(0.85, 0.85, 0.88), metallic: 0.3, roughness: 0.50, flakes: 0.10));

    // -- Mesh registration ----------------------------------------------
    MeshRegistry::register('ground',         PlaneMesh::generate(60.0, 60.0));
    MeshRegistry::register('svg_car',        OpenmojiCarMesh::generate());
    MeshRegistry::register('svg_suv',        OpenmojiSuvMesh::generate());
    MeshRegistry::register('svg_truck',      OpenmojiTruckMesh::generate());
    MeshRegistry::register('svg_racing',     OpenmojiRacingMesh::generate());

    // -- Scene -----------------------------------------------------------
    $b = new SceneBuilder();

    $b->entity('Camera')
        ->with(new Camera3DComponent(fov: 55.0, near: 0.1, far: 200.0, active: true))
        ->with(new Transform3D(
            position: new Vec3(0.0, 4.0, 16.0),
            rotation: Quaternion::fromAxisAngle(new Vec3(1, 0, 0), -0.22),
        ));

    $lightTravelDir = (new Vec3(-0.4, -0.7, -0.3))->normalize();

    $b->entity('Sun')
        ->with(new DirectionalLight(direction: $lightTravelDir, color: new Color(1.0, 0.95, 0.85), intensity: 1.4))
        ->with(new Transform3D());

    $b->entity('Ground')
        ->with(new MeshRenderer('ground', 'asphalt'))
        ->with(new Transform3D(position: new Vec3(0.0, 0.0, 0.0)));

    // SVG meshes are normalised to the unit box (~1 m wide) and are
    // 2D-extrusions facing the camera, so we scale them up + face them
    // toward the viewer. Standing them on their wheels means rotating
    // around X by 0 (already upright). Place them in a 4-wide row.
    $cars = [
        ['svg_car',     'svg_red',    -7.5,  'Car'],
        ['svg_suv',     'svg_blue',   -2.5,  'SUV'],
        ['svg_truck',   'svg_yellow',  2.5,  'Truck'],
        ['svg_racing',  'svg_white',   7.5,  'Racing'],
    ];
    $scale = new Vec3(4.0, 4.0, 1.0); // SVGs are normalised to unit box; widen + tall
    foreach ($cars as [$mesh, $material, $x, $name]) {
        $b->entity($name)
            ->with(new Transform3D(
                position: new Vec3($x, 2.0, 0.0),
                scale:    $scale,
            ))
            ->with(new MeshRenderer($mesh, $material));
    }

    $b->materialize($engine->world);

    $commandList = $engine->commandList3D ?? new RenderCommandList();
    $engine->world->addSystem(new Transform3DSystem());
    $engine->world->addSystem(new Camera3DSystem($commandList, 1280, 720));
    $engine->world->addSystem(new Renderer3DSystem($engine->renderer3D, $commandList));
});

$ambientCmd = new SetAmbientLight(new Color(0.25, 0.27, 0.32), 1.0);
$fogCmd     = new SetFog(new Color(0.55, 0.45, 0.40), 30.0, 100.0);
$skyCmd     = new SetSky(
    sunDirection:     (new Vec3(-0.4, -0.7, -0.3))->normalize()->mul(-1.0),
    sunColor:         new Color(1.0, 0.85, 0.55),
    sunIntensity:     1.5,
    zenithColor:      new Color(0.20, 0.40, 0.75),
    horizonColor:     new Color(1.00, 0.55, 0.30),
    groundColor:      new Color(0.10, 0.08, 0.06),
    cloudCover:       0.30,
);

$engine->onUpdate(function (Engine $engine) use ($ambientCmd, $fogCmd, $skyCmd): void {
    if ($engine->input->isKeyPressed(256)) { // ESC
        $engine->stop();
        return;
    }
    $cl = $engine->commandList3D;
    if ($cl !== null) {
        $cl->add($ambientCmd);
        $cl->add($fogCmd);
        $cl->add($skyCmd);
    }
});

$engine->run();
