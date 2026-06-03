<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

use PHPolygon\Runtime\ThermalState;

/**
 * Reads NSProcessInfo.thermalState via php-vio's vio_thermal_state() helper.
 * macOS-only - on any other platform (and when the vio build doesn't ship
 * the helper) every poll returns Unknown so the ThermalMonitor falls back
 * to the frametime guard.
 *
 * The OS API performs IPC under the hood and reflects whole-system heat
 * including other apps, so we poll at ~1 Hz rather than every frame.
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
        return 'thermal_macos';
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
