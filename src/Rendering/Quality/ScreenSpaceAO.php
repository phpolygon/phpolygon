<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * Screen-space ambient-occlusion quality tier.
 *
 * The current implementation uses a per-fragment curvature approximation
 * (analytic AO derived from screen-space derivatives of the surface
 * normal). This is cheap, requires no extra G-buffer pass, and produces
 * the characteristic darkening in concave regions / corner crevices that
 * gives "geometry" weight to PBR scenes.
 *
 * Tiers map to a single shader uniform u_ao_strength multiplied into the
 * ambient term:
 *
 *   Off    -> 0.0   (no AO)
 *   Low    -> 0.4   (subtle, cheap)
 *   Medium -> 0.7   (default - matches "looks grounded" target)
 *   High   -> 1.0   (full strength, may darken thin geometry)
 *
 * Future depth-buffer SSAO can replace the in-shader curvature path
 * without changing this enum or the uniform name.
 */
enum ScreenSpaceAO: string
{
    case Off    = 'off';
    case Low    = 'low';
    case Medium = 'medium';
    case High   = 'high';

    public function strength(): float
    {
        return match ($this) {
            self::Off    => 0.0,
            self::Low    => 0.4,
            self::Medium => 0.7,
            self::High   => 1.0,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Off    => 'Off',
            self::Low    => 'Low',
            self::Medium => 'Medium',
            self::High   => 'High',
        };
    }
}
