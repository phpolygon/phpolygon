<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

use PHPolygon\Engine;
use PHPolygon\Event\QualityChangeRequest;
use PHPolygon\Rendering\GraphicsSettings;

/**
 * Hooks into the engine's per-frame update path and tunes individual
 * quality settings up or down to keep the rendered FPS within a
 * dead-band around the player's target.
 *
 * Activation:
 *   - Engine constructs one instance and exposes it as $engine->adaptiveQuality
 *   - The controller is only active when GraphicsSettings::$mode is Adaptive;
 *     in Manual or Off mode it observes frame times but does not change
 *     anything.
 *
 * Cost-impact stack (cheapest first - matches GraphicsAutoTuner):
 *   1. RenderScale     1.0 -> 0.5 in 0.1 steps
 *   2. ShadowQuality   High -> Off
 *   3. ViewDistance    200 -> 75 in coarse steps
 *   4. AntiAliasing    MSAA4x -> Off
 *   5. CloudShadows    on -> off
 *   6. Bloom           on -> off
 *   7. ShaderQuality   Full -> Unlit (last because it is most visible)
 *   8. Anisotropy      16 -> 1
 *
 * TextureQuality and MeshLodTier are intentionally NOT in the adaptive
 * stack - hot-swapping them requires re-uploading textures or
 * regenerating meshes, which is far more expensive than any single frame
 * is worth. Those settings stay where the player put them.
 *
 * Combat-aware: every adjustment is offered to listeners through a
 * QualityChangeRequest event before being applied. Listeners may call
 * veto() to block the change. The controller retries vetoed adjustments
 * after RETRY_AFTER_SECONDS so that a sudden "we just left combat"
 * situation can still benefit from the deferred upgrade.
 */
final class AdaptiveQualityController
{
    public const SAMPLE_BUFFER = 120;
    public const ADJUST_INTERVAL_S = 1.0;
    public const WARMUP_FRAMES = 60;
    public const RETRY_AFTER_SECONDS = 5.0;
    public const STABLE_UPGRADE_SECONDS = 5.0;

    /** @var list<float> Recent frame-time samples in ms (newest at the end) */
    private array $samples = [];
    private int $framesSinceWarmup = 0;
    private float $lastAdjustmentAt = 0.0;
    private float $stableSinceAt = 0.0;
    private float $vetoedUntil = 0.0;

    public function __construct(
        private readonly Engine $engine,
    ) {
    }

    /**
     * Reset frame history. Call from the engine when a new scene loads or
     * when a settings change occurs - the controller should not blame the
     * old scene's frame times for the new tier.
     */
    public function resetWarmup(): void
    {
        $this->samples = [];
        $this->framesSinceWarmup = 0;
        $this->stableSinceAt = 0.0;
    }

    /**
     * Hook called once per render frame by Engine. dt and the current frame
     * time are taken from the supplied frame-time-in-ms value so the
     * controller does not have to depend on PerfProfiler being active.
     */
    public function tick(float $frameTimeMs): void
    {
        $settings = $this->engine->graphics->settings();
        if ($settings->mode !== QualityMode::Adaptive) {
            // Still record samples so the F3 overlay sees them, but never
            // act on the data.
            $this->pushSample($frameTimeMs);
            return;
        }

        $this->pushSample($frameTimeMs);

        if ($this->framesSinceWarmup < self::WARMUP_FRAMES) {
            $this->framesSinceWarmup++;
            return;
        }

        $now = microtime(true);
        if ($now - $this->lastAdjustmentAt < self::ADJUST_INTERVAL_S) {
            return;
        }

        if ($this->vetoedUntil > $now) {
            return;
        }

        if (count($this->samples) < 30) {
            return;
        }

        $avgMs = array_sum($this->samples) / count($this->samples);
        $currentFps = $avgMs > 0.0 ? 1000.0 / $avgMs : 0.0;
        $target = $settings->targetFps;

        // Dead-band: 95-105% target -> nothing to do.
        $lower = $target * 0.95;
        $upper = $target * 1.10;

        if ($currentFps < $lower) {
            $this->stableSinceAt = 0.0;
            $this->attemptDowngrade($settings, sprintf('avg %.1f fps < %.0f * 0.95', $currentFps, $target));
            $this->lastAdjustmentAt = $now;
        } elseif ($currentFps > $upper) {
            if ($this->stableSinceAt === 0.0) {
                $this->stableSinceAt = $now;
            }
            if ($now - $this->stableSinceAt >= self::STABLE_UPGRADE_SECONDS) {
                $this->attemptUpgrade($settings, sprintf('avg %.1f fps > %.0f * 1.10', $currentFps, $target));
                $this->lastAdjustmentAt = $now;
                $this->stableSinceAt = $now;
            }
        } else {
            $this->stableSinceAt = 0.0;
        }
    }

    private function attemptDowngrade(GraphicsSettings $current, string $reason): void
    {
        $next = AdaptiveTierStack::downgrade($current);
        if ($next === null) {
            return;
        }
        $this->dispatchOrApply($current, $next, $reason);
    }

    private function attemptUpgrade(GraphicsSettings $current, string $reason): void
    {
        $next = AdaptiveTierStack::upgrade($current);
        if ($next === null) {
            return;
        }
        $this->dispatchOrApply($current, $next, $reason);
    }

    private function dispatchOrApply(GraphicsSettings $current, GraphicsSettings $next, string $reason): void
    {
        $request = new QualityChangeRequest(current: $current, proposed: $next, reason: $reason);
        $this->engine->events->dispatch($request);
        if ($request->isVetoed()) {
            $this->vetoedUntil = microtime(true) + self::RETRY_AFTER_SECONDS;
            return;
        }
        $this->engine->graphics->update(static fn(GraphicsSettings $s): GraphicsSettings => $next);
        $this->resetWarmup();
    }

    private function pushSample(float $ms): void
    {
        $this->samples[] = $ms;
        if (count($this->samples) > self::SAMPLE_BUFFER) {
            array_shift($this->samples);
        }
    }

    /**
     * @return list<float>
     */
    public function getSamples(): array
    {
        return $this->samples;
    }
}
