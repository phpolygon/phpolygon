<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

use PHPolygon\Engine;
use PHPolygon\Event\GraphicsCalibrationCompleted;
use PHPolygon\Event\GraphicsCalibrationProgress;
use PHPolygon\Event\GraphicsCalibrationStarted;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\GraphicsSettingsManager;
use PHPolygon\Scene\Scene;

/**
 * Auto-tunes graphics settings against a benchmark scene to hit the
 * player's target FPS with at least a 15% headroom.
 *
 * Algorithm (tier search):
 *   1. Load the benchmark scene at the highest tier (renderScale=1.0,
 *      shadows=High, full PBR, all post-effects on).
 *   2. Run WARMUP_FRAMES with no measurement, allowing JIT, class loading
 *      and GPU warm-up to settle.
 *   3. Sample MEASURE_FRAMES frames into a buffer; compute the p95
 *      frame time.
 *   4. If p95 frame time > (1000 / targetFps) * 0.85 (15% headroom)
 *      step the next setting in the cost-impact stack down. Repeat.
 *   5. If even the cheapest tier cannot meet the target, return the
 *      cheapest tier with a "metTarget=false" marker.
 *
 * The auto-tuner never upgrades during calibration; that is the
 * AdaptiveQualityController's job during normal play.
 */
final class GraphicsAutoTuner
{
    public const WARMUP_FRAMES = 30;
    public const MEASURE_FRAMES = 300;
    public const HEADROOM = 0.85;
    public const MAX_TIERS = 24;

    public function __construct(
        private readonly Engine $engine,
        private readonly GraphicsSettingsManager $manager,
    ) {
    }

    /**
     * Run the calibration. Returns the resulting BenchmarkResult and emits
     * GraphicsCalibrationStarted / Progress / Completed events along the way.
     */
    public function calibrate(float $targetFps, ?Scene $custom = null): BenchmarkResult
    {
        $events = $this->engine->events;
        $events->dispatch(new GraphicsCalibrationStarted($targetFps));

        $scene = $custom ?? $this->resolveDefaultBenchmarkScene();
        $sceneClass = get_class($scene);
        $previousActive = $this->engine->scenes->getActiveSceneName();

        // SceneManager::loadScene() expects a registered name. The auto-tuner
        // instantiates an ad-hoc benchmark scene that no game ever registers,
        // so we register it under its FQCN before loading. Without this the
        // load throws, the catch swallows it, and the calibration runs against
        // the live game scene — defeating the point of having a controlled
        // benchmark workload and risking crashes in renderer paths that the
        // real scene exercises but the benchmark would not.
        $benchmarkLoaded = false;
        try {
            if (!$this->engine->scenes->isRegistered($sceneClass)) {
                $this->engine->scenes->register($sceneClass, $sceneClass);
            }
            $this->engine->scenes->loadScene($sceneClass);
            $benchmarkLoaded = true;
        } catch (\Throwable $e) {
            // Some test environments do not support full scene loading;
            // we still measure against the live scene state.
        }

        $thresholdMs = (1000.0 / max(1.0, $targetFps)) * self::HEADROOM;
        $current = $this->manager->settings();

        /** @var list<array{label:string, p95Ms:float, settings:GraphicsSettings}> $history */
        $history = [];

        for ($tier = 0; $tier < self::MAX_TIERS; $tier++) {
            $events->dispatch(new GraphicsCalibrationProgress(
                ratio: min(1.0, $tier / 8.0),
                stage: $this->describeTier($current),
            ));

            $this->manager->update(static fn(GraphicsSettings $s): GraphicsSettings => $current);
            $p95 = $this->measureP95Ms();

            $history[] = [
                'label' => $this->describeTier($current),
                'p95Ms' => $p95,
                'settings' => $current,
            ];

            if ($p95 <= $thresholdMs) {
                break;
            }

            $next = AdaptiveTierStack::downgrade($current);
            if ($next === null) {
                break;
            }
            $current = $next;
        }

        // The loop always pushes at least once before any break / return.
        $finalP95 = $history[array_key_last($history)]['p95Ms'];
        $result = new BenchmarkResult(
            hardwareFingerprint: $this->manager->hardwareFingerprint(),
            targetFps: $targetFps,
            achievedP95Ms: $finalP95,
            finalSettings: $current,
            tierHistory: $history,
        );

        // Restore the scene the game had loaded before calibration. loadScene()
        // in Single mode unloads everything else, so without this step the
        // game-loop would start with the benchmark workload still active and
        // the original scene gone.
        if ($benchmarkLoaded
            && $previousActive !== null
            && $previousActive !== $sceneClass
            && $this->engine->scenes->isRegistered($previousActive)
        ) {
            try {
                $this->engine->scenes->loadScene($previousActive);
            } catch (\Throwable $e) {
                // Restore failures are non-fatal; the game can re-issue a
                // scene load itself if needed.
            }
        }

        $events->dispatch(new GraphicsCalibrationCompleted($result));
        return $result;
    }

    /** @var (callable():void)|null */
    private $frameRunner = null;

    /**
     * Override the per-frame driver used during measurement. The default
     * uses the engine's window + 2D/3D renderers to render one frame; the
     * first-launch flow injects its own driver because the engine is not
     * yet inside its main loop at that point.
     */
    public function setFrameRunner(?callable $runner): void
    {
        $this->frameRunner = $runner;
    }

    /**
     * Run WARMUP_FRAMES untimed frames followed by MEASURE_FRAMES timed
     * frames against the engine's tick path, returning the p95 frame time
     * in milliseconds. Falls back to a synthetic estimate when the engine
     * is in headless mode (no real GPU work to measure).
     */
    private function measureP95Ms(): float
    {
        // In headless mode there are no real frame times - estimate
        // analytically from the current settings so the auto-tuner can
        // still be exercised by tests.
        if ($this->engine->getConfig()->headless) {
            return $this->headlessP95Estimate();
        }

        $runFrame = $this->frameRunner ?? $this->buildDefaultFrameRunner();

        for ($i = 0; $i < self::WARMUP_FRAMES; $i++) {
            $runFrame();
        }

        $samples = [];
        for ($i = 0; $i < self::MEASURE_FRAMES; $i++) {
            $start = hrtime(true);
            $runFrame();
            $samples[] = (hrtime(true) - $start) / 1_000_000.0;
        }

        // self::MEASURE_FRAMES is always > 0 so $samples is never empty.
        sort($samples);
        $idx = (int)floor(count($samples) * 0.95);
        $idx = max(0, min(count($samples) - 1, $idx));
        return $samples[$idx];
    }

    /**
     * @return callable():void
     */
    private function buildDefaultFrameRunner(): callable
    {
        $engine = $this->engine;
        return function () use ($engine): void {
            $engine->world->update(1.0 / 60.0);
            if ($engine->renderer3D !== null) {
                $engine->renderer3D->beginFrame();
            }
            $engine->renderer2D->beginFrame();
            $engine->world->render();
            if ($engine->renderer3D !== null) {
                $engine->renderer3D->endFrame();
            }
            $engine->renderer2D->endFrame();
            $engine->window->swapBuffers();
            $engine->window->pollEvents();
        };
    }

    /**
     * Synthetic frame-time estimate used when no GPU is available. Each
     * setting contributes a fixed cost so the tier search still produces
     * sensible behaviour in unit tests.
     */
    private function headlessP95Estimate(): float
    {
        $s = $this->manager->settings();
        $base = 8.0;
        $base += $s->renderScale * $s->renderScale * 6.0;
        $base += match ($s->shadowQuality) {
            ShadowQuality::Off => 0.0,
            ShadowQuality::Low => 1.0,
            ShadowQuality::Medium => 3.0,
            ShadowQuality::High => 7.0,
        };
        $base += $s->viewDistance / 100.0;
        $base += match ($s->antiAliasing) {
            AntiAliasing::Off => 0.0,
            AntiAliasing::Fxaa => 0.5,
            AntiAliasing::Msaa2x => 2.0,
            AntiAliasing::Msaa4x => 4.0,
            // TAA: composite + history copy + jitter overhead, sits between
            // FXAA (single fullscreen sample loop) and MSAA2x (2x render-target).
            AntiAliasing::Taa => 0.7,
        };
        $base += $s->cloudShadows ? 1.5 : 0.0;
        $base += $s->bloom ? 1.0 : 0.0;
        $base += $s->shaderQuality === ShaderQuality::Full ? 2.5 : 0.0;
        $base += match ($s->anisotropy) {
            16 => 0.6, 8 => 0.4, 4 => 0.2, 2 => 0.1, default => 0.0,
        };
        return $base;
    }

    private function describeTier(GraphicsSettings $s): string
    {
        return sprintf(
            'rs=%.1f shadow=%s view=%.0f aa=%s clouds=%s bloom=%s shader=%s aniso=%d',
            $s->renderScale,
            $s->shadowQuality->value,
            $s->viewDistance,
            $s->antiAliasing->value,
            $s->cloudShadows ? 'on' : 'off',
            $s->bloom ? 'on' : 'off',
            $s->shaderQuality->value,
            $s->anisotropy,
        );
    }

    private function resolveDefaultBenchmarkScene(): Scene
    {
        $configured = $this->engine->getConfig()->benchmarkScene;
        if ($configured !== null && class_exists($configured)) {
            /** @var class-string<Scene> $configured */
            return new $configured();
        }
        return new BenchmarkScene();
    }
}
