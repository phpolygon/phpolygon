<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

use PHPolygon\Runtime\ThermalState;

/**
 * Reads a real hardware thermal level via php-vio's vio_thermal_state(), which
 * is cross-platform: macOS NSProcessInfo.thermalState (whole system), Linux
 * /sys/class/thermal zones (hottest of CPU/GPU/…), and Windows NVIDIA NVAPI GPU
 * temperature (with an ACPI-WMI fallback). On a platform/GPU without a readable
 * sensor every poll returns Unknown, so the ThermalMonitor falls back to the
 * frametime guard.
 *
 * The read can perform IPC / driver calls under the hood and reflects
 * whole-system heat, so we poll at ~1 Hz rather than every frame.
 */
final class ThermalSourceOs implements ThermalSourceInterface
{
    public const POLL_EVERY_N_FRAMES = 60;

    private int $framesSincePoll;
    private PressureSignal $cached = PressureSignal::Unknown;
    private ThermalState $lastState = ThermalState::Unknown;

    /** @var callable():ThermalState */
    private $reader;

    /**
     * @param (callable():ThermalState)|null $reader Injectable for tests.
     *                                                Defaults to ThermalState::fromVio.
     */
    public function __construct(
        ?callable $reader = null,
        int $pollEveryNFrames = self::POLL_EVERY_N_FRAMES,
    ) {
        $this->reader = $reader ?? static fn (): ThermalState => ThermalState::fromVio();
        $this->framesSincePoll = max(1, $pollEveryNFrames);
        // Negative so the first call triggers a poll without waiting.
        $this->framesSincePoll = $pollEveryNFrames;
    }

    public function name(): string
    {
        return 'thermal_os';
    }

    public function update(float $frameTimeMs, float $nowSeconds, float $currentTargetFps): PressureSignal
    {
        $this->framesSincePoll++;
        if ($this->framesSincePoll < self::POLL_EVERY_N_FRAMES) {
            return $this->cached;
        }
        $this->framesSincePoll = 0;
        $state = ($this->reader)();
        $this->lastState = $state;
        $this->cached = $state->toPressureSignal();
        return $this->cached;
    }

    public function lastState(): ThermalState
    {
        return $this->lastState;
    }
}
