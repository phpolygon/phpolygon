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
     * @param Color   $subsurfaceColor     Warm transmission tint for the
     *                                     skin-SSS approximation. Sampled at
     *                                     the terminator (NdotL near zero)
     *                                     and on back-lit fragments to fake
     *                                     light wrapping through translucent
     *                                     tissue (ears, fingers, nose).
     *                                     Ignored when subsurfaceStrength = 0.
     * @param float   $subsurfaceStrength  0 = no SSS (default, byte-identical
     *                                     to the legacy lit path so existing
     *                                     materials render unchanged). 1 =
     *                                     full skin scattering: wrap-diffuse
     *                                     extends past the terminator and
     *                                     warm tint bleeds into the shadow
     *                                     boundary. Use Material::skin(...)
     *                                     for sensible skin defaults.
     * @param float   $cavity               0 = off (default). Darkens the
     *                                     recesses of the procedural normal
     *                                     pattern's height field (mortar,
     *                                     cracks, dents): ambient light by
     *                                     the full factor, direct light by
     *                                     half. 1 = recesses get no ambient
     *                                     light. Needs $normalPattern.
     * @param float   $parallaxDepth        0 = off (default). Depth of the
     *                                     pattern's relief in world units:
     *                                     parallax occlusion marches the view
     *                                     ray that far below the surface, so
     *                                     the pattern shifts with the view
     *                                     and recesses hide behind their
     *                                     edges. The geometry is unchanged
     *                                     (silhouettes stay flat). Needs
     *                                     $normalPattern and mesh UVs.
     * @param bool    $twoSided             Light an OPAQUE surface from both
     *                                     sides: where the authored normal
     *                                     faces away from the viewer it is
     *                                     mirrored through the triangle's
     *                                     plane, so the back of a thin shell
     *                                     (a leaf, a palm frond, a flag, a
     *                                     tarp) is lit like its front instead
     *                                     of rendering unlit/black. Pair with
     *                                     $subsurfaceStrength for light
     *                                     shining through from behind.
     *                                     Transparent materials always shade
     *                                     with the viewer-facing normal.
     * @param bool    $foliageWind          Sway in the vertex shader with the
     *                                     global wind (SetWind): the mesh
     *                                     bends with the square of its local
     *                                     height (a trunk foot stays put, a
     *                                     crown moves) and the phase comes
     *                                     from the instance position, so
     *                                     neighbours never sway in lockstep.
     *                                     Bark and foliage of one plant both
     *                                     set it and stay joined.
     * @param float   $windFlutter          Extra leaf flutter in metres at the
     *                                     outer edge of the mesh (weighted by
     *                                     horizontal distance from the mesh
     *                                     centre) per unit wind intensity.
     *                                     0 for bark; 0.03-0.08 for crowns,
     *                                     ~0.25 for palm fronds. Needs
     *                                     $foliageWind.
     * @param bool    $grass                Grass blades (GrassTuftMesh): the
     *                                     vertex stage bends each blade with
     *                                     the wind and away from benders, thins
     *                                     and shortens tufts with the camera
     *                                     distance (SetGrass) and reads the
     *                                     instance hash from the matrix; the
     *                                     fragment stage blends albedo (root)
     *                                     to $grassTip along the blade. Never
     *                                     mapped to a proc mode. Pair with
     *                                     $twoSided. See Material::grass().
     * @param Color   $grassTip             Colour at the blade tip.
     * @param float   $grassBend            Tip displacement per unit wind as a
     *                                     fraction of the blade height.
     * @param int     $grassBands           0 = smooth root-to-tip gradient,
     *                                     n = n flat bands (stylised look).
     * @param float   $grassVariation       Brightness variation per blade and
     *                                     over the field (0..1).
     * @param float   $grassRootShade       Darkening at the root, 0..1.
     * @param float   $grassPatchScale      Frequency (1/m) of the field-wide
     *                                     colour noise.
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
        public readonly Color $subsurfaceColor = new Color(1.0, 0.35, 0.25),
        public readonly float $subsurfaceStrength = 0.0,
        public readonly float $cavity = 0.0,
        public readonly float $parallaxDepth = 0.0,
        public readonly bool $twoSided = false,
        public readonly bool $foliageWind = false,
        public readonly float $windFlutter = 0.0,
        public readonly bool $grass = false,
        public readonly Color $grassTip = new Color(0.6, 0.75, 0.3),
        public readonly float $grassBend = 0.3,
        public readonly int $grassBands = 0,
        public readonly float $grassVariation = 0.2,
        public readonly float $grassRootShade = 0.45,
        public readonly float $grassPatchScale = 0.12,
    ) {}

    /**
     * Grass blades: root colour `$base`, tip colour `$tip`, two-sided, matte,
     * no environment reflection, light through the blades against the sun
     * (`$translucency`, strongest at the tips).
     */
    public static function grass(
        Color $base,
        Color $tip,
        float $translucency = 0.3,
        float $bend = 0.3,
        int $bands = 0,
        float $variation = 0.2,
        float $rootShade = 0.45,
        float $patchScale = 0.12,
        float $roughness = 0.85,
    ): self {
        return new self(
            albedo: $base,
            roughness: $roughness,
            metallic: 0.0,
            useEnvironmentMap: false,
            subsurfaceColor: new Color(
                min(1.0, $tip->r * 1.15 + 0.05),
                min(1.0, $tip->g * 1.2 + 0.08),
                min(1.0, $tip->b * 0.7),
            ),
            subsurfaceStrength: max(0.0, $translucency),
            twoSided: true,
            grass: true,
            grassTip: $tip,
            grassBend: $bend,
            grassBands: max(0, $bands),
            grassVariation: max(0.0, $variation),
            grassRootShade: max(0.0, min(1.0, $rootShade)),
            grassPatchScale: max(0.0, $patchScale),
        );
    }

    /**
     * Foliage (leaves, fronds, needles, hedges): two-sided lighting, light
     * shining through from behind (subsurface tinted toward a brighter,
     * yellower version of the albedo unless given), matte, no environment
     * reflection, and foliage wind with leaf flutter.
     */
    public static function foliage(
        Color $albedo,
        float $flutter = 0.05,
        float $translucency = 0.35,
        ?Color $transmission = null,
        float $roughness = 0.8,
        bool $wind = true,
    ): self {
        return new self(
            albedo: $albedo,
            roughness: $roughness,
            metallic: 0.0,
            useEnvironmentMap: false,
            subsurfaceColor: $transmission ?? new Color(
                min(1.0, $albedo->r * 1.3 + 0.08),
                min(1.0, $albedo->g * 1.35 + 0.12),
                min(1.0, $albedo->b * 0.8),
            ),
            subsurfaceStrength: $translucency,
            twoSided: true,
            foliageWind: $wind,
            windFlutter: $wind ? $flutter : 0.0,
        );
    }

    /**
     * Bark / wood of a plant that sways with its foliage: foliage wind on
     * (same bend as the crown, no flutter), matte, no environment reflection.
     */
    public static function bark(Color $albedo, float $roughness = 0.92, bool $wind = true): self
    {
        return new self(
            albedo: $albedo,
            roughness: $roughness,
            metallic: 0.0,
            useEnvironmentMap: false,
            foliageWind: $wind,
        );
    }

    /** This material with another emission colour (e.g. a glow that fades in at night). */
    public function withEmission(Color $emission): self
    {
        return $this->copy(emission: $emission);
    }

    /**
     * This material showing a texture (null = none) over the given albedo, or
     * with its albedo kept.
     */
    public function withAlbedoTexture(?string $textureId, ?Color $albedo = null): self
    {
        return $this->copy(albedoTexture: $textureId, clearAlbedoTexture: $textureId === null, albedo: $albedo);
    }

    /** This material drawn by another shader. */
    public function withShader(string $shader): self
    {
        return $this->copy(shader: $shader);
    }

    /**
     * This material as foliage: two-sided lighting and foliage wind with the
     * given flutter; adds light shining through from behind when the material
     * has none. Colours and everything else stay.
     */
    public function withFoliage(float $flutter = 0.05, float $translucency = 0.3): self
    {
        $hasSss = $this->subsurfaceStrength > 0.0;
        return $this->copy(
            twoSided: true,
            foliageWind: true,
            windFlutter: $flutter,
            subsurfaceStrength: $hasSss ? $this->subsurfaceStrength : $translucency,
            subsurfaceColor: $hasSss ? $this->subsurfaceColor : new Color(
                min(1.0, $this->albedo->r * 1.3 + 0.08),
                min(1.0, $this->albedo->g * 1.35 + 0.12),
                min(1.0, $this->albedo->b * 0.8),
            ),
        );
    }

    private function copy(
        ?Color $emission = null,
        ?bool $twoSided = null,
        ?bool $foliageWind = null,
        ?float $windFlutter = null,
        ?float $subsurfaceStrength = null,
        ?Color $subsurfaceColor = null,
        ?string $albedoTexture = null,
        bool $clearAlbedoTexture = false,
        ?Color $albedo = null,
        ?string $shader = null,
    ): self {
        return new self(
            albedo: $albedo ?? $this->albedo,
            roughness: $this->roughness,
            metallic: $this->metallic,
            emission: $emission ?? $this->emission,
            alpha: $this->alpha,
            shader: $shader ?? $this->shader,
            albedoTexture: $clearAlbedoTexture ? null : ($albedoTexture ?? $this->albedoTexture),
            clearcoat: $this->clearcoat,
            clearcoatRoughness: $this->clearcoatRoughness,
            flakes: $this->flakes,
            normalIntensity: $this->normalIntensity,
            useEnvironmentMap: $this->useEnvironmentMap,
            normalPattern: $this->normalPattern,
            normalScale: $this->normalScale,
            surfacePattern: $this->surfacePattern,
            surfaceScale: $this->surfaceScale,
            surfaceIntensity: $this->surfaceIntensity,
            wetness: $this->wetness,
            cloth: $this->cloth,
            clothStrength: $this->clothStrength,
            clothFrequency: $this->clothFrequency,
            clothPhase: $this->clothPhase,
            clothAnchorTop: $this->clothAnchorTop,
            subsurfaceColor: $subsurfaceColor ?? $this->subsurfaceColor,
            subsurfaceStrength: $subsurfaceStrength ?? $this->subsurfaceStrength,
            cavity: $this->cavity,
            parallaxDepth: $this->parallaxDepth,
            twoSided: $twoSided ?? $this->twoSided,
            foliageWind: $foliageWind ?? $this->foliageWind,
            windFlutter: $windFlutter ?? $this->windFlutter,
            grass: $this->grass,
            grassTip: $this->grassTip,
            grassBend: $this->grassBend,
            grassBands: $this->grassBands,
            grassVariation: $this->grassVariation,
            grassRootShade: $this->grassRootShade,
            grassPatchScale: $this->grassPatchScale,
        );
    }

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

    /**
     * Convenience factory for human skin. Sets a dielectric PBR profile
     * (low metallic, mid roughness, no clearcoat) and enables the
     * subsurface-scattering path in the lit shader:
     *
     *   - $subsurfaceColor is the warm transmission tint sampled at the
     *     light terminator and on back-lit fragments. The default is a
     *     human-blood pink; pass a desaturated variant for animals /
     *     non-human creatures, or a derived tint per skin tone.
     *   - $subsurfaceStrength scales the SSS contribution. 0.5..1.0 is
     *     the sensible range; 0 disables SSS and degrades to standard PBR.
     *
     * The shader gates the entire SSS path on subsurfaceStrength > 0, so
     * any other material remains byte-identical to its pre-SSS output.
     */
    public static function skin(
        Color $albedo,
        Color $subsurfaceColor = new Color(1.0, 0.35, 0.25),
        float $subsurfaceStrength = 0.8,
        float $roughness = 0.55,
        float $detailIntensity = 0.15,
    ): self {
        return new self(
            albedo: $albedo,
            roughness: $roughness,
            metallic: 0.0,
            normalPattern: $detailIntensity > 0.0 ? NormalPattern::SKIN : null,
            normalScale: 1.0,
            surfacePattern: $detailIntensity > 0.0 ? SurfacePattern::SKIN : null,
            surfaceScale: 1.0,
            surfaceIntensity: $detailIntensity,
            subsurfaceColor: $subsurfaceColor,
            subsurfaceStrength: $subsurfaceStrength,
        );
    }
}
