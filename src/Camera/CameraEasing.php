<?php

declare(strict_types=1);

namespace PHPolygon\Camera;

/** Time warp of a flight: maps linear progress 0..1 to path progress 0..1. */
enum CameraEasing: string
{
    /** Constant speed along the path. */
    case Linear = 'linear';
    /** Zero speed at both ends, 3t^2 - 2t^3. */
    case Smoothstep = 'smoothstep';
    /** Zero speed at both ends, gentler middle (quartic in/out). */
    case EaseInOut = 'ease_in_out';

    public function apply(float $t): float
    {
        $t = max(0.0, min(1.0, $t));
        return match ($this) {
            self::Linear => $t,
            self::Smoothstep => $t * $t * (3.0 - 2.0 * $t),
            self::EaseInOut => $t < 0.5
                ? 8.0 * $t * $t * $t * $t
                : 1.0 - 8.0 * ($t - 1.0) ** 4,
        };
    }
}
