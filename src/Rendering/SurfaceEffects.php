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
        return self::GLSL;
    }

    private const string GLSL = <<<'GLSL'
// ---- PHPolygon SurfaceEffects (surface hook building blocks) ----
float sfx_hash3(vec3 p) {
    p = fract(p * vec3(0.1031, 0.1030, 0.0973));
    p += dot(p, p.yxz + 33.33);
    return fract((p.x + p.y) * p.z);
}

float sfx_noise3(vec3 p) {
    vec3 i = floor(p);
    vec3 f = fract(p);
    f = f * f * (3.0 - 2.0 * f);
    float a = mix(sfx_hash3(i), sfx_hash3(i + vec3(1.0, 0.0, 0.0)), f.x);
    float b = mix(sfx_hash3(i + vec3(0.0, 1.0, 0.0)), sfx_hash3(i + vec3(1.0, 1.0, 0.0)), f.x);
    float c = mix(sfx_hash3(i + vec3(0.0, 0.0, 1.0)), sfx_hash3(i + vec3(1.0, 0.0, 1.0)), f.x);
    float d = mix(sfx_hash3(i + vec3(0.0, 1.0, 1.0)), sfx_hash3(i + vec3(1.0, 1.0, 1.0)), f.x);
    return mix(mix(a, b, f.y), mix(c, d, f.y), f.z);
}

vec2 sfx_dissolve(float value, float progress, float edge) {
    float cut = progress * (1.0 + edge);
    float keep = step(cut, value);
    float glow = keep * (1.0 - smoothstep(0.0, max(edge, 1e-4), value - cut)) * step(1e-4, progress);
    return vec2(keep, glow);
}

float sfx_front(float dist, float radius, float width) {
    float d = (dist - radius) / max(width, 1e-4);
    return exp(-d * d);
}

float sfx_behind(float dist, float radius, float softness) {
    return 1.0 - smoothstep(radius - max(softness, 1e-4), radius, dist);
}

vec2 sfx_scanBuild(float height, float scanHeight, float lineWidth) {
    float built = step(height, scanHeight);
    float line = built * (1.0 - smoothstep(0.0, max(lineWidth, 1e-4), scanHeight - height));
    return vec2(built, line);
}

float sfx_wireGrid(vec3 p, vec3 n, float spacing, float widthPx) {
    vec3 g = p / max(spacing, 1e-4);
    vec3 fw = max(fwidth(g), vec3(1e-4));
    vec3 d = abs(fract(g - 0.5) - 0.5) / fw;
    vec3 lines = 1.0 - smoothstep(vec3(widthPx), vec3(widthPx + 1.0), d);
    vec3 across = 1.0 - smoothstep(vec3(0.6), vec3(0.9), abs(n));
    return max(max(lines.x * across.x, lines.y * across.y), lines.z * across.z);
}

float sfx_glitch(vec3 p, float time, float cell, float rate) {
    float t = floor(time * rate);
    return sfx_hash3(floor(p / max(cell, 1e-4)) + vec3(t * 17.0, t * 5.0, t * 11.0));
}

vec3 sfx_saturation(vec3 c, float s) {
    float l = dot(c, vec3(0.2126, 0.7152, 0.0722));
    return max(mix(vec3(l), c, s), vec3(0.0));
}

void sfx_weather(inout vec3 albedo, inout float roughness, vec3 p, vec3 n, float amount) {
    if (amount <= 0.0) {
        return;
    }
    float patches = sfx_noise3(p * 0.35) * 0.65 + sfx_noise3(p * 1.7) * 0.35;
    float streak = sfx_noise3(vec3(p.x * 2.3, p.y * 0.18, p.z * 2.3));
    float wall = 1.0 - abs(n.y);
    float top = clamp(n.y, 0.0, 1.0);
    float grime = clamp((patches - 0.35) * 1.8, 0.0, 1.0) * (0.6 + 0.4 * wall);
    grime = max(grime, wall * smoothstep(0.55, 0.85, streak)) * clamp(amount, 0.0, 1.0);
    albedo = mix(albedo, sfx_saturation(albedo, 0.45) * 0.55, grime);
    float dust = top * clamp(amount, 0.0, 1.0) * 0.25;
    albedo = mix(albedo, vec3(dot(albedo, vec3(0.3333))) * vec3(1.05, 1.0, 0.92), dust);
    roughness = clamp(mix(roughness, 1.0, clamp(amount, 0.0, 1.0) * 0.5 * max(grime, top)), 0.04, 1.0);
}

float sfx_segment(vec2 p, vec2 a, vec2 b, out float t) {
    vec2 ab = b - a;
    t = clamp(dot(p - a, ab) / max(dot(ab, ab), 1e-6), 0.0, 1.0);
    return length(p - a - ab * t);
}

float sfx_pulses(float s, float time, float speed, float spacing, float width) {
    float gap = max(spacing, 1e-4);
    float x = fract((s - time * speed) / gap) * gap;
    return 1.0 - smoothstep(0.0, max(width, 1e-4), min(x, gap - x));
}
// ---- end SurfaceEffects ----

GLSL;
}
