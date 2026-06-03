<?php

declare(strict_types=1);

namespace PHPolygon\Event;

use PHPolygon\Rendering\Quality\PressureSignal;

/**
 * Emitted by the ThermalMonitor whenever the aggregated pressure signal
 * crosses a level boundary. UI / telemetry can use this to surface a
 * "performance mode active" indicator without polling.
 *
 * The source field identifies which underlying ThermalSourceInterface
 * triggered the change (e.g. "thermal_macos", "frametime_guard").
 */
final readonly class ThermalStateChanged
{
    public function __construct(
        public PressureSignal $previous,
        public PressureSignal $current,
        public string $source,
    ) {
    }
}
