<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

use PHPolygon\Runtime\DevLogger;

/**
 * Frametime-based pressure source — a THERMAL FALLBACK for platforms with no
 * real temperature sensor. Frame time is only a proxy for heat: slow frames can
 * mean a hot/throttling chip OR simply a heavy scene on a cool machine, and this
 * source cannot tell them apart. So when a real sensor is available (a
 * ThermalSourceOs reading vio_thermal_state), THAT is authoritative for thermal
 * throttling and this source must run in ADVISORY mode ($contributesPressure =
 * false): it still tracks p95 + logs what it would have flagged (useful in the
 * dev log), but always reports Nominal to the monitor so a busy-but-cool scene
 * never raises a false thermal alarm. Adaptive quality reacts to frame time
 * independently (AdaptiveQualityController), so advisory mode loses nothing.
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

    public function __construct(
        int $warmupFrames = self::WARMUP_FRAMES,
        private readonly ?DevLogger $log = null,
        private readonly bool $contributesPressure = true,
    ) {
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
        $prev = $this->current;

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

        // Dev mode (--dev / --dev-monitor): surface every signal change with the
        // numbers behind it, including the WORST single sample in the window. In
        // advisory mode the line is tagged so it's clear it did NOT throttle (the
        // real sensor is authoritative) — a busy-but-cool scene shows here without
        // raising a thermal alarm.
        if ($this->current !== $prev) {
            $this->log?->logMessage(sprintf(
                '[frametime-guard%s] %s -> %s  p95=%.1fms budget=%.1fms ratio=%.2f thisFrame=%.1fms max=%.1fms targetFps=%.0f samples=%d',
                $this->contributesPressure ? '' : ' advisory',
                $prev->value, $this->current->value, $p95, $budgetMs, $p95 / max(0.001, $budgetMs),
                $frameTimeMs, max($this->samples), $currentTargetFps, count($this->samples),
            ));
        }

        // Advisory mode (a real thermal sensor is present): never drive thermal
        // throttling from frame time — report Nominal regardless of what the
        // proxy computed above. The diagnostic log still fired.
        return $this->contributesPressure ? $this->current : PressureSignal::Nominal;
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
