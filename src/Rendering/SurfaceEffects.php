<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Building blocks for the surface hook ({@see ProcModeShaderRegistry::registerSurfaceGlsl()}):
 * GLSL functions a game prepends to its hook helpers to make surfaces appear,
 * vanish, age or glow without a material id per state. Everything is keyed off
 * world position and scalars the game hands in (uniforms, material or instance
 * params), so batches stay batches and a change costs no draw.
 *
 * - `sfx_hash3`, `sfx_noise3`     sine-free hash and 3D value noise in [0, 1]
 *                                 (no ulp-sensitive `sin`, stable on every backend)
 * - `sfx_dissolve(v, p, edge)`    cut a surface away by a noise value v as p goes 0 → 1;
 *                                 x = keep (1/0), y = glow on the edge of the cut
 * - `sfx_front(d, r, w)`          Gaussian ring band on a growing radius
 * - `sfx_behind(d, r, soft)`      1 inside a radius, 0 outside, soft edge
 * - `sfx_scanBuild(h, scan, w)`   build-up from below: x = built, y = scanline glow
 * - `sfx_wireGrid(p, N, s, px)`   antialiased world-space grid lines on a face, a
 *                                 wireframe stand-in without barycentrics
 * - `sfx_glitch(p, t, cell, hz)`  blocky digital noise, re-rolled hz times a second
 * - `sfx_saturation(c, s)`        saturation 0 (grey) .. 1 (as is) .. > 1
 * - `sfx_weather(albedo, rough, p, N, amount)`  grime patches, wall streaks and dull
 *                                 tops by amount 0..1; 0 leaves the surface alone
 * - `sfx_segment(p, a, b, t)`     distance to a segment in the plane, t along it
 * - `sfx_pulses(s, t, v, gap, w)` bands `gap` apart travelling along s at v
 *
 * The functions only read their arguments: no engine uniform, no varying. They are
 * the same text for both GLSL families. `sfx_wireGrid` takes screen derivatives:
 * call it in control flow that is uniform per primitive (a per-draw flat value is),
 * and before any discard of the fragment.
 */
final class SurfaceEffects
{
    private function __construct()
    {
    }

    public static function glsl(): string
    {
        return self::$glsl ??= (string) file_get_contents(dirname(__DIR__, 2) . '/resources/shaders/lib/surface_effects.glsl');
    }

    private static ?string $glsl = null;

}
