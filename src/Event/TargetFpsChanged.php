<?php

declare(strict_types=1);

namespace PHPolygon\Event;

/**
 * Emitted whenever the engine adjusts the active targetFps - either because
 * the user changed the setting (source = "user"), the ThermalMonitor
 * reacted to pressure (source = "thermal_macos" / "frametime_guard"), or
 * a ramp-up after recovery (source = "recovery").
 *
 * The previous/current values are the GraphicsSettings::$targetFps fields
 * before and after the change.
 */
final readonly class TargetFpsChanged
{
    public function __construct(
        public float $previous,
        public float $current,
        public string $source,
        public string $reason,
    ) {
    }
}
