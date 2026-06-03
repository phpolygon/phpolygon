<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * Discrete targetFps steps used by the ThermalMonitor when ramping up or
 * down in response to thermal pressure.
 *
 * Steps follow common display-refresh values so the user lands on a sane
 * number after each transition. The ladder is stateless - callers pass the
 * current value plus a floor (down) or ceiling (up).
 */
final class TargetFpsLadder
{
    /** @var list<float> Sorted ascending. */
    public const STEPS = [30.0, 45.0, 50.0, 60.0, 75.0, 90.0, 120.0, 144.0];

    /**
     * Return the highest ladder step that is <= floor, jumping down from
     * current. If current is already at or below floor, returns current
     * unchanged - we don't ratchet further down than the pressure asks for.
     */
    public static function stepDownTo(float $current, float $floor): float
    {
        if ($current <= $floor + 0.5) {
            return $current;
        }
        for ($i = count(self::STEPS) - 1; $i >= 0; $i--) {
            if (self::STEPS[$i] <= $floor + 0.5) {
                return self::STEPS[$i];
            }
        }
        return self::STEPS[0];
    }

    /**
     * Return the next ladder step above current, capped at ceiling.
     * If current already meets or exceeds the ceiling, returns ceiling.
     */
    public static function stepUp(float $current, float $ceiling): float
    {
        foreach (self::STEPS as $s) {
            if ($s > $current + 0.5) {
                return min($s, $ceiling);
            }
        }
        return min($current, $ceiling);
    }
}
