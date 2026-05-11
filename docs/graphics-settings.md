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

- **Render scale and MSAA are tracked but not yet honoured by the GL
  pipeline.** The off-screen multisample FBO that implements them is
  Phase 1.5 work. Settings round-trip correctly today and the auto-tuner
  steps through them, but pixel output is unaffected. This is documented
  with TODOs in `OpenGLRenderer3D::applySettings()` and the equivalents
  in the other backends.
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
