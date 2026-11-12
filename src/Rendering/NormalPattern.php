<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Procedural normal-map pattern identifiers.
 *
 * PHPolygon does not ship normal-map image files. Surface micro-detail is
 * generated entirely in the fragment shader from a small library of pattern
 * functions (brick mortar grooves, hammered metal divots, woven fibres, ...).
 * Materials reference a pattern by string id; the shader looks the id up
 * via an integer code (see {@see NormalPattern::codeFor()}) and dispatches
 * to the matching analytic function.
 *
 * Tangent space is derived per-fragment using screen-space derivatives, so
 * meshes do not need to carry tangent buffers - any procedurally-generated
 * mesh in the engine can opt into normal mapping just by referencing a
 * pattern id on its material.
 *
 * Add a new pattern in three steps:
 *   1. Add a const + bump the codeFor() switch below.
 *   2. Implement np_<name>(vec2 uv) inside mesh3d.frag.glsl AND the embedded
 *      Vio shader in VioRenderer3D::vioFragmentShaderSource() - keep them in
 *      sync, the audit script asserts equivalence.
 *   3. Wire the new code into the dispatchProceduralNormal() switch in both
 *      shader copies.
 */
final class NormalPattern
{
    public const NONE        = null;
    public const BRICKS      = 'bricks';
    public const BUMPS       = 'bumps';
    public const ORANGE_PEEL = 'orange_peel';
    public const HAMMERED    = 'hammered';
    public const HEXAGONS    = 'hexagons';
    public const WOOD_GRAIN  = 'wood_grain';
    public const SCRATCHES   = 'scratches';
    public const CRACKED     = 'cracked';
    public const NOISE       = 'noise';
    /**
     * Skin micro-relief: dense small pores layered over softer wrinkle FBM.
     * Designed for the Material::skin() factory; intensity 0.3..0.6 is the
     * realistic range, higher values exaggerate the orange-peel-like look.
     */
    public const SKIN        = 'skin';
    /** Rounded stones in mortar beds (shader plan 5.1), with a height field for parallax. */
    public const COBBLESTONE = 'cobblestone';
    /** Overlapping roof tiles in rows, each a little lifted at its lower edge (shader plan 5.5). */
    public const ROOF_TILES  = 'roof_tiles';
    /**
     * Board-formed / panel concrete: soft horizontal board joints every 0.2 m (each
     * board a hair higher or lower), a vertical panel joint every 2.4 m and round tie
     * holes on a 0.6 x 0.6 m grid, in metres of mesh UV at normalScale 1 (metric UVs,
     * see MetricMesh); normalScale 2 halves every distance. Joints run along U.
     */
    public const FORMWORK    = 'formwork';
    /**
     * Concrete / terrazzo aggregate: small rounded pits and bumps from two octaves of
     * cell noise (about 2.2 cm and 0.9 cm at normalScale 1), no hard edges. A calm,
     * low-contrast replacement for NOISE on concrete and plaster.
     */
    public const AGGREGATE   = 'aggregate';
    /**
     * Anisotropic brushed metal: fine strands along U (300 per metre at normalScale 1,
     * each with its own depth, fading out where a strand is thinner than a pixel).
     * The relief counterpart of SurfacePattern::BRUSHED_METAL, which only varies
     * albedo/roughness.
     */
    public const BRUSHED     = 'brushed';
    /** Larger (about 0.33 m at normalScale 1), shallower dimples than HAMMERED, no hard rim: dented sheet. */
    public const HAMMERED_SOFT = 'hammered_soft';
    /**
     * Wooden planks 0.12 m wide and 1.2 m long along U (normalScale 1), laid in rows
     * with a random butt-joint offset per row; every plank has its own grain spacing,
     * grain phase and sometimes a knot, with a subtle gap between planks. Pairs with
     * SurfacePattern::WOOD_PLANKS (same layout at the same scale) for the tone.
     */
    public const WOOD_PLANKS = 'wood_planks';

    /**
     * Map a string pattern id to the integer code consumed by the shader's
     * u_normal_pattern uniform. Unknown ids - including null - resolve to 0
     * which disables normal-map sampling entirely.
     */
    public static function codeFor(?string $pattern): int
    {
        return match ($pattern) {
            self::BRICKS      => 1,
            self::BUMPS       => 2,
            self::ORANGE_PEEL => 3,
            self::HAMMERED    => 4,
            self::HEXAGONS    => 5,
            self::WOOD_GRAIN  => 6,
            self::SCRATCHES   => 7,
            self::CRACKED     => 8,
            self::NOISE       => 9,
            self::SKIN        => 10,
            self::COBBLESTONE => 11,
            self::ROOF_TILES  => 12,
            self::FORMWORK    => 13,
            self::AGGREGATE   => 14,
            self::BRUSHED     => 15,
            self::HAMMERED_SOFT => 16,
            self::WOOD_PLANKS => 17,
            default           => 0,
        };
    }

    /**
     * @return list<string> Every supported pattern id, in registration order.
     */
    public static function all(): array
    {
        return [
            self::BRICKS,
            self::BUMPS,
            self::ORANGE_PEEL,
            self::HAMMERED,
            self::HEXAGONS,
            self::WOOD_GRAIN,
            self::SCRATCHES,
            self::CRACKED,
            self::NOISE,
            self::SKIN,
        ];
    }
}
