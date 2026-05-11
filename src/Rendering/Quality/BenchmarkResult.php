<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

use PHPolygon\Rendering\GraphicsSettings;

/**
 * Result of a GraphicsAutoTuner calibration run.
 *
 * - $hardwareFingerprint identifies the GPU/CPU combination the run was
 *   produced for. Stored alongside the chosen settings so that hardware
 *   swaps can prompt for re-calibration.
 * - $tierHistory captures every tier the auto-tuner stepped through, with
 *   the p95 frame time it observed. Useful for diagnostics overlays.
 *
 * @phpstan-type TierEntry array{label:string, p95Ms:float, settings:GraphicsSettings}
 */
final readonly class BenchmarkResult
{
    /**
     * @param list<array{label:string, p95Ms:float, settings:GraphicsSettings}> $tierHistory
     */
    public function __construct(
        public string $hardwareFingerprint,
        public float $targetFps,
        public float $achievedP95Ms,
        public GraphicsSettings $finalSettings,
        public array $tierHistory,
    ) {
    }

    public function achievedFps(): float
    {
        return $this->achievedP95Ms > 0.0 ? 1000.0 / $this->achievedP95Ms : 0.0;
    }

    public function metTarget(): bool
    {
        return $this->achievedFps() >= $this->targetFps * 0.95;
    }
}
