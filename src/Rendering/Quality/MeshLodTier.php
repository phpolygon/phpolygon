<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * Mesh level-of-detail tier. Procedural meshes can read this value and
 * generate fewer subdivisions / segments / vertices when the player has
 * picked a lower tier.
 *
 * The tier itself is not enforced by the renderer - it is exposed via
 * GraphicsSettings so geometry generators (BoxMesh, SphereMesh, etc.) can
 * branch on it during world build. Hot-swapping is intentionally not
 * supported because mesh regeneration is too expensive for per-frame
 * adaptation; AdaptiveQualityController therefore excludes this setting.
 */
enum MeshLodTier: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function multiplier(): float
    {
        return match ($this) {
            self::High => 1.0,
            self::Medium => 0.66,
            self::Low => 0.4,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::High => 'High',
            self::Medium => 'Medium',
            self::Low => 'Low',
        };
    }
}
