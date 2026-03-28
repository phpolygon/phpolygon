<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Material definition for 3D rendering.
 * Phase 7: color-based (albedo, roughness, metallic, emission).
 * Phase 8+: texture support will be added via optional texture IDs.
 */
class Material
{
    public function __construct(
        public readonly Color $albedo = new Color(0.8, 0.8, 0.8),
        public readonly float $roughness = 0.5,
        public readonly float $metallic = 0.0,
        public readonly Color $emission = new Color(0.0, 0.0, 0.0),
        public readonly float $alpha = 1.0,
    ) {}

    public static function default(): self
    {
        return new self();
    }

    public static function color(Color $albedo): self
    {
        return new self(albedo: $albedo);
    }

    public static function emissive(Color $albedo, Color $emission): self
    {
        return new self(albedo: $albedo, emission: $emission);
    }
}
