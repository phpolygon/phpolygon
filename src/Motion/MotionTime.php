<?php

declare(strict_types=1);

namespace PHPolygon\Motion;

use PHPolygon\Rendering\Effects\EffectRegistry;
use PHPolygon\Rendering\Effects\ReducedMotion;

/**
 * How fast a CPU-driven motion runs under the player's reduced-motion choice. The
 * motion names its entry in the {@see EffectRegistry} (the same register the shader
 * effects use) and follows it: `Reduced` runs at the player's motion scale, `Frozen`
 * and `Off` stand still, `Static` and a motion without an entry keep full speed.
 * With reduced motion off every motion runs at 1, so nothing changes for a player
 * who did not ask for it.
 */
final class MotionTime
{
    private function __construct() {}

    /** Time factor of a motion registered as $effect (null = no entry). */
    public static function scale(?string $effect): float
    {
        if ($effect === null || $effect === '' || !EffectRegistry::reducedMotion()) {
            return 1.0;
        }
        $definition = EffectRegistry::get($effect);
        return match ($definition?->reducedMotion) {
            ReducedMotion::Frozen, ReducedMotion::Off => 0.0,
            ReducedMotion::Reduced => EffectRegistry::motionScale($definition),
            default => 1.0,
        };
    }

    /** Whether the player asked for reduced motion (ramps stretch by the motion's own factor). */
    public static function reduced(): bool
    {
        return EffectRegistry::reducedMotion();
    }

    /**
     * One step of a drive level toward 0 or 1. Linear in the level, $rampUp / $rampDown
     * seconds for a full swing (0 = at once); feed {@see ease()} of the result into speeds.
     */
    public static function drive(float $level, bool $on, float $rampUp, float $rampDown, float $dt): float
    {
        if ($on) {
            return $rampUp <= 0.0 ? 1.0 : min(1.0, $level + $dt / $rampUp);
        }
        return $rampDown <= 0.0 ? 0.0 : max(0.0, $level - $dt / $rampDown);
    }

    /** Smoothstep of a drive level: speed starts and stops without a jerk. */
    public static function ease(float $level): float
    {
        $t = max(0.0, min(1.0, $level));
        return $t * $t * (3.0 - 2.0 * $t);
    }
}
