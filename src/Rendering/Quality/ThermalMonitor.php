<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

use PHPolygon\Engine;
use PHPolygon\Event\ThermalStateChanged;
use PHPolygon\Runtime\DevLogger;
use PHPolygon\Runtime\HardwareProfile;

/**
 * Multi-source thermal pressure aggregator. Hooked into Engine::endFrameStats
 * so it sees the same per-frame timing the AdaptiveQualityController sees.
 *
 * Sources contribute a PressureSignal each frame; the monitor picks the
 * worst (highest level) and maps it to a targetFps step on TargetFpsLadder.
 *
 *   Pressure -> floor targetFps (mapped via stepDownTo):
 *     Fair      -> 50
 *     Serious   -> 45
 *     Critical  -> 30
 *
 * Ramp-down is instant. Ramp-up is gated by RAMP_UP_HOLD_TIME_S of sustained
 * Nominal so we don't bounce between steps under marginal load. After AQC
 * has just downgraded quality, recovery is blocked for AQC_DOWNGRADE_LOCK_S
 * to let the quality knob actually take effect before we add FPS pressure.
 *
 * The ceiling from HardwareProfile is the upper bound of ramp-up - we never
 * raise targetFps above what the hardware is known to handle without
 * throttling on this profile (null = no ceiling, full settings respected).
 */
final class ThermalMonitor
{
    public const RAMP_UP_HOLD_TIME_S  = 30.0;
    public const AQC_DOWNGRADE_LOCK_S = 10.0;
    public const SOFT_CEILING_DEFAULT = 240.0;

    private float $allClearSince = 0.0;
    private PressureSignal $lastAggregated = PressureSignal::Nominal;
    private string $lastTriggerSource = '';
    private float $ceiling;

    /**
     * @param list<ThermalSourceInterface> $sources
     */
    public function __construct(
        private readonly Engine $engine,
        HardwareProfile $profile,
        private readonly array $sources,
        private readonly ?DevLogger $log = null,
    ) {
        $this->ceiling = $profile->targetFpsCeiling() ?? self::SOFT_CEILING_DEFAULT;
    }

    /**
     * Called once per render frame from Engine::endFrameStats. $frameTimeMs
     * is the wall-clock time the previous render frame took.
     */
    public function tick(float $frameTimeMs): void
    {
        if (!$this->engine->getConfig()->autoThermalManagement) {
            return;
        }

        $now = microtime(true);
        $current = $this->engine->graphics->settings()->targetFps;

        $aggregated = PressureSignal::Nominal;
        $winning = '';
        foreach ($this->sources as $src) {
            $signal = $src->update($frameTimeMs, $now, $current);
            if ($signal->level() > $aggregated->level()) {
                $aggregated = $signal;
                $winning = $src->name();
            }
        }

        if ($aggregated !== $this->lastAggregated) {
            $this->log?->logStateChange($this->lastAggregated, $aggregated, $winning);
            $this->engine->events->dispatch(new ThermalStateChanged(
                previous: $this->lastAggregated,
                current:  $aggregated,
                source:   $winning,
            ));
            $this->lastAggregated = $aggregated;
            $this->lastTriggerSource = $winning;
        }

        $floor = self::pressureFloor($aggregated);
        if ($floor !== null) {
            $desired = TargetFpsLadder::stepDownTo($current, $floor);
            if ($desired < $current - 0.5) {
                $this->apply($current, $desired, $winning, 'pressure=' . $aggregated->value);
            }
            $this->allClearSince = 0.0;
            return;
        }

        // Recovery path
        if ($current >= $this->ceiling - 0.5) {
            $this->allClearSince = 0.0;
            return;
        }
        if ($this->aqcRecentlyDowngraded($now)) {
            $this->allClearSince = 0.0;
            return;
        }
        if ($this->allClearSince === 0.0) {
            $this->allClearSince = $now;
            return;
        }
        if ($now - $this->allClearSince < self::RAMP_UP_HOLD_TIME_S) {
            return;
        }
        $next = TargetFpsLadder::stepUp($current, $this->ceiling);
        if ($next > $current + 0.5) {
            $this->apply($current, $next, 'recovery', 'all_clear_' . (int) self::RAMP_UP_HOLD_TIME_S . 's');
            // Reset so the next step also waits the full hold time.
            $this->allClearSince = $now;
        }
    }

    public function currentPressure(): PressureSignal
    {
        return $this->lastAggregated;
    }

    public function lastTriggerSource(): string
    {
        return $this->lastTriggerSource;
    }

    public function ceiling(): float
    {
        return $this->ceiling;
    }

    /**
     * @return list<ThermalSourceInterface>
     */
    public function sources(): array
    {
        return $this->sources;
    }

    private function apply(float $from, float $to, string $source, string $reason): void
    {
        $this->engine->graphics->setRuntimeTargetFps($to, $source, $reason);
        $this->log?->logTargetFpsChange($from, $to, $source, $reason);
        // AQC must not blame the FPS drop on its own quality changes.
        $this->engine->adaptiveQuality?->resetWarmup();
    }

    private function aqcRecentlyDowngraded(float $now): bool
    {
        $aqc = $this->engine->adaptiveQuality;
        if ($aqc === null) {
            return false;
        }
        if ($this->engine->graphics->settings()->mode !== QualityMode::Adaptive) {
            return false;
        }
        $last = $aqc->lastDowngradeAt();
        if ($last === 0.0) {
            return false;
        }
        return ($now - $last) < self::AQC_DOWNGRADE_LOCK_S;
    }

    private static function pressureFloor(PressureSignal $signal): ?float
    {
        return match ($signal) {
            PressureSignal::Critical => 30.0,
            PressureSignal::Serious  => 45.0,
            PressureSignal::Fair     => 50.0,
            PressureSignal::Nominal,
            PressureSignal::Unknown  => null,
        };
    }
}
