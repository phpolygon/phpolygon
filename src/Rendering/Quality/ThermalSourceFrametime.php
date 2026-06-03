<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * Universal pressure source that watches the engine's own frametime. Works
 * on every platform - no OS API needed - so it complements the macOS-only
 * NSProcessInfo source.
 *
 * Compares the rolling p95 of the last WINDOW_FRAMES samples against the
 * theoretical frametime budget (1000 / targetFps):
 *
 *   p95 > budget * TRIGGER_OVER_BUDGET, sustained >= SUSTAIN_SECONDS_DOWN
 *     -> Fair / Serious / Critical (graded by overshoot ratio)
 *
 *   p95 < budget * RECOVER_UNDER_BUDGET, sustained >= SUSTAIN_SECONDS_UP
 *     -> Nominal
 *
 * Hysteresis is asymmetric: ramp-down faster than ramp-up so we don't
 * pump fps levels back and forth around the trigger band.
 */
final class ThermalSourceFrametime implements ThermalSourceInterface
{
    public const WINDOW_FRAMES        = 600;   // ~10s at 60 fps
    public const TRIGGER_OVER_BUDGET  = 1.20;  // p95 > budget * 1.20 -> pressure
    public const RECOVER_UNDER_BUDGET = 0.90;  // p95 < budget * 0.90 -> recovery
    public const SUSTAIN_SECONDS_DOWN = 3.0;
    public const SUSTAIN_SECONDS_UP   = 8.0;
    public const WARMUP_FRAMES        = 120;   // ~2s, skips splash/calibration tail

    /** @var list<float> Frame times in ms (newest at the end). */
    private array $samples = [];
    private int $warmupRemaining;
    private float $sustainedOverSince = 0.0;
    private float $sustainedUnderSince = 0.0;
    private PressureSignal $current = PressureSignal::Nominal;
    private float $lastP95Ms = 0.0;

    public function __construct(int $warmupFrames = self::WARMUP_FRAMES)
    {
        $this->warmupRemaining = max(0, $warmupFrames);
    }

    public function name(): string
    {
        return 'frametime_guard';
    }

    public function update(float $frameTimeMs, float $nowSeconds, float $currentTargetFps): PressureSignal
    {
        if ($this->warmupRemaining > 0) {
            $this->warmupRemaining--;
            return PressureSignal::Nominal;
        }

        $this->samples[] = $frameTimeMs;
        if (count($this->samples) > self::WINDOW_FRAMES) {
            array_shift($this->samples);
        }
        if (count($this->samples) < 60) {
            return PressureSignal::Nominal;
        }

        $p95 = self::percentile($this->samples, 0.95);
        $this->lastP95Ms = $p95;
        $budgetMs = 1000.0 / max(1.0, $currentTargetFps);

        if ($p95 > $budgetMs * self::TRIGGER_OVER_BUDGET) {
            $this->sustainedUnderSince = 0.0;
            if ($this->sustainedOverSince === 0.0) {
                $this->sustainedOverSince = $nowSeconds;
            }
            if ($nowSeconds - $this->sustainedOverSince >= self::SUSTAIN_SECONDS_DOWN) {
                $ratio = $p95 / $budgetMs;
                $this->current = $ratio > 2.0
                    ? PressureSignal::Critical
                    : ($ratio > 1.5 ? PressureSignal::Serious : PressureSignal::Fair);
            }
        } elseif ($p95 < $budgetMs * self::RECOVER_UNDER_BUDGET) {
            $this->sustainedOverSince = 0.0;
            if ($this->sustainedUnderSince === 0.0) {
                $this->sustainedUnderSince = $nowSeconds;
            }
            if ($nowSeconds - $this->sustainedUnderSince >= self::SUSTAIN_SECONDS_UP) {
                $this->current = PressureSignal::Nominal;
            }
        } else {
            $this->sustainedOverSince = 0.0;
            $this->sustainedUnderSince = 0.0;
        }

        return $this->current;
    }

    public function lastP95Ms(): float
    {
        return $this->lastP95Ms;
    }

    public function sampleCount(): int
    {
        return count($this->samples);
    }

    /**
     * @param list<float> $values
     */
    private static function percentile(array $values, float $q): float
    {
        sort($values);
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }
        $idx = (int) floor($q * ($count - 1));
        return $values[$idx];
    }
}
