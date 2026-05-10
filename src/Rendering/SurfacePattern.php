<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Procedural surface-wear patterns. Counterpart to {@see NormalPattern}
 * but for the ORM (occlusion / roughness / metallic) channels - lets a
 * single material vary its PBR properties across its surface without
 * shipping ORM texture maps.
 *
 * Each pattern is evaluated in the fragment shader and produces three
 * deltas applied on top of the material's base values:
 *
 *   - albedoTint: per-fragment tint multiplied into the base albedo
 *   - roughnessDelta: added to base roughness (clamped to [0, 1])
 *   - metallicDelta: added to base metallic (clamped to [0, 1])
 *
 * Add a new surface pattern:
 *   1. Add a const + bump the codeFor() switch.
 *   2. Implement sp_<name>(vec2 uv) in mesh3d.frag.glsl AND the embedded
 *      Vio shader. Both must return the same vec3 delta.
 *   3. Wire the new code into the dispatchSurfacePattern() switch in
 *      both shader copies (and in mesh3d.metal).
 */
final class SurfacePattern
{
    public const NONE            = null;
    public const WORN_PAINT      = 'worn_paint';
    public const RUST            = 'rust';
    public const BRUSHED_METAL   = 'brushed_metal';
    public const POLISHED_RINGS  = 'polished_rings';

    public static function codeFor(?string $pattern): int
    {
        return match ($pattern) {
            self::WORN_PAINT     => 1,
            self::RUST           => 2,
            self::BRUSHED_METAL  => 3,
            self::POLISHED_RINGS => 4,
            default              => 0,
        };
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::WORN_PAINT,
            self::RUST,
            self::BRUSHED_METAL,
            self::POLISHED_RINGS,
        ];
    }
}
