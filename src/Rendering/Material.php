<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Material definition for 3D rendering.
 *
 * Supports colour-based PBR properties (albedo, roughness, metallic,
 * emission) plus three orthogonal extension sets:
 *
 *   1. Carpaint extras (clearcoat layer, metallic flake density,
 *      environment-map reflection toggle) - skipped by the standard
 *      shader path when their activation factor is 0, so existing
 *      materials remain visually unchanged.
 *   2. Procedural normal / surface patterns (see {@see NormalPattern}
 *      / {@see SurfacePattern}) - in-shader pattern lookup driven by
 *      string ids, no texture uploads, no external image files.
 *   3. Procedural vertex-shader cloth (see {@see $cloth}) - per-vertex
 *      sway computed in the vertex stage from `u_time` +
 *      `u_wind_direction` + `u_wind_intensity` + the cloth-tuning
 *      fields below, with anchor weight derived from local Y so the
 *      top of the mesh stays still and the bottom swings. No CPU-side
 *      simulation, no extra render passes - good enough for background
 *      characters, trenchcoats, capes, banners, hanging cables. Real
 *      physical cloth (Bullet SoftBody / GPU compute solver) is
 *      documented in `docs/rfcs/compute-pipeline.md`.
 *
 * Optional `albedoTexture` is sampled by backends that bind it; the
 * engine never requires a texture to render.
 */
class Material
{
    /**
     * @param Color   $albedo               Base reflection / diffuse color.
     * @param float   $roughness            0 = mirror, 1 = fully diffuse.
     * @param float   $metallic             0 = dielectric, 1 = metal.
     * @param Color   $emission             Self-illumination color.
     * @param float   $alpha                Final fragment alpha (1 = opaque).
     * @param string  $shader               Shader id (defaults to 'default').
     * @param ?string $albedoTexture        Optional texture id sampled into
     *                                     u_albedo_texture by Vio backend.
     * @param float   $clearcoat            0 = no clearcoat, 1 = full glossy
     *                                     coat layered on top of the base
     *                                     specular lobe.
     * @param float   $clearcoatRoughness   Roughness of the clearcoat lobe
     *                                     (independent from base roughness).
     * @param float   $flakes               0 = none, 1 = dense metallic flake
     *                                     speckling visible at glancing angle.
     * @param float   $normalIntensity      Multiplier for procedural normal
     *                                     perturbation (1 = engine default).
     *                                     Drives both carpaint flake jitter
     *                                     and the procedural normal-pattern
     *                                     bump strength.
     * @param bool    $useEnvironmentMap    When true the standard PBR path
     *                                     samples u_environment_map (cubemap)
     *                                     to compute IBL reflection on top of
     *                                     direct lighting. Defaults to true so
     *                                     metallic surfaces look correct
     *                                     out-of-the-box; set to false for
     *                                     emissive HUD elements or for a
     *                                     PS1-style flat look.
     * @param ?string $normalPattern        Procedural normal-map pattern id
     *                                     (see {@see NormalPattern}). Null
     *                                     keeps the geometric surface normal.
     *                                     Patterns are evaluated in-shader
     *                                     - no texture uploads, no external
     *                                     image files; tangent space is
     *                                     derived per-fragment via
     *                                     screen-space derivatives so meshes
     *                                     do not need to ship tangent
     *                                     buffers.
     * @param float   $normalScale          UV tiling multiplier for the
     *                                     procedural normal pattern. Higher
     *                                     = denser repetition (1 = pattern
     *                                     fits one UV-unit). Has no effect
     *                                     when $normalPattern is null.
     * @param ?string $surfacePattern       Procedural surface-wear pattern
     *                                     id (see {@see SurfacePattern}).
     *                                     Modulates albedo / roughness /
     *                                     metallic per-fragment so a
     *                                     single material can read as
     *                                     "worn", "rusted" or "brushed"
     *                                     without shipping ORM textures.
     * @param float   $surfaceScale         UV tiling for the surface
     *                                     pattern (analogous to
     *                                     normalScale).
     * @param float   $surfaceIntensity     0 = pattern disabled, 1 =
     *                                     full strength, > 1 = exaggerated.
     * @param float   $wetness              0 = dry, 1 = soaked. Stand-in
     *                                     for screen-space reflections in
     *                                     a forward renderer: wetness
     *                                     reduces effective roughness,
     *                                     darkens albedo, and amplifies
     *                                     the IBL reflection contribution
     *                                     so wet asphalt / polished floors
     *                                     read as reflective without
     *                                     needing a G-buffer ray-march
     *                                     pass.
     * @param bool    $cloth                Enable procedural vertex-shader
     *                                     cloth sway.
     * @param float   $clothStrength        Sway amplitude in world units,
     *                                     applied per vertex weighted by
     *                                     `1 - anchorWeight`. 0.05 is a
     *                                     subtle background-character coat;
     *                                     0.2 is a flowing cape.
     * @param float   $clothFrequency       Hz multiplier on the time term.
     *                                     1.0 = ~one full sway per second;
     *                                     raise for jittery / windy fabric,
     *                                     lower for heavy cloth.
     * @param float   $clothPhase           Per-material phase offset so two
     *                                     adjacent cloths don't sway in
     *                                     lock-step. Use a different value
     *                                     per registered cloth material
     *                                     (e.g. spl_object_id() % 6.28
     *                                     from game code).
     * @param bool    $clothAnchorTop       true (default): top of the mesh
     *                                     is fixed, bottom swings (hanging
     *                                     coat / cape). false: bottom is
     *                                     fixed, top swings (banner anchored
     *                                     at the floor, candle-flame style).
     */
    public function __construct(
        public readonly Color $albedo = new Color(0.8, 0.8, 0.8),
        public readonly float $roughness = 0.5,
        public readonly float $metallic = 0.0,
        public readonly Color $emission = new Color(0.0, 0.0, 0.0),
        public readonly float $alpha = 1.0,
        public readonly string $shader = 'default',
        public readonly ?string $albedoTexture = null,
        public readonly float $clearcoat = 0.0,
        public readonly float $clearcoatRoughness = 0.05,
        public readonly float $flakes = 0.0,
        public readonly float $normalIntensity = 1.0,
        public readonly bool $useEnvironmentMap = true,
        public readonly ?string $normalPattern = null,
        public readonly float $normalScale = 1.0,
        public readonly ?string $surfacePattern = null,
        public readonly float $surfaceScale = 1.0,
        public readonly float $surfaceIntensity = 1.0,
        public readonly float $wetness = 0.0,
        public readonly bool $cloth = false,
        public readonly float $clothStrength = 0.05,
        public readonly float $clothFrequency = 1.0,
        public readonly float $clothPhase = 0.0,
        public readonly bool $clothAnchorTop = true,
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

    /**
     * Convenience factory for car-paint surfaces. Sensible defaults give a
     * glossy metallic finish with a clearcoat layer and subtle flakes that
     * the visual-quality stack's `proc_mode = 10` carpaint shader path
     * consumes (metallic flake jitter, dielectric clearcoat lobe, IBL
     * reflection). The Car prefab's default materials use this entry point.
     */
    public static function carpaint(
        Color $albedo,
        float $metallic = 0.6,
        float $roughness = 0.32,
        float $clearcoat = 0.7,
        float $flakes = 0.4,
    ): self {
        return new self(
            albedo: $albedo,
            roughness: $roughness,
            metallic: $metallic,
            clearcoat: $clearcoat,
            flakes: $flakes,
            useEnvironmentMap: true,
        );
    }

    /**
     * Convenience factory for cloth-enabled materials. Equivalent to
     * setting `cloth: true` on the standard constructor with sensible
     * defaults for a Cyberpunk-style trenchcoat / cape.
     */
    public static function cloth(
        Color $albedo,
        float $strength = 0.08,
        float $frequency = 1.2,
        float $phase = 0.0,
        bool $anchorTop = true,
        float $roughness = 0.7,
        float $metallic = 0.0,
    ): self {
        return new self(
            albedo: $albedo,
            roughness: $roughness,
            metallic: $metallic,
            cloth: true,
            clothStrength: $strength,
            clothFrequency: $frequency,
            clothPhase: $phase,
            clothAnchorTop: $anchorTop,
        );
    }
}
