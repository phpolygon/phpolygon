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

## Settings reference

Every field of `GraphicsSettings` (`src/Rendering/GraphicsSettings.php`), with
its default. The JSON key in `graphics.json` is the field name; enums are
stored by their lower-case value.

| Field | Default | Meaning |
|---|---|---|
| `mode` | `QualityMode::Manual` | `Manual`, `Adaptive` (the controller tunes towards `targetFps`) or `Off` (no automatic adjustment, no first-launch calibration). |
| `targetFps` | `60.0` | Target of the adaptive controller and the calibration. |
| `renderScale` | `1.0` | 0.5 – 2.0; down to 0.33 while a temporal upscaler is selected. |
| `shadowQuality` | `Medium` | `Off`, `Low`, `Medium`, `High` — shadow map resolution; `Off` skips the shadow pass. |
| `shadowDistance` | `50.0` | Stored and round-tripped; no renderer reads it at present. |
| `viewDistance` | `200.0` | Upper bound for the far distance of `SetFog`. |
| `antiAliasing` | `Fxaa` | `Off`, `Fxaa`, `Msaa2x`, `Msaa4x`, `Taa`. |
| `anisotropy` | `4` | 1, 2, 4, 8 or 16; other values snap to the nearest. |
| `vsync` | `true` | Forwarded to the window. |
| `fpsCap` | `0` | 0 = uncapped; 30, 60, 120 or 144. Any other value becomes 0. |
| `textureQuality` | `Full` | `Full`, `Half`, `Quarter` — a LOD bias so smaller mip levels are sampled. |
| `shaderQuality` | `Full` | `Full` or `Unlit` (every draw through the `unlit` shader). |
| `cloudShadows` | `true` | Cloud shadows on the ground. |
| `bloom` | `true` | Bloom in the post chain. |
| `hdr` | `true` | FP16 scene target; the source of the highlights. |
| `fog` | `true` | `SetFog` distance fog; off pushes it out of range. |
| `meshLod` | `High` | `High`, `Medium`, `Low` — read by geometry generators at world build; not enforced by the renderer. |
| `ambientOcclusion` | `Medium` | `ScreenSpaceAO`: `Off`, `Low`, `Medium`, `High`. |
| `colorGrading` | `Neutral` | `ColorGradingPreset`. |
| `vignetteIntensity` | `0.0` | 0 – 1. |
| `volumetricFog` | `false` | Shadowed light scattering along the view ray. |
| `froxelFog` | `false` | With `volumetricFog`: the fog light is computed once per frame in a 160 x 90 x 64 grid over the view instead of a march per pixel. Needs a backend that samples scene depth. |
| `ssr` | `Off` | `ScreenSpaceReflections`: `Off`, `Low`, `High`. |
| `fieldtracing` | `Off` | `FieldtracingMode`: `Off`, `ProbesOnly`, `SdfOcclusion`, `SdfBounce`. |
| `surfaceRelief` | `Parallax` | `Off`, `Cavity`, `Parallax`. |
| `upscaler` | `Off` | `Off`, `Fsr1`, `Taau`, `Fsr3`, `Dlss`. |
| `upscaleSharpness` | `0.9` | 0 = soft, 1 = strongest. |
| `upscaleQuality` | `Custom` | Render-scale preset of the upscaler; `Custom` leaves `renderScale` in charge. |
| `grassQuality` | `Medium` | `Off`, `Low`, `Medium`, `High`, `Ultra`, `Realistic`. |
| `shadingRate` | `Full` | `Full`, `Half` (2x2 blocks), `Quarter` (4x4) — variable rate shading of the 3D scene pass; ignored without the feature. |
| `foveatedShading` | `false` | Coarser shading at the screen edges (VRS Tier 2). |
| `lowLatency` | `false` | The CPU runs at most one frame ahead of presentation (waitable swapchain on D3D11 / D3D12). Applied when the window is created. |
| `hdrOutput` | `false` | HDR10 display output (D3D11 / D3D12) when the display is in HDR mode. Applied when the window is created. |
| `hdrPaperWhite` | `200.0` | Display white in nits for `hdrOutput`, 80 – 1000. |
| `depthPrepass` | `false` | Opaque surfaces write depth first, shading then runs once per pixel. |
| `skyCloudCache` | `false` | Raymarched clouds into a cube twice a second. |
| `temporalSsao` | `false` | SSAO accumulated over frames while the camera holds still. |
| `microShadows` | `false` | Direct light at a grazing angle does not reach into occluded cavities; sky light there comes from the cavity's opening. |
| `screenSpaceSss` | `false` | Screen-space subsurface scattering for materials with subsurface (MRT path). |
| `screenSpaceGi` | `false` | Screen-space radiosity: one bounce of the direct light between neighbouring pixels (MRT path). |
| `bakedLight` | `false` | Sky and sun visibility baked once from the SDF, looked up per pixel where a light volume was provided (`SetBakedLight`). |
| `rayTracedShadows`, `rayTracedReflections`, `rayTracedProbes`, `rayTracedGi` | `false` | Ray-traced sun shadows, reflections on smooth surfaces, probe-field update and one-bounce diffuse light. Need `VIO_FEATURE_RAY_QUERY`. |
| `meshShaders` | `false` | Instanced opaque batches as frustum-culled meshlets (`VIO_FEATURE_MESH_SHADER`). |
| `occlusionCulling` | `false` | Hi-Z culling of meshlets the depth prepass hides; needs `meshShaders` and `depthPrepass`. |
| `bindlessMaterials` | `false` | Albedo from the texture table, per-instance textures in one draw (`VIO_FEATURE_BINDLESS`). |
| `gpu` | `''` | GPU by name as `GpuAdapters::available()` lists it; `''` = automatic. Applied after a restart; a GPU that is gone falls back to automatic. |
| `effects` | `[]` | On/off state of a game's own effects by name (`effectEnabled($name)`); `with(effects: …)` merges name by name. |
| `accessibility` | defaults | `AccessibilitySettings`, see below. Saved only when it differs from the defaults. |

`$engine->graphics->capabilities()` says which of these the active renderer
can apply (see "Hardware capabilities" below).

### Accessibility (`AccessibilitySettings`)

A value object of its own inside `GraphicsSettings`; quality presets and the
adaptive controller never touch it.

| Field | Default | Meaning |
|---|---|---|
| `colorVision` | `ColorVisionMode::Off` | `Protanopia`, `Deuteranopia`, `Tritanopia`: the image is corrected for that deficiency. |
| `colorVisionStrength` | `1.0` | 0 – 1, how much of the correction is applied. |
| `colorVisionSimulate` | `false` | Developer view: show what a viewer with `colorVision` sees. |
| `worldSaturation` | `1.0` | 1 = unchanged, 0 = grey; the UI keeps its colours. |
| `contrast` | `0.0` | 0 – 1: steeper tone curve plus dark outlines along luminance edges. |
| `reducedMotion` | `false` | Holds or slows the shader time of registered effects. |
| `motionScale` | `0.25` | Speed of reduced effects while `reducedMotion` is on. |
| `flashLimit` | `false` | Photosensitivity: limits how fast the mean brightness of the 3D image may change. |
| `nightBoost` | `0.0` | 0 – 1: lifts the shadows of a dark image. |
| `comfortVignette` | `0.0` | 0 – 1: darkens the edge of the view while the camera turns or moves fast. |
| `focusDim` | `0.0` | 0 – 1: dims the 3D image outside the game's focus region (`SetFocusRegion`). |

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

## Tour benchmark (a game's own route)

`Quality\TourBenchmark` tunes the settings while the game drives through its
own world. It renders nothing itself: the game shows the current station,
applies `settings()` and feeds every rendered frame's time to `frame()`.

```php
use PHPolygon\Rendering\Quality\TourBenchmark;
use PHPolygon\Rendering\Quality\TourStation;

$tour = new TourBenchmark(
    stations: [
        new TourStation('Harbour', new Vec3(0.0, 2.0, 0.0), new Vec3(10.0, 2.0, -5.0)),
        new TourStation('Market', new Vec3(40.0, 3.0, 12.0), new Vec3(30.0, 2.0, 20.0)),
    ],
    targetFps: 60.0,
    start: $engine->graphics->settings(),
);
```

At each station the camera settles (`settleSeconds`, 0.5 by default), then
`measureFrames` (120) frame times are taken. When the frame time at
`percentile` (0.99, the "1 % lows") misses the budget `1000 / targetFps`, the
settings go one step down the `AdaptiveTierStack` and the station is measured
again. After the tour, when even the slowest station keeps `headroom` (20 %)
of the budget free, one step up is tried and checked on the slowest stations.
`station()`, `takeStationChange()`, `progress()`, `done()`, `abort()` and
`result()` (a `TourBenchmarkResult` with the final settings, the time per
station and the list of changes) drive the game's side.

---

## Choosing the GPU

`GraphicsSettings::$gpu` names the adapter to render on.
`GpuAdapters::available()` lists the hardware adapters php-vio reports (name,
type, VRAM; software rasterisers are left out), discrete cards first;
`GpuAdapters::isAvailable($name)` checks one. The choice is stored by name and
applied when the window opens, i.e. after a restart.

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
| Tour benchmark                         | `src/Rendering/Quality/TourBenchmark.php`, `TourStation.php`, `TourBenchmarkResult.php` |
| Hardware capabilities                  | `src/Rendering/Quality/GraphicsCapabilities.php` |
| Accessibility settings                 | `src/Rendering/Accessibility/AccessibilitySettings.php` |
| GPU adapters                           | `src/Rendering/GpuAdapters.php` |
| UI panel                               | `src/UI/GraphicsOptionsPanel.php` |
| Events                                 | `src/Event/Graphics*.php`, `QualityChangeRequest.php` |

---

## Pitfalls

- **Render scale is honoured by every backend.** MSAA is wired into
  OpenGL and Vio (multisample target + resolve); on Vio
  `capabilities()->msaa` reports whether the active php-vio backend has
  multisampled render targets. The standalone Vulkan renderer owns its
  own multisample image attachment and re-probes the supported sample
  count per context. MSAA may silently fall back to single-sample on
  backends / drivers that reject the requested sample count - a single
  STDERR diagnostic is logged once per process, but no error is raised.
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

Two contributions are layered:

- Per-fragment curvature-based ambient occlusion. Darkens corners and
  crevices via screen-space normal derivatives. Tiers map to a single
  `u_ao_strength` uniform: `Off=0.0`, `Low=0.4`, `Medium=0.7`, `High=1.0`
  (the default is `Medium`).
- At `Medium` and `High` the Vio renderer adds a depth + normal SSAO pass
  (G-buffer, hemisphere kernel, blur) on Direct3D 11 / 12, Vulkan and
  Metal. php-vio's OpenGL backend keeps the curvature term alone.

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

- **Shadowed:** each step samples the cascade shadow map at its point on the
  view ray (one tap, start offset jittered per pixel). Air the light does not
  reach - a room, the shadow of a building - scatters nothing; beyond the
  shadow distance the air counts as lit.
- **Per light:** `DirectionalLight::$volumetricScale` (0..1, default 1) scales
  what the primary light scatters, after the cap (`SetDirectionalLight::$volumetricScale`,
  shader `u_volumetric_light_dim` = 1 - scale). `DayNightSystem` blends it to
  `DayNightCycle::$moonScattering` (default 0.1) as the moon takes over: the
  moonlight stays bright enough to see by without washing the view out like
  the sun.

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

### Surface relief (`Material::$cavity`, `Material::$parallaxDepth`)

Every normal pattern also has a height field over the same pattern UV (1 = the surface, 0 = the deepest recess: mortar joints, cracks, dents). Two material values use it:

- `cavity` (0..1) darkens the recesses. Ambient light is scaled by the full factor, direct light by half. 1 leaves the recesses without ambient light.
- `parallaxDepth` (world units) runs parallax occlusion. The view ray descends that far below the surface; the pattern, the surface wear and the albedo texture are read where it meets the height field. It needs mesh UVs, and silhouettes stay flat.

```php
MaterialRegistry::register('cobbles', new Material(
    albedo: new Color(0.52, 0.50, 0.47),
    roughness: 0.9,
    normalPattern: NormalPattern::CRACKED,
    normalScale: 0.7,
    normalIntensity: 1.2,
    cavity: 0.6,
    parallaxDepth: 0.03,
));
```

`GraphicsSettings::$surfaceRelief` scales it per player: `Parallax` (default) applies both values, `Cavity` only the darkening, `Off` neither. Materials without these values render the same in every tier. The adaptive stack drops Parallax right after SSR, and Cavity after the vignette.

### Grass blades (`GraphicsSettings::$grassQuality`)

`GrassQuality` picks the parameter set (`GrassDetail`) a `GrassSystem` built with the
`GraphicsSettingsManager` uses: `Off` (ground material only), `Low`, `Medium` (default), `High`,
`Ultra` (stylised blades, more segments, density and reach per level) and `Realistic` (thin folded
blades, smooth gradient, light through the blades). The levels share one form language; only the
resolution changes. The setting is not part of the adaptive stack (changing it regenerates cells,
spread over frames: `GrassDetail::$generateBudgetMs` is shared by all fields).

### Upscaling (`GraphicsSettings::$upscaler`)

At a render scale below 1 the scene has to reach the display resolution:

| Upscaler | What happens |
|---|---|
| `Off` | The present pass stretches the image bilinearly. |
| `Fsr1` | AMD FidelityFX Super Resolution 1 (vio). The scene is resolved at render resolution (bloom, tonemap, grade, vignette, FXAA), upscaled with EASU and sharpened with RCAS. `$upscaleSharpness` sets the sharpening, 0 = soft, 1 = strongest. |
| `Taau` | The engine's own temporal resolve (`VioTaaPass`) from a jittered render with motion vectors; RCAS afterwards. Every vio backend with MRT + `VIO_FEATURE_RENDER_TARGET_DEPTH_SAMPLE`. |
| `Fsr3` | AMD FidelityFX Super Resolution 3.1 upscaling through php-vio's native module (D3D12 / Vulkan). FSR runs its own RCAS with `$upscaleSharpness`. |
| `Dlss` | NVIDIA DLSS Super Resolution through php-vio (D3D12 / Vulkan, RTX GPUs); `NativeAa` is DLAA. The engine's RCAS sharpens afterwards. |

FSR 1 is spatial, so it needs no motion vectors and runs on every vio backend. The temporal ones take a preset, `GraphicsSettings::$upscaleQuality`: `NativeAa` (1.0), `Quality` (1/1.5), `Balanced` (1/1.7), `Performance` (1/2), `UltraPerformance` (1/3) or `Custom` (the render-scale slider decides). With FSR 3 / DLSS a preset renders at the size the provider asks for (`vio_upscaler_render_size`; DLSS: NGX's optimal settings, e.g. Balanced 1114x626 at 1080p instead of 1129x635), with the provider's jitter cycle.

The native upscalers get the scene colour, the MRT depth, motion (`prevUv - uv`, handed over with `mv_scale` = render size, y sign by render-target orientation) and the reactive mask; the jitter goes over as render pixels with y down (`Quality\UpscalerDispatch` holds the conversion). The upscaler object is created at the frame boundary and recreated on a change of provider, preset, render / display size or HDR format. Bloom and present follow as for TAAU; FXAA never runs on top.

A provider that fails at runtime (`vio_upscaler_create` / `vio_upscaler_dispatch` returns false) is reported once on STDERR, drops out of the capabilities with the error as its note, and the selection walks down `Upscaler::fallbackChain()` (Dlss -> Fsr3 -> Taau -> Fsr1 -> Off). The stored setting stays. `PHPOLYGON_VIO_UPSCALER_FAIL=create|dispatch` forces such a failure for testing.

What the game provides for the native upscalers (a build does the first three from `build.json` `upscalers`, see [build-native-upscalers.md](build-native-upscalers.md)):
- the runtime libraries next to the executable (or in `EngineConfig::$upscalerRuntimePath`): `amd_fidelityfx_dx12.dll` / `amd_fidelityfx_vk.dll` (FidelityFX SDK 1.1.4, MIT) and for DLSS php-vio's plugin `vio_dlss.dll` plus `nvngx_dlss.dll` (DLSS SDK, NVIDIA RTX SDK licence; Linux `libvio_dlss.so` + `libnvidia-ngx-dlss.so.*`, untested);
- its own NGX project id, a random GUID: `upscalers.dlss.projectId` (baked into the executable's ini) or `new EngineConfig(dlssProjectId: '…')` (set as `vio.dlss_project_id` before the context is created, overrides the baked one);
- the licence notices (`THIRD-PARTY-NOTICES.txt`);
- for DLSS the NVIDIA attribution in its splash or credits, the notification to NVIDIA before release and EULA terms that protect NVIDIA's components.

### Hardware capabilities (`GraphicsCapabilities`)

`$engine->graphics->capabilities()` reports what the active renderer can apply:
- variable rate shading, HDR10 output and the waitable swapchain
- MSAA and a real TAA pass
- SSR and the SDF fieldtracing tiers
- surface relief and the upscalers that run here; `upscalerNote()` says why one does not (FSR 3 / DLSS: php-vio's `vio_upscaler_info()['reason']` - missing runtime library, not an RTX GPU, driver too old, software adapter ...)
- ray tracing (`$rayTracing`), mesh shaders (`$meshShaders`), Hi-Z occlusion culling (`$occlusionCulling`), bindless materials (`$bindless`) and the G-buffer passes (`$screenSpaceEffects`)

Asking for DLSS takes seconds the first time on a device, so the engine asks only when it has to: when DLSS is selected, and when a settings screen calls `capabilities()`. That call never blocks. While the answer is out, `isUpscalerPending(Upscaler::Dlss)` is true and DLSS is not offered; the answer lands in `<shaderCachePath>/upscaler-probe.json`, so the next process has it at once. Call `capabilities()` from the settings screen, not while starting up, and draw the note of a pending upscaler if you list upscalers yourself.

With a php-vio that has `vio_upscaler_prepare` nothing blocks. A stored DLSS starts on the next entry of the fallback chain, usually FSR 3, while the provider initialises on a worker thread, and takes over at the next cut, because the image changes there. Scene changes and camera cuts count by themselves; report a loading screen or a pause with `$engine->graphics->upscalerCutPoint()`. A game with one scene and no such call stays on the stand-in until the next scene. When the player picks DLSS in a settings screen, the note says it is preparing and the switch follows as soon as it is ready. If the provider fails, the game stays on the stand-in, and the note and `game.log` give the reason.

Without that function, or with a plugin that cannot do it, the query blocks once per device, driver and php-vio version for a settings screen (at the start of the next frame) and on every start of a game with a stored DLSS (behind the splash, because the provider initialises to run); its first frame then uses DLSS or, on a "no", the next entry of the chain.

`GraphicsOptionsPanel` draws unsupported options non-interactive and marks them "(not supported)". For a stored upscaler that does not run it shows the one that runs instead plus `GraphicsOptionsPanel::upscalerHint()`; temporal upscalers get the quality-preset dropdown, sharpness shows wherever a sharpening pass runs. Game menus should do the same. The stored settings stay untouched, so a graphics.json carried to a more capable machine keeps its choices. Without a renderer (headless, before the window) `GraphicsCapabilities::unknown()` disables nothing.

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

`ShadowMapRenderer::updateLightMatrix(direction, cameraTarget)` accepts
an optional camera centre. When supplied the shadow frustum follows the
camera and is texel-snapped to the shadow-map grid. Required for stable
open-world shadows. The Vio renderer centres its three shadow cascades
on the camera position of the frame.

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

On the **Vio renderer** the `Low` and `High` tiers run a ray-marched pass
against the G-buffer and the HDR scene colour on Direct3D 11 / 12, Vulkan
and Metal. With the tier `Off`, and on php-vio's OpenGL backend, the same
`u_ssr_intensity` uniform amplifies the wetness IBL contribution instead.

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

The Vio renderer resolves TAA with `VioTaaPass` (jittered projection,
motion vectors, reprojected and clipped history; the same pass upsamples
for `Upscaler::Taau`). It needs MRT plus
`VIO_FEATURE_RENDER_TARGET_DEPTH_SAMPLE`;
`capabilities()->temporalAntiAliasing` reports it. Where a renderer cannot
run it, `AntiAliasing::fallback()` maps `Taa -> Fxaa` rather than
blank-framing.

### Showcase

`examples/quality_showcase.php` exercises the mesh-shader items of this
section with live F1..F8 toggles. Use it as the visual reference when
modifying any mesh-shader path.
