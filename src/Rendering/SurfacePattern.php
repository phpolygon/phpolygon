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
    /**
     * Skin freckle / pigmentation pattern. Blotchy large-scale variation
     * gates a smaller-scale freckle field; output darkens albedo (~30 %)
     * and adds a touch of roughness where freckles appear. Pairs naturally
     * with {@see NormalPattern::SKIN}.
     */
    public const SKIN            = 'skin';
    /** Veined marble: soft veins through a pale stone (shader plan 5.5). */
    public const MARBLE          = 'marble';
    /** Sandstone: fine grain and horizontal bedding layers. */
    public const SANDSTONE       = 'sandstone';
    /**
     * Soft mottling of the albedo only (about +-8 % at intensity 1, blotches of a
     * metre or so at surfaceScale 1): no roughness or metal change. For concrete,
     * plaster and paint.
     */
    public const MOTTLE          = 'mottle';
    /**
     * WORN_PAINT without the hard step: chips fade in (smoothstep), the underlay is
     * only 15 % darker and roughness / metal deltas stop at 0.1, so edge wear reads
     * as scuffing instead of camouflage.
     */
    public const WORN_PAINT_SOFT = 'worn_paint_soft';
    /**
     * Per-plank tone and roughness of NormalPattern::WOOD_PLANKS (+-11 % albedo per
     * plank, a slow streak along it, darker gaps): same layout at the same scale.
     */
    public const WOOD_PLANKS     = 'wood_planks';

    public static function codeFor(?string $pattern): int
    {
        return match ($pattern) {
            self::WORN_PAINT     => 1,
            self::RUST           => 2,
            self::BRUSHED_METAL  => 3,
            self::POLISHED_RINGS => 4,
            self::SKIN           => 5,
            self::MARBLE         => 6,
            self::SANDSTONE      => 7,
            self::MOTTLE         => 8,
            self::WORN_PAINT_SOFT => 9,
            self::WOOD_PLANKS    => 10,
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
            self::SKIN,
        ];
    }
}
