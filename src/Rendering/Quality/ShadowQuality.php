<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * Shadow map resolution tier. Off disables the shadow pass entirely.
 *
 * The numeric resolution() value drives ShadowMapRenderer's depth-texture
 * size. Doubling resolution quadruples the shadow-pass cost, so this is
 * one of the most impactful settings for low-end hardware.
 */
enum ShadowQuality: string
{
    case Off = 'off';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function resolution(): int
    {
        return match ($this) {
            self::Off => 0,
            self::Low => 1024,
            self::Medium => 2048,
            self::High => 4096,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::Low => 'Low (1024)',
            self::Medium => 'Medium (2048)',
            self::High => 'High (4096)',
        };
    }
}
