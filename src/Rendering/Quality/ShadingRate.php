<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * Variable rate shading tier (php-vio >= 2.19, VIO_FEATURE_SHADING_RATE): how
 * many pixels one fragment-shader invocation covers during the 3D scene pass.
 * Geometry, depth and the output resolution stay untouched, so it is the
 * cheapest performance step in the adaptive stack - a quarter (Half) or a
 * sixteenth (Quarter) of the pixel work without the blur of a lower render
 * scale. UI and post-processing always run at full rate. Ignored on backends
 * without the feature.
 */
enum ShadingRate: string
{
    case Full = 'full';
    case Half = 'half';
    case Quarter = 'quarter';

    /** The php-vio VIO_SHADING_RATE_* value (1X1 = 0, 2X2 = 3, 4X4 = 4). */
    public function vioConstant(): int
    {
        return match ($this) {
            self::Full => 0,
            self::Half => 3,
            self::Quarter => 4,
        };
    }

    /** Fragment invocations relative to full rate. */
    public function pixelWorkFraction(): float
    {
        return match ($this) {
            self::Full => 1.0,
            self::Half => 0.25,
            self::Quarter => 0.0625,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full (1x1)',
            self::Half => 'Half (2x2)',
            self::Quarter => 'Quarter (4x4)',
        };
    }
}
