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
        ];
    }
}
