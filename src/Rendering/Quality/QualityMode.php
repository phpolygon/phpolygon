<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * Top-level quality strategy chosen by the player.
 *
 * - Manual:   Settings are edited explicitly in the options panel and never
 *             changed automatically by the engine.
 * - Adaptive: The AdaptiveQualityController watches frame times and tunes
 *             individual settings up or down to hit the target FPS.
 * - Off:      No automatic adjustments, no first-launch calibration. Whatever
 *             the GraphicsSettings object holds is what gets rendered.
 */
enum QualityMode: string
{
    case Manual = 'manual';
    case Adaptive = 'adaptive';
    case Off = 'off';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Adaptive => 'Adaptive',
            self::Off => 'Off',
        };
    }
}
