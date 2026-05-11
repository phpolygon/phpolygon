# Graphics Quality Settings

PHPolygon ships with a player-facing graphics-quality system that handles
persistence, hardware fingerprinting, first-launch calibration, and
optional live adaptation. Games are responsible only for surfacing a
settings panel; the engine wires everything else up automatically.

This guide is for game developers integrating the system. For the engine
overview, see the "Graphics Quality Settings" section in the project
`CLAUDE.md`.

---

## TL;DR

```php
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\UI\GraphicsOptionsPanel;
use PHPolygon\UI\UIContext;

$engine = new Engine(new EngineConfig(
    is3D: true,
    firstLaunchCalibration: true,         // default
    benchmarkScene: MyOptionalBenchmark::class, // null = built-in
));

$ui = new UIContext($engine->renderer2D, $engine->input);
$panel = new GraphicsOptionsPanel($engine, $ui);

$engine->onRender(function () use ($panel, $ui) {
    if (!$showSettingsScreen) return;
    $panel->draw(x: 40, y: 40, width: 360);
    $ui->flushOverlays();
});

$engine->run();
```

That is the complete integration. No event hookups, no save-game code, no
custom benchmark scene needed.

---

## Storage and lifecycle

| When                                    | What happens |
|-----------------------------------------|--------------|
| `Engine::__construct`                   | `GraphicsSettingsManager` constructed; reads `saves/graphics.json` if it exists. |
| Renderer3D created (in `Engine::run`)   | `applyToRenderer()` pushes the settings to the backend. |
| First launch (no `graphics.json`)       | If `EngineConfig::$firstLaunchCalibration === true`, the auto-tuner runs `BenchmarkScene` and writes the chosen settings. |
| Player edits panel                      | `GraphicsSettingsManager::update()` immutably updates settings, persists to disk, applies to renderer + window + game loop, dispatches `GraphicsSettingsChanged`. |
| Player toggles to Adaptive              | `AdaptiveQualityController` starts watching frame times (no-op until 60 warm-up frames pass). |
| Hardware change between launches        | Saved fingerprint mismatches the live one, `isRecalibrationRecommended()` returns true so the UI can prompt the player. |

Storage layout:

```json
{
  "version": 1,
  "hardwareFingerprint": "<sha256 of os/arch/ext signature>",
  "settings": {
    "mode": "adaptive",
    "targetFps": 60.0,
    "renderScale": 0.9,
    "shadowQuality": "medium",
    ...
  }
}
```

The hardware fingerprint is intentionally coarse - it cannot capture
the GPU itself before a GL context exists. Refine it after window
initialisation by calling `updateHardwareFingerprintFromGl()` with the
strings returned by `glGetString(GL_VENDOR)` / `GL_RENDERER`.

---

## Manual editing

```php
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\Quality\ShaderQuality;

$engine->graphics->update(fn(GraphicsSettings $s) => $s->with(
    shadowQuality: ShadowQuality::Low,
    shaderQuality: ShaderQuality::Unlit,
    bloom: false,
));
```

Every `update()` call:

1. Computes a new `GraphicsSettings` (immutable).
2. Persists `saves/graphics.json`.
3. Calls `applyToRenderer()` on the live 3D backend.
4. Forwards vsync to the window, fpsCap to the GameLoop, and anisotropy /
   LOD bias to the TextureManager.
5. Dispatches `GraphicsSettingsChanged($previous, $current)`.

If the new settings equal the old ones, all of the above is skipped (no
spurious events, no disk writes).

---

## Adaptive mode

```php
use PHPolygon\Rendering\Quality\QualityMode;

$engine->graphics->setMode(QualityMode::Adaptive);
$engine->graphics->setTargetFps(60.0);
```

The controller does nothing in any other mode. While Adaptive is active:

- It samples frame times into a 120-frame ring buffer.
- Every 1.0 s of real time it computes the average FPS.
- Dead-band: 95% - 110% of target -> no change.
- Below 95%: stage one downgrade from the cost-impact stack.
- Above 110% sustained for 5 s: stage one upgrade.
- 60 frames after any settings change or scene load are ignored as warm-up.

Adjustments dispatch a `QualityChangeRequest` event before applying. A
listener can veto:

```php
$engine->events->listen(QualityChangeRequest::class, function (QualityChangeRequest $e) use ($combatSystem) {
    if ($combatSystem->isInCombat()) {
        $e->veto(); // controller will retry in 5 s
    }
});
```

The `proposed` field carries the full `GraphicsSettings` the controller is
about to apply. `reason` is a short diagnostic string ("avg 42.3 fps < 60 *
0.95").

---

## First-launch calibration

```php
new EngineConfig(
    firstLaunchCalibration: true,
    graphicsSettingsPath: 'saves/graphics.json',
    benchmarkScene: MyGame\Bench\CityCalibrationScene::class,
);
```

Calibration runs once, immediately after `onInit`, only if no
`graphics.json` exists. The flow:

1. `GraphicsCalibrationStarted` event.
2. `BenchmarkScene` (or the configured override) loads.
3. Auto-tuner steps from highest tier downward; each step runs 30 warm-up
   frames + 300 measured frames and computes p95 frame time.
4. Stops as soon as p95 fits inside `(1000 / targetFps) * 0.85`
   (15% headroom).
5. `GraphicsCalibrationCompleted` event with a `BenchmarkResult`:
   - `hardwareFingerprint`, `targetFps`, `achievedP95Ms`
   - `finalSettings` (a `GraphicsSettings`)
   - `tierHistory` (every tier evaluated, with frame-time evidence)

Force a re-run from the in-game options panel:

```php
$result = $engine->graphics->recalibrate();
```

This is what the panel's "Recalibrate Now" button does internally.

---

## Custom benchmark scene

The built-in `BenchmarkScene` (`src/Rendering/Quality/BenchmarkScene.php`)
contains 200 instanced buildings + 50 free props + a directional light. It
covers the workload most third-person / strategy games hit.

If your game has a wildly different workload (e.g. heavy particle systems
or tessellation), provide a custom scene:

```php
final class CityCalibrationScene extends Scene
{
    public function getName(): string { return 'City Calibration'; }

    public function build(SceneBuilder $b): void
    {
        // Spawn worst-case representative geometry, lights, particles, etc.
    }
}

new EngineConfig(benchmarkScene: CityCalibrationScene::class);
```

The auto-tuner does not require any special API on the Scene - the same
`build()` it would use during normal play is enough.

---

## What lives where

| Concern                                | File |
|----------------------------------------|------|
| Settings value object                  | `src/Rendering/GraphicsSettings.php` |
| Manager (load/save/apply)              | `src/Rendering/GraphicsSettingsManager.php` |
| Quality enums                          | `src/Rendering/Quality/*.php` |
| Auto-tuner                             | `src/Rendering/Quality/GraphicsAutoTuner.php` |
| Benchmark scene                        | `src/Rendering/Quality/BenchmarkScene.php` |
| Adaptive controller                    | `src/Rendering/Quality/AdaptiveQualityController.php` |
| Cost-impact stack                      | `src/Rendering/Quality/AdaptiveTierStack.php` |
| Result record                          | `src/Rendering/Quality/BenchmarkResult.php` |
| UI panel                               | `src/UI/GraphicsOptionsPanel.php` |
| Events                                 | `src/Event/Graphics*.php`, `QualityChangeRequest.php` |

---

## Pitfalls

- **Render scale is honoured by every backend.** MSAA is wired into
  OpenGL and Vio (multisample renderbuffer + resolve blit); on Metal
  the offscreen FBO is single-sample only - the `AntiAliasing::Msaa*`
  modes degrade to FXAA at present. Vulkan owns its own multisample
  image attachment and re-probes the supported sample count per
  context. MSAA may silently fall back to single-sample on backends
  /drivers that reject the requested sample count - a single STDERR
  diagnostic is logged once per process, but no error is raised.
- **Hardware fingerprints are coarse.** Two machines with identical OS /
  arch will hash the same until you call `updateHardwareFingerprintFromGl()`
  after the GL context is current. Most games can skip that refinement -
  the worst case is one missed "you upgraded your GPU, want to recalibrate?"
  prompt.
- **Adaptive mode does not touch `TextureQuality` or `MeshLodTier`.** Those
  are too expensive to hot-swap. If your game wants the player to be able to
  trade those values, expose them in the manual section of the panel and
  leave the adaptive controller alone.
- **The first-launch overlay uses Renderer2D directly**, before the game's
  UI is initialised. If your game replaces `Renderer2D` with something
  exotic (e.g. a custom batch renderer that requires explicit setup), pass
  `firstLaunchCalibration: false` and run `recalibrate()` yourself once
  your renderer is ready.

---

## Visual quality additions (Phase A+B+C)

Beyond the original render-scale / MSAA / shadow tier knobs, the engine
now ships a number of analytic visual-quality features. They are all
shader-side: no extra GPU memory, no precomputed assets.

### `ScreenSpaceAO`

Per-fragment curvature-based ambient occlusion. Darkens corners and
crevices via screen-space normal derivatives. Tiers map to a single
`u_ao_strength` uniform: `Off=0.0`, `Low=0.4`, `Medium=0.7`, `High=1.0`
(the default is `Medium`). A future depth-buffer SSAO pass can replace
the in-shader path without changing the enum or uniform name.

### `ColorGradingPreset`

Lift / Gamma / Gain + saturation, evaluated in linear space before
ACES tone-map. Six presets ship: `Neutral`, `Warm`, `Cool`,
`Cinematic`, `Vibrant`, `Muted`. `Neutral` resolves to identity values
and short-circuits in the shader.

### Vignette

Radial darkening evaluated in screen space. Set
`GraphicsSettings::$vignetteIntensity` in `[0, 1]`; 0 disables.

### Volumetric fog / godrays

Eight-step in-shader ray-march with a Henyey-Greenstein-style phase
function aligned with the primary directional light. Toggle with
`GraphicsSettings::$volumetricFog`. Independent from the linear
`SetFog` distance fog, which stays unconditional.

### Procedural normal maps (`NormalPattern`)

Per-material analytic normal patterns: bricks, bumps, orange peel,
hammered metal, hexagons, wood grain, scratches, cracked surface, fbm
noise. Tangent space is derived per-fragment via screen-space
derivatives so meshes stay tangent-buffer-free.

```php
MaterialRegistry::register('brick_wall', new Material(
    albedo: new Color(0.55, 0.30, 0.20),
    roughness: 0.85,
    normalPattern: NormalPattern::BRICKS,
    normalScale: 2.0,
    normalIntensity: 1.0,
));
```

### Procedural surface wear (`SurfacePattern`)

Per-material AO / roughness / metallic / albedo modulation. Four
patterns: `WORN_PAINT`, `RUST`, `BRUSHED_METAL`, `POLISHED_RINGS`.

```php
MaterialRegistry::register('weathered_door', new Material(
    albedo: new Color(0.65, 0.55, 0.45),
    roughness: 0.4, metallic: 0.7,
    surfacePattern: SurfacePattern::WORN_PAINT,
    surfaceScale: 2.0,
    surfaceIntensity: 1.0,
));
```

### Wetness (SSR surrogate)

Forward-renderer stand-in for screen-space reflections.
`Material::$wetness` in `[0, 1]` boosts the IBL contribution on
upward-facing fragments and reduces effective roughness, so wet
asphalt and polished floors read as reflective without a G-buffer
ray-march pass.

### Carpaint extras

`Material::carpaint($albedo, ...)` returns a metallic + clearcoat +
flake material driven by `proc_mode == 10` in the mesh shader. The
clearcoat lobe uses a fixed dielectric F0 and an independent
roughness; flakes are jittered tangent-space normal perturbations.

### Camera-following shadow

`ShadowMapRenderer::updateLightMatrix(direction, cameraTarget)` and
`VioRenderer3D::computeLightSpaceMatrix(direction, cameraTarget)` both
accept an optional camera centre. When supplied the shadow frustum
follows the camera and is texel-snapped to the shadow-map grid.
Required for stable open-world shadows; both backends pass the camera
position automatically.

### Area lights (`AreaLightHelper`)

Forward-pipeline stand-in for analytic rectangular area lights. Splits
the rectangle into a regular point-light grid that sums to the same
total radiance.

```php
AreaLightHelper::pushRectangle(
    $commandList,
    center: new Vec3(0.0, 4.0, -3.0),
    orientation: Quaternion::identity(),
    width: 3.0, height: 1.5,
    color: new Color(1.0, 0.92, 0.78),
    intensity: 4.0,
    samples: 3, // 3x3 = 9 sub-lights
);
```

### Particle system

`ParticleEmitter` component + `ParticleSystem`. Emits a single
`DrawMeshInstanced` per emitter per frame; particles integrate
position / age in the system's `update`. Renderer-side particles
remain plain instanced quads - per-instance colour streams and sprite
atlases are deliberately out of the first cut.

### Screen-space reflections (`ScreenSpaceReflections`)

Quality enum with three tiers (`Off`, `Low`, `High`) bound to a
`u_ssr_intensity` uniform consumed by every backend's mesh shader.

On the **OpenGL backend** the engine ships a real composite pass
(`OpenGLSsrPass`):

- `OpenGLOffscreenTarget` exposes the resolved depth attachment as a
  sampleable texture (`depthTextureId()`).
- The pass reconstructs world position from the depth sample, world
  normal from screen-space derivatives, and ray-marches the
  reflection vector for 24 steps. A hit blends the sampled scene
  colour into the output; a miss falls back to the standard wetness
  IBL lobe.
- SSR and FXAA are mutually exclusive in the present pipeline (FXAA
  would need a temp render target to break the read/write cycle).

On **Vio + Metal** the same `u_ssr_intensity` uniform amplifies the
wetness IBL contribution so games get a visible response from the
setting without needing the depth-buffer pass to migrate first.

### Temporal anti-aliasing (`AntiAliasing::Taa`)

OpenGL ships a real TAA pass (`OpenGLTaaPass`):

- `TaaJitter::offset()` produces Halton(2,3) sub-pixel offsets each
  frame; `OpenGLRenderer3D::jitteredProjection()` adds the offset to
  the projection matrix's NDC translation entries before upload.
- The pass owns a private FBO + colour history texture, blends the
  current jittered frame against history with neighbourhood clamping
  (3x3 AABB) to suppress ghosting, then blits the composite into the
  history target for the next frame.
- TAA and SSR are mutually exclusive (TAA needs the resolved colour
  as input, SSR writes directly to the backbuffer).

`AntiAliasing::fallback()` still maps `Taa -> Fxaa` so backends that
haven't migrated (Vio, Metal) silently degrade rather than blank-frame.
The OpenGL backend ignores `fallback()` because its TAA path is real.

### Showcase

`examples/quality_showcase.php` exercises every item above with live
F1..F7 toggles. Use it as the visual reference when modifying any
mesh-shader path.
