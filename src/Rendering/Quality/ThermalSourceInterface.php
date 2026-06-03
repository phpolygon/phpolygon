<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * One signal source feeding the ThermalMonitor. Sources are polled once
 * per frame; throttling expensive operations (OS API calls, percentile
 * computation) is the source's responsibility.
 */
interface ThermalSourceInterface
{
    /**
     * Stable identifier used in events and dev logs ("thermal_macos",
     * "frametime_guard"). Lower-case snake_case.
     */
    public function name(): string;

    /**
     * Returns the current pressure level. Called once per frame from
     * ThermalMonitor::tick(). May return Unknown when the source isn't
     * usable on this platform.
     */
    public function update(float $frameTimeMs, float $nowSeconds, float $currentTargetFps): PressureSignal;
}
