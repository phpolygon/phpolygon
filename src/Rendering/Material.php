<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Material definition for 3D rendering.
 *
 * Supports colour-based PBR properties and optional albedo texture. The
 * {@see $cloth} flag opts the material into the procedural vertex-shader
 * cloth path: per-vertex sway is computed in the vertex stage from
 * `u_time` + `u_wind_direction` + `u_wind_intensity` + the cloth-tuning
 * fields below, with anchor weight derived from local Y so the top of
 * the mesh stays still and the bottom swings. No CPU-side simulation,
 * no extra render passes - good enough for background characters,
 * trenchcoats, capes, banners, hanging cables.
 *
 * Real physical cloth (Bullet SoftBody / GPU compute solver) is a
 * separate engine investment and is documented in
 * `docs/rfcs/compute-pipeline.md`.
 */
class Material
{
    /**
     * @param Color   $albedo               Base diffuse / reflection color.
     * @param float   $roughness            0 = mirror, 1 = fully diffuse.
     * @param float   $metallic             0 = dielectric, 1 = metal.
     * @param Color   $emission             Self-illumination color.
     * @param float   $alpha                Final fragment alpha (1 = opaque).
     * @param string  $shader               Shader id (defaults to 'default').
     * @param ?string $albedoTexture        Optional texture id sampled into u_albedo_texture.
     * @param bool    $cloth                Enable procedural vertex-shader cloth sway.
     * @param float   $clothStrength        Sway amplitude in world units, applied per
     *                                      vertex weighted by `1 - anchorWeight`. 0.05 is
     *                                      a subtle background-character coat; 0.2 is a
     *                                      flowing cape.
     * @param float   $clothFrequency       Hz multiplier on the time term. 1.0 = ~one
     *                                      full sway per second; raise for jittery /
     *                                      windy fabric, lower for heavy cloth.
     * @param float   $clothPhase           Per-material phase offset so two adjacent
     *                                      cloths don't sway in lock-step. Use a
     *                                      different value per registered cloth material
     *                                      (e.g. spl_object_id() % 6.28 from game code).
     * @param bool    $clothAnchorTop       true (default): top of the mesh is fixed,
     *                                      bottom swings (hanging coat / cape).
     *                                      false: bottom is fixed, top swings (banner
     *                                      anchored at the floor, candle-flame style).
     */
    public function __construct(
        public readonly Color $albedo = new Color(0.8, 0.8, 0.8),
        public readonly float $roughness = 0.5,
        public readonly float $metallic = 0.0,
        public readonly Color $emission = new Color(0.0, 0.0, 0.0),
        public readonly float $alpha = 1.0,
        public readonly string $shader = 'default',
        public readonly ?string $albedoTexture = null,
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
