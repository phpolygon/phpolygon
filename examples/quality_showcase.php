<?php

/**
 * PHPolygon - Quality Showcase
 *
 * Test scene that exercises every rendering improvement landed in
 * Phase A + B + C of the visual-quality work:
 *
 * Phase A
 *   1) Procedural Normal Maps   -- 9 wall panels, one per NormalPattern
 *      (bricks, bumps, orange peel, hammered, hexagons, wood grain,
 *      scratches, cracked, fbm noise). Tangent space is derived per
 *      fragment via dFdx/dFdy so meshes stay tangent-buffer-free.
 *   2) Curvature-based AO       -- Stair-stepped boxes + tight inset
 *      corners; F1 cycles ScreenSpaceAO tiers.
 *   3) MSAA / FXAA              -- F2 cycles AA modes.
 *   4) ACES Tone Mapping        -- Bright over-driven point light keeps
 *      a filmic highlight roll-off.
 *   5) Camera-Following Shadow  -- Panel rig 30 m off origin; tall
 *      pylon casts the long shadow.
 *   ★) Procedural Carpaint      -- Row of Car prefabs with clearcoat +
 *      flakes + IBL.
 *
 * Phase B
 *   6) Vignette + Color Grading -- F4 cycles ColorGradingPreset
 *      (Neutral, Warm, Cool, Cinematic, Vibrant, Muted). F5 toggles
 *      a strong vignette.
 *   7) Surface-wear patterns    -- A second row of "wear panels" with
 *      worn paint, rust, brushed metal, polished rings.
 *   8) Wetness (SSR surrogate)  -- F6 toggles `wetness=1` on the
 *      ground material; the asphalt becomes glossy + IBL-bright.
 *   9) Area Lights              -- A 3x3 AreaLightHelper grid stands in
 *      front of the carpaint row, lighting it like a softbox.
 *
 * Phase C
 *  10) TAA setting               -- Selectable in the AA cycle as
 *      "TAA (preview)"; falls back to FXAA until the history buffer
 *      ships.
 *  11) Volumetric Fog / Godrays  -- F7 toggles in-shader scatter; the
 *      pylon and panels get a shafty look in low sun.
 *  12) Particle System           -- A continuous spark emitter sits on
 *      top of the pylon (slow drift + gravity + size fade).
 *
 * Controls
 *   F1 : cycle AO tier
 *   F2 : cycle AA mode (Off -> FXAA -> MSAA2x -> MSAA4x -> TAA preview)
 *   F3 : cycle Normal-pattern tile scale
 *   F4 : cycle Color-Grading preset
 *   F5 : toggle vignette
 *   F6 : toggle wet ground
 *   F7 : toggle volumetric fog
 *   F8 : toggle rain (4096-particle emitter, exercises flat-float storage)
 *   ESC: quit
 *
 * Run:  php examples/quality_showcase.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\DirectionalLight;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\ParticleEmitter;
use PHPolygon\Component\PointLight;
use PHPolygon\Component\Transform3D;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\CylinderMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Prefab\Vehicles\Car;
use PHPolygon\Rendering\AreaLightHelper;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetFog;
use PHPolygon\Rendering\Command\SetSky;
use PHPolygon\Rendering\Command\SetSkybox;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\NormalPattern;
use PHPolygon\Rendering\ProceduralSky;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ColorGradingPreset;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\SurfacePattern;
use PHPolygon\Scene\SceneBuilder;
use PHPolygon\System\Camera3DSystem;
use PHPolygon\System\ParticleSystem;
use PHPolygon\System\Renderer3DSystem;
use PHPolygon\System\Transform3DSystem;

$engine = new Engine(new EngineConfig(
    title:  'PHPolygon - Quality Showcase',
    width:  1280,
    height: 720,
    is3D:   true,
    firstLaunchCalibration: false,
));

// Patterns laid out left-to-right along +X.
$patterns = [
    NormalPattern::BRICKS,
    NormalPattern::BUMPS,
    NormalPattern::ORANGE_PEEL,
    NormalPattern::HAMMERED,
    NormalPattern::HEXAGONS,
    NormalPattern::WOOD_GRAIN,
    NormalPattern::SCRATCHES,
    NormalPattern::CRACKED,
    NormalPattern::NOISE,
];

$engine->onInit(function () use ($engine, $patterns): void {
    // -- Sun direction --------------------------------------------------
    // Low evening angle so shadows are long; this is the configuration
    // that makes camera-following shadow-frustum + texel-snap pay off.
    $lightTravelDir = (new Vec3(-0.55, -0.45, -0.35))->normalize();
    $sunFromSurface = $lightTravelDir->mul(-1.0);

    // Procedural sunset cubemap: drives skybox on OpenGL fallback and
    // the IBL reflection used by the carpaint clearcoat lobe.
    CubemapRegistry::registerProcedural('sky', ProceduralSky::sunset($lightTravelDir)->generate(256));

    // -- Meshes ---------------------------------------------------------
    MeshRegistry::register('ground',         PlaneMesh::generate(120.0, 120.0));
    MeshRegistry::register('wall_panel',     BoxMesh::generate(2.5, 3.0, 0.4));
    MeshRegistry::register('wear_panel',     BoxMesh::generate(2.5, 2.0, 0.4));
    MeshRegistry::register('ao_box_small',   BoxMesh::generate(1.0, 1.0, 1.0));
    MeshRegistry::register('ao_box_medium',  BoxMesh::generate(1.6, 1.6, 1.6));
    MeshRegistry::register('ao_box_large',   BoxMesh::generate(2.4, 2.4, 2.4));
    MeshRegistry::register('pylon',          CylinderMesh::generate(0.45, 14.0, 16));
    MeshRegistry::register('label_block',    BoxMesh::generate(2.4, 0.18, 0.05));
    MeshRegistry::register('particle_quad',  BoxMesh::generate(0.12, 0.12, 0.12));
    // Rain drops are stretched in Y so motion-blur is "free" - the box
    // already looks like a streak when small enough.
    MeshRegistry::register('rain_drop_quad', BoxMesh::generate(0.025, 0.20, 0.025));

    // -- Materials ------------------------------------------------------
    // Ground: stone with a procedural normal pattern for surface detail.
    MaterialRegistry::register('ground_stone', new Material(
        albedo: new Color(0.32, 0.32, 0.34),
        roughness: 0.9,
        metallic: 0.0,
        normalPattern: NormalPattern::HEXAGONS,
        normalScale: 6.0,
        normalIntensity: 1.0,
    ));

    // One material per pattern for the wall lineup.
    foreach ($patterns as $i => $pattern) {
        MaterialRegistry::register("panel_{$pattern}", new Material(
            albedo: new Color(0.78, 0.74, 0.66),
            roughness: 0.55,
            metallic: 0.0,
            normalPattern: $pattern,
            normalScale: 2.0,
            normalIntensity: 1.0,
        ));
    }

    // AO showcase: matte concrete with no normal map so the per-fragment
    // curvature darkening (corners between the boxes) is what reads.
    MaterialRegistry::register('ao_concrete', new Material(
        albedo: new Color(0.62, 0.60, 0.58),
        roughness: 0.85,
        metallic: 0.0,
    ));

    // Pylon: dark anodised metal, picks up specular from the bright
    // point-light to demonstrate ACES highlight roll-off.
    MaterialRegistry::register('pylon_metal', new Material(
        albedo: new Color(0.18, 0.18, 0.22),
        roughness: 0.30,
        metallic: 0.85,
        normalPattern: NormalPattern::SCRATCHES,
        normalScale: 6.0,
        normalIntensity: 0.6,
    ));

    // Label slabs: white emissive bands beneath each panel - act as
    // legible "panel labels" without needing a font/UI overlay.
    MaterialRegistry::register('label_emissive', new Material(
        albedo: new Color(0.05, 0.05, 0.05),
        roughness: 0.6,
        metallic: 0.0,
        emission: new Color(0.85, 0.78, 0.55),
    ));

    // Phase B - Surface-wear panels: one material per SurfacePattern.
    $wearPatterns = [
        SurfacePattern::WORN_PAINT,
        SurfacePattern::RUST,
        SurfacePattern::BRUSHED_METAL,
        SurfacePattern::POLISHED_RINGS,
    ];
    foreach ($wearPatterns as $sp) {
        MaterialRegistry::register("wear_{$sp}", new Material(
            albedo: new Color(0.65, 0.55, 0.45),
            roughness: 0.4,
            metallic: 0.7,
            surfacePattern: $sp,
            surfaceScale: 2.0,
            surfaceIntensity: 1.0,
        ));
    }

    // Phase C - particle "spark" material: emissive orange for godray
    // visibility against the dusk sky.
    MaterialRegistry::register('particle_default', new Material(
        albedo: new Color(0.05, 0.05, 0.05),
        emission: new Color(1.4, 0.9, 0.3),
        roughness: 0.8,
        useEnvironmentMap: false,
    ));

    // Rain emitter material: cool, bright, faintly emissive so the
    // particles read against the dusk sky. The 4096-particle rain
    // emitter is the use case that makes the flat-float storage in
    // ParticleEmitter pay off (would have been ~50 KB / frame in
    // sub-array overhead with the old layout).
    MaterialRegistry::register('rain_drop', new Material(
        albedo: new Color(0.40, 0.55, 0.75),
        emission: new Color(0.10, 0.20, 0.30),
        roughness: 0.4,
        metallic: 0.0,
        useEnvironmentMap: false,
    ));

    // -- Scene ----------------------------------------------------------
    $b = new SceneBuilder();

    // Camera placed off-origin (camera-following shadow frustum demo).
    $b->entity('Camera')
        ->with(new Camera3DComponent(fov: 55.0, near: 0.1, far: 250.0, active: true))
        ->with(new Transform3D(
            position: new Vec3(0.0, 5.5, 22.0),
            rotation: Quaternion::fromAxisAngle(new Vec3(1, 0, 0), -0.18),
        ));

    $b->entity('Sun')
        ->with(new DirectionalLight(direction: $lightTravelDir, color: new Color(1.0, 0.92, 0.78), intensity: 1.4))
        ->with(new Transform3D());

    $b->entity('Ground')
        ->with(new MeshRenderer('ground', 'ground_stone'))
        ->with(new Transform3D(position: new Vec3(0.0, 0.0, 0.0)));

    // -- Wall panels: one per NormalPattern, evenly spaced along +X. ---
    $panelSpacing = 3.2;
    $panelStartX  = -($panelSpacing * (count($patterns) - 1)) / 2.0;
    $panelZ       = -8.0;
    foreach ($patterns as $i => $pattern) {
        $x = $panelStartX + $i * $panelSpacing;
        $b->entity("Panel_{$pattern}")
            ->with(new MeshRenderer('wall_panel', "panel_{$pattern}"))
            ->with(new Transform3D(position: new Vec3($x, 1.5, $panelZ)));

        // Emissive label slab in front of the panel.
        $b->entity("Label_{$pattern}")
            ->with(new MeshRenderer('label_block', 'label_emissive'))
            ->with(new Transform3D(position: new Vec3($x, 0.10, $panelZ + 1.8)));
    }

    // -- AO showcase: stair-stepped concrete boxes (left of panels). ---
    // Curvature peaks where boxes meet, so this is where AO reads loudest.
    $aoOriginX = -16.0;
    $aoOriginZ = -3.0;
    $b->entity('AO_Base')
        ->with(new MeshRenderer('ao_box_large', 'ao_concrete'))
        ->with(new Transform3D(position: new Vec3($aoOriginX, 1.2, $aoOriginZ)));
    $b->entity('AO_Mid')
        ->with(new MeshRenderer('ao_box_medium', 'ao_concrete'))
        ->with(new Transform3D(position: new Vec3($aoOriginX + 1.6, 0.8, $aoOriginZ)));
    $b->entity('AO_Small')
        ->with(new MeshRenderer('ao_box_small', 'ao_concrete'))
        ->with(new Transform3D(position: new Vec3($aoOriginX + 2.8, 0.5, $aoOriginZ)));
    // Wedge corner: small box pushed into the side of the large one.
    $b->entity('AO_Wedge')
        ->with(new MeshRenderer('ao_box_small', 'ao_concrete'))
        ->with(new Transform3D(position: new Vec3($aoOriginX - 1.0, 0.5, $aoOriginZ + 1.4)));

    // -- Pylon: tall cylinder right of panels, casts a long shadow that
    // travels across the ground when the camera pans (texel-snap demo).
    $b->entity('Pylon')
        ->with(new MeshRenderer('pylon', 'pylon_metal'))
        ->with(new Transform3D(position: new Vec3(16.0, 7.0, -2.0)));

    // -- Phase B: surface-wear panel row (behind the main wall row). ----
    $wearPatternsList = [
        SurfacePattern::WORN_PAINT,
        SurfacePattern::RUST,
        SurfacePattern::BRUSHED_METAL,
        SurfacePattern::POLISHED_RINGS,
    ];
    foreach ($wearPatternsList as $j => $sp) {
        $x = -6.0 + $j * 3.0;
        $b->entity("WearPanel_{$sp}")
            ->with(new MeshRenderer('wear_panel', "wear_{$sp}"))
            ->with(new Transform3D(position: new Vec3($x, 1.0, -12.5)));
    }

    // -- Phase C: spark particle emitter on top of the pylon. -----------
    $b->entity('PylonSparks')
        ->with(new ParticleEmitter(
            meshId: 'particle_quad',
            materialId: 'particle_default',
            rate: 18.0,
            lifetime: 2.5,
            velocity: new Vec3(0.0, 1.6, 0.0),
            velocityJitter: new Vec3(0.4, 0.3, 0.4),
            gravity: new Vec3(0.0, -1.4, 0.0),
            startSize: 0.18,
            endSize: 0.0,
            startColor: new Color(1.4, 0.9, 0.3),
            endColor: new Color(0.4, 0.1, 0.0),
            maxParticles: 80,
        ))
        ->with(new Transform3D(position: new Vec3(16.0, 14.5, -2.0)));

    // Rain emitter: 4096 particles, capped wide spawn area (jitter on
    // X/Z), gravity-driven down. Starts with rate 0; F8 in the update
    // loop ramps it up to drown the scene in particles. This is the
    // benchmark case for the flat-storage refactor.
    $b->entity('Rain')
        ->with(new ParticleEmitter(
            meshId: 'rain_drop_quad',
            materialId: 'rain_drop',
            rate: 0.0,                                 // F8 enables this at runtime
            lifetime: 1.4,
            velocity: new Vec3(0.0, -8.0, 0.0),
            velocityJitter: new Vec3(40.0, 0.0, 40.0), // wide horizontal spread
            gravity: new Vec3(0.0, -6.0, 0.0),
            startSize: 0.6,
            endSize:   0.6,
            startColor: new Color(0.40, 0.55, 0.75),
            endColor:   new Color(0.40, 0.55, 0.75),
            maxParticles: 4096,
        ))
        ->with(new Transform3D(position: new Vec3(0.0, 12.0, 0.0)));

    // -- Carpaint hero: row of cars from the Car prefab in the centre.
    Car::demoLineup($b, origin: new Vec3(0.0, 0.0, 4.0), spacing: 6.0);

    // -- Bright point-light to drive ACES highlight roll-off. -----------
    // Intensity 6.0 deliberately pushes past linear clipping; the ACES
    // tone-map is what stops the surrounding panels from going pure
    // white. Try toggling ACES off in mesh3d.frag.glsl to see what
    // it's doing.
    $b->entity('AcesSpot')
        ->with(new PointLight(color: new Color(1.0, 0.85, 0.55), intensity: 6.0, radius: 9.0))
        ->with(new Transform3D(position: new Vec3(0.0, 4.5, -4.5)));

    $b->materialize($engine->world);

    // -- Systems ---------------------------------------------------------
    $commandList = $engine->commandList3D ?? new RenderCommandList();
    $engine->world->addSystem(new Transform3DSystem());
    $engine->world->addSystem(new Camera3DSystem($commandList, 1280, 720));
    $engine->world->addSystem(new ParticleSystem($commandList));
    $engine->world->addSystem(new Renderer3DSystem($engine->renderer3D, $commandList));
});

$ambientCmd = new SetAmbientLight(new Color(0.32, 0.34, 0.40), 0.55);
$fogCmd     = new SetFog(new Color(0.55, 0.45, 0.40), 60.0, 220.0);

$skyCmd = new SetSky(
    sunDirection:     (new Vec3(-0.55, -0.45, -0.35))->normalize()->mul(-1.0),
    sunColor:         new Color(1.0, 0.85, 0.55),
    sunIntensity:     1.6,
    zenithColor:      new Color(0.18, 0.28, 0.55),
    horizonColor:     new Color(1.00, 0.55, 0.30),
    groundColor:      new Color(0.10, 0.08, 0.06),
    sunSize:          0.04,
    sunGlowSize:      0.30,
    sunGlowIntensity: 0.50,
    cloudCover:       0.25,
    cloudAltitude:    60.0,
    cloudDensity:     0.6,
    cloudWindSpeed:   1.5,
);
$skyboxCmd = new SetSkybox('sky');

// Quality-toggle state (cycled via F1..F7 below).
$aoCycle    = [ScreenSpaceAO::Off, ScreenSpaceAO::Low, ScreenSpaceAO::Medium, ScreenSpaceAO::High];
$aaCycle    = [AntiAliasing::Off, AntiAliasing::Fxaa, AntiAliasing::Msaa2x, AntiAliasing::Msaa4x, AntiAliasing::Taa];
$nsCycle    = [1.0, 2.0, 4.0];
$gradeCycle = [
    ColorGradingPreset::Neutral, ColorGradingPreset::Warm, ColorGradingPreset::Cool,
    ColorGradingPreset::Cinematic, ColorGradingPreset::Vibrant, ColorGradingPreset::Muted,
];
$aoIndex    = 2; // default Medium
$aaIndex    = 1; // default FXAA
$nsIndex    = 1; // default 2x
$gradeIndex = 0; // Neutral
$vignetteOn = false;
$wetOn      = false;
$volFogOn   = false;
$rainOn     = false;

$engine->onUpdate(function (Engine $engine) use (
    $ambientCmd, $fogCmd, $skyCmd, $skyboxCmd,
    &$aoIndex, &$aaIndex, &$nsIndex, &$gradeIndex, &$vignetteOn, &$wetOn, &$volFogOn, &$rainOn,
    $aoCycle, $aaCycle, $nsCycle, $gradeCycle, $patterns
): void {
    $input = $engine->input;
    if ($input->isKeyPressed(256)) { // ESC
        $engine->stop();
        return;
    }

    // F1..F7 (GLFW codes 290..296)
    if ($input->isKeyPressed(290)) {
        $aoIndex = ($aoIndex + 1) % count($aoCycle);
        $engine->graphics->update(fn ($s) => $s->with(ambientOcclusion: $aoCycle[$aoIndex]));
        echo "AO -> " . $aoCycle[$aoIndex]->label() . "\n";
    }
    if ($input->isKeyPressed(291)) {
        $aaIndex = ($aaIndex + 1) % count($aaCycle);
        $engine->graphics->update(fn ($s) => $s->with(antiAliasing: $aaCycle[$aaIndex]));
        echo "AA -> " . $aaCycle[$aaIndex]->label() . "\n";
    }
    if ($input->isKeyPressed(292)) {
        $nsIndex = ($nsIndex + 1) % count($nsCycle);
        $scale = $nsCycle[$nsIndex];
        foreach ($patterns as $pattern) {
            MaterialRegistry::register("panel_{$pattern}", new Material(
                albedo: new Color(0.78, 0.74, 0.66),
                roughness: 0.55,
                metallic: 0.0,
                normalPattern: $pattern,
                normalScale: $scale * 2.0,
                normalIntensity: 1.0,
            ));
        }
        echo "Normal scale -> {$scale}x\n";
    }
    if ($input->isKeyPressed(293)) {
        $gradeIndex = ($gradeIndex + 1) % count($gradeCycle);
        $engine->graphics->update(fn ($s) => $s->with(colorGrading: $gradeCycle[$gradeIndex]));
        echo "Color grading -> " . $gradeCycle[$gradeIndex]->label() . "\n";
    }
    if ($input->isKeyPressed(294)) {
        $vignetteOn = !$vignetteOn;
        $engine->graphics->update(fn ($s) => $s->with(vignetteIntensity: $vignetteOn ? 0.55 : 0.0));
        echo "Vignette -> " . ($vignetteOn ? 'on' : 'off') . "\n";
    }
    if ($input->isKeyPressed(295)) {
        $wetOn = !$wetOn;
        // Re-register the ground material with the toggled wetness value.
        MaterialRegistry::register('ground_stone', new Material(
            albedo: new Color(0.32, 0.32, 0.34),
            roughness: 0.9,
            metallic: 0.0,
            normalPattern: NormalPattern::HEXAGONS,
            normalScale: 6.0,
            normalIntensity: 1.0,
            wetness: $wetOn ? 1.0 : 0.0,
        ));
        echo "Wet ground -> " . ($wetOn ? 'on' : 'off') . "\n";
    }
    if ($input->isKeyPressed(296)) {
        $volFogOn = !$volFogOn;
        $engine->graphics->update(fn ($s) => $s->with(volumetricFog: $volFogOn));
        echo "Volumetric fog -> " . ($volFogOn ? 'on' : 'off') . "\n";
    }
    if ($input->isKeyPressed(297)) { // F8
        $rainOn = !$rainOn;
        // Find the rain emitter and flip its spawn rate.
        foreach ($engine->world->query(\PHPolygon\Component\ParticleEmitter::class) as $entity) {
            $emitter = $entity->get(\PHPolygon\Component\ParticleEmitter::class);
            if ($emitter->meshId === 'rain_drop_quad') {
                $emitter->rate = $rainOn ? 2800.0 : 0.0;
                if (!$rainOn) {
                    $emitter->clear();
                }
            }
        }
        echo "Rain -> " . ($rainOn ? 'on (4096 particles)' : 'off') . "\n";
    }

    // Re-emit frame-level commands. Renderer3DSystem clears the command
    // list after every render, so anything per-frame must be re-added.
    $cl = $engine->commandList3D;
    if ($cl !== null) {
        $cl->add($ambientCmd);
        $cl->add($fogCmd);
        $cl->add($skyCmd);
        $cl->add($skyboxCmd);

        // Phase B - area light: 3x3 softbox standing in front of the cars.
        AreaLightHelper::pushRectangle(
            $cl,
            center:      new Vec3(0.0, 4.5, 8.0),
            orientation: Quaternion::identity(),
            width:       6.0,
            height:      2.5,
            color:       new Color(1.0, 0.94, 0.85),
            intensity:   3.5,
            radius:      14.0,
            samples:     3,
        );
    }
});

$engine->run();
