<?php

/**
 * PHPolygon - Prefab modifier API showcase
 *
 * Demonstrates `SceneBuilder::spawn(Prefab)->modifier()->...->place(Vec3)`
 * with the reference Car prefab (see src/Prefab/Vehicles/Car.php). The four
 * lineup variants and their default materials live in Car::demoLineup() and
 * Car::registerDefaultMaterials() so games can opt in with one call.
 *
 * Run: php examples/vio_prefab_cars.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\DirectionalLight;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Prefab\Vehicles\Car;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetFog;
use PHPolygon\Rendering\Command\SetSky;
use PHPolygon\Rendering\Command\SetSkybox;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\ProceduralSky;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Scene\SceneBuilder;
use PHPolygon\System\Camera3DSystem;
use PHPolygon\System\Renderer3DSystem;
use PHPolygon\System\Transform3DSystem;

$engine = new Engine(new EngineConfig(
    title:  'PHPolygon - Prefab Cars',
    width:  1280,
    height: 720,
    is3D:   true,
    // This is a Prefab-API demo, not a perf benchmark - skip calibration.
    firstLaunchCalibration: false,
));

$engine->onInit(function () use ($engine): void {
    // -- Ground (not Car-related, so registered directly) ------------------
    MaterialRegistry::register('asphalt', new Material(
        albedo: new Color(0.18, 0.18, 0.20), roughness: 0.95, metallic: 0.0,
    ));
    MeshRegistry::register('ground', PlaneMesh::generate(60.0, 60.0));

    // -- Sun direction --------------------------------------------------
    // DirectionalLight::$direction is the light's travel direction (sun ->
    // surface). SetSky::$sunDirection wants the opposite (surface -> sun)
    // so we keep both in sync from a single source of truth.
    $lightTravelDir = (new Vec3(-0.4, -0.7, -0.3))->normalize();
    $sunFromSurface = $lightTravelDir->mul(-1.0);

    // OpenGL fallback: SetSky is not yet handled there, so we register a
    // procedural sunset cubemap as a SetSkybox fallback. Vio and Metal
    // prefer SetSky (priority over SetSkybox in the renderer dispatch).
    CubemapRegistry::registerProcedural('sky', ProceduralSky::sunset($lightTravelDir)->generate(256));

    // -- Scene -------------------------------------------------------------
    $b = new SceneBuilder();

    $b->entity('Camera')
        ->with(new Camera3DComponent(fov: 55.0, near: 0.1, far: 200.0, active: true))
        ->with(new Transform3D(
            position: new Vec3(0.0, 4.0, 16.0),
            rotation: Quaternion::fromAxisAngle(new Vec3(1, 0, 0), -0.22),
        ));

    $b->entity('Sun')
        ->with(new DirectionalLight(direction: $lightTravelDir, color: new Color(1.0, 0.95, 0.85), intensity: 1.4))
        ->with(new Transform3D());

    $b->entity('Ground')
        ->with(new MeshRenderer('ground', 'asphalt'))
        ->with(new Transform3D(position: new Vec3(0.0, 0.0, 0.0)));

    // The prefab does the work: register default car materials + spawn
    // four chassis variants in a row centred on the origin.
    Car::demoLineup($b);

    $b->materialize($engine->world);

    // -- Systems & frame-level commands -----------------------------------
    // Transform3DSystem must run BEFORE Camera/Renderer so child world
    // matrices include their parent's translation.
    $commandList = $engine->commandList3D ?? new RenderCommandList();
    $engine->world->addSystem(new Transform3DSystem());
    $engine->world->addSystem(new Camera3DSystem($commandList, 1280, 720));
    $engine->world->addSystem(new Renderer3DSystem($engine->renderer3D, $commandList));

    // (Frame-level commands are pushed every frame in onUpdate below -
    // Renderer3DSystem clears the command list after every render(), so
    // commands added once in onInit would only be visible in frame 1.)
});

$ambientCmd = new SetAmbientLight(new Color(0.25, 0.27, 0.32), 1.0);
$fogCmd     = new SetFog(new Color(0.55, 0.45, 0.40), 30.0, 100.0);

// Atmospheric sky pass - analytic per-fragment sky with a visible sun
// disc, glow, sky/horizon gradient and a light cloud layer. On Metal
// this also drives the IBL cubemap that the carpaint shader samples
// for real reflections; on Vio the same SetSky renders the sky
// background directly via fragment_sky / ATMOSPHERE_FRAG.
$skyCmd = new SetSky(
    sunDirection:     (new Vec3(-0.4, -0.7, -0.3))->normalize()->mul(-1.0),
    sunColor:         new Color(1.0, 0.85, 0.55),
    sunIntensity:     1.5,
    zenithColor:      new Color(0.20, 0.40, 0.75),
    horizonColor:     new Color(1.00, 0.55, 0.30),
    groundColor:      new Color(0.10, 0.08, 0.06),
    sunSize:          0.04,
    sunGlowSize:      0.30,
    sunGlowIntensity: 0.50,
    cloudCover:       0.35,
    cloudAltitude:    60.0,
    cloudDensity:     0.7,
    cloudWindSpeed:   1.5,
);

// SetSkybox is the only sky path OpenGL currently handles; on Vio + Metal
// SetSky takes priority over SetSkybox in the command dispatch, so this
// is purely a backwards-compat fallback for the OpenGL backend.
$skyboxCmd = new SetSkybox('sky');

$engine->onUpdate(function (Engine $engine) use ($ambientCmd, $fogCmd, $skyCmd, $skyboxCmd): void {
    if ($engine->input->isKeyPressed(256)) { // ESC
        $engine->stop();
        return;
    }
    // Re-emit frame-level commands every tick. Renderer3DSystem::render()
    // clears the command list after each frame, so anything we want to
    // affect every frame must be re-added here.
    $cl = $engine->commandList3D;
    if ($cl !== null) {
        $cl->add($ambientCmd);
        $cl->add($fogCmd);
        $cl->add($skyCmd);
        $cl->add($skyboxCmd);
    }
});

$engine->run();
