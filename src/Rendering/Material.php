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
     * @param bool    $windTufts            The mesh is built from leaf tufts
     *                                     ({@see \PHPolygon\Vegetation\PlantCanopy}):
     *                                     uv.x holds the phase of each tuft,
     *                                     uv.y 0 at its centre .. 1 at the leaf
     *                                     tips, so flutter moves tuft by tuft
     *                                     instead of by the distance from the
     *                                     mesh centre. Off: the position alone
     *                                     sets the flutter (any other mesh).
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
        public readonly bool $windTufts = false,
        /**
         * Values of uniforms declared with ProcModeShaderRegistry::declareMaterialParameter(),
         * by uniform name. Parameters of a look that need no id of their own.
         *
         * @var array<string, int|float|list<float>>
         */
        public readonly array $params = [],
        /**
         * 0..1, how little temporal anti-aliasing and upscalers (TAA, TAAU, FSR 3, DLSS) may
         * trust last frame's colour of this surface. For opaque surfaces whose content moves
         * while the surface stands still: scrolling scanlines, a screen, running text. They
         * carry no motion vector of their own, so the history smears their content into
         * streaks. 1 = the strongest response; transparent surfaces count their coverage anyway.
         */
        public readonly float $reactive = 0.0,
        /**
         * Mip levels added to the albedo texture's level of detail, on top of the engine's own
         * bias for temporal upscaling. Negative = sharper: a sign or a text panel that must stay
         * legible from far away (-0.5 to -1; below that it shimmers without temporal AA).
         */
        public readonly float $mipBias = 0.0,
        /**
         * Filtering ratio the albedo texture gets at least, 0 = the texture's own (the player's
         * anisotropy setting). Above that setting the shader takes up to 16 taps along the
         * stretched axis, so a panel seen at a grazing angle keeps its strokes. Costs those taps
         * on the material's pixels only.
         */
        public readonly int $anisotropy = 0,
        /**
         * A travelling wave through the mesh in the vertex shader (swimming fish, swaying
         * kelp); null = none. See {@see BodyWave}.
         */
        public readonly ?BodyWave $bodyWave = null,
        /**
         * > 0: a soft puff of smoke, steam or dust on a unit quad (a billboard
         * particle): round, fading out towards the quad's rim, and fading where it
         * meets geometry within this many metres (needs the scene depth,
         * ProcModeShaderRegistry::requireSceneDepth()); no hard cut through walls
         * and ground. 0 = an ordinary surface.
         */
        public readonly float $softParticle = 0.0,
        /**
         * > 0: the albedo texture is projected from the three world axes (tiles
         * per metre) and blended by the normal instead of read through the mesh
         * UVs - for imported meshes with stretched or missing UVs (shader plan 5.1).
         */
        public readonly float $triplanar = 0.0,
        /**
         * How much the surface weathers inside an AgingVolume (shader plan 5.2):
         * 1 = fully (dust, streaks, rust, moss, salt), 0 = never (glass, screens,
         * fresh paint the game wants clean).
         */
        public readonly float $weathering = 1.0,
        /**
         * 0..1: a transparent material reads as glass (shader plan 5.3) - towards
         * grazing angles it turns opaque and mirror-like (Fresnel), so the pane's
         * edges and curvature show.
         */
        public readonly float $glass = 0.0,
        /** 0..1: dust smudges and dried rain runs on that glass. */
        public readonly float $glassDirt = 0.0,
        /**
         * > 0: an emissive window surface lights per window (shader plan 5.4) - a
         * world-space grid of windows, this fraction of them lit in a warm or cool
         * tone, each showing a room behind the glass (interior mapping).
         */
        public readonly float $windowLights = 0.0,
        /** Emissive neon tube: a hot white core facing the viewer, softer towards the rim. */
        public readonly bool $neonTube = false,
        /** 0..1: cloth fuzz - a soft rim of the albedo colour at grazing angles (shader plan 5.5). */
        public readonly float $sheen = 0.0,
        /** 0..1: brushed metal - the sun's highlight stretched along the surface's horizontal. */
        public readonly float $brushed = 0.0,
        /** 0..1: micro glitter - tiny crystals that flash in the sun (sand, granite, snow). */
        public readonly float $glitter = 0.0,
        /**
         * Camera distance (start, end) over which the surface dissolves in a
         * screen-space dither (shader plan 6.1) - plants, props and decoration
         * fade out instead of popping where the game stops drawing them.
         * end <= 0 = never.
         *
         * @var array{0: float, 1: float}
         */
        public readonly array $fadeDistance = [0.0, 0.0],
        /**
         * 0..1: rim light for figures (shader plan 6.2) - a soft edge of sky light
         * round the silhouette, brighter where the sun comes from behind, so a
         * character separates from the background in light and shade.
         */
        public readonly float $rimLight = 0.0,
        /** 0..1: hair shine - a highlight ring across the strands (Kajiya-Kay, strands run down the surface). */
        public readonly float $hairShine = 0.0,
        /**
         * 0..1: a transparent surface bends what lies behind it (glass, water in a
         * tank, heat shields) - it shows the opaque scene shifted along its normal
         * instead of blending over it. Needs more than 16 texture units (the copy of
         * the scene colour reads unit 16); without them the surface blends as before.
         */
        public readonly float $refraction = 0.0,
        /** 0..1: frosted glass - what lies behind is blurred (with $refraction > 0). */
        public readonly float $frost = 0.0,
        /**
         * Particle material: albedo, emission and alpha are multiplied by the
         * particle's colour over life (ParticleEmitter::$colorOverLife), which rides
         * in the instance parameters (shader plan 7.1).
         */
        public readonly bool $particleTint = false,
        /**
         * Flipbook (shader plan 7.1): [columns, rows] of the albedo texture; a
         * particle shows the frame of its age (first frame at birth, last at
         * death), other meshes cycle at $flipbookFps. [0, 0] = off.
         *
         * @var array{0: int, 1: int}
         */
        public readonly array $flipbook = [0, 0],
        public readonly float $flipbookFps = 12.0,
        /** Streaks (Stretched particles) fade towards their tail (shader plan 7.1). */
        public readonly bool $streakFade = false,
        /**
         * Coarsest shading rate this material may run at (shader plan 9.1,
         * VIO_FEATURE_SHADING_RATE): Half / Quarter for surfaces whose detail
         * hides it - sky domes, distant terrain, fog cards, water far out. Null =
         * the frame's rate (GraphicsSettings::$shadingRate). The coarser of the two
         * wins.
         */
        public readonly ?Quality\ShadingRate $shadingRate = null,
        /**
         * Vertex animation texture (shader plan 6.3, Geometry\VatBaker): the id of
         * the baked image (VioRenderer3D::registerGpuTexture), and [frames, fps,
         * min x, min y, min z, extent x, extent y, extent z] from the bake. Needs
         * more than 17 texture units; the mesh then plays the animation in place
         * (no shadow of its own: the shadow pass keeps the rest pose out).
         *
         * @var list<float>
         */
        public readonly ?string $vatTexture = null,
        public readonly array $vatInfo = [],
        /**
         * > 0: pixels whose albedo-texture alpha lies below it are cut out (leaves,
         * fences, baked impostors - shader plan 9.1). Opaque pass, no blending; the
         * shadow pass ignores it.
         */
        public readonly float $alphaCutoff = 0.0,
        /**
         * Fur shells (shader plan 10.2): > 0 grows strands this long (metres) out of the
         * surface - the mesh drawn again $furShells times, each shell pushed out along
         * the normals and cut to thinning strand dots ($furDensity per UV unit). The
         * base gets darker towards the skin. Opaque pass only; no shells in shadows.
         */
        public readonly float $furLength = 0.0,
        public readonly int $furShells = 12,
        public readonly float $furDensity = 120.0,
        /**
         * Strand hair (shader plan 10.2, {@see \PHPolygon\Geometry\HairMesh}): the mesh's
         * normals are strand directions; shading uses the normal of a thin cylinder
         * around the strand that faces the camera.
         */
        public readonly bool $hair = false,
        /**
         * Spectral ocean (shader plan 10.2): a texture id holding an
         * {@see \PHPolygon\Rendering\Ocean\OceanSpectrum} volume. The vertex stage
         * displaces the surface by it (tiled every $oceanPatch metres, $oceanAmplitude
         * metres at the largest wave, looping every $oceanLoop seconds, $oceanChop
         * scales the sideways motion that sharpens crests) and turns the normals with
         * it. Needs more than 18 texture units; elsewhere the material draws flat.
         */
        public readonly ?string $oceanTexture = null,
        public readonly float $oceanPatch = 64.0,
        public readonly float $oceanAmplitude = 0.6,
        public readonly float $oceanLoop = 12.0,
        public readonly float $oceanChop = 1.0,
        /**
         * Tessellation (shader plan 10.2): > 0 splits each triangle up to this level near
         * the camera - full detail up to $tessNear metres, none from $tessFar - and runs
         * the vertex stage on every new vertex, so waves and the ocean spectrum get
         * detail where they are seen. Stays out of the depth prepass and the mesh-
         * shader path; draws untessellated where the backend has no tessellation.
         */
        public readonly float $tessellation = 0.0,
        public readonly float $tessNear = 8.0,
        public readonly float $tessFar = 60.0,
        /**
         * Tangent-space normal map (shader plan 5.1): a texture id, RGB = the normal in
         * the surface's tangent frame (+Y up, OpenGL convention), stored linearly. The
         * frame comes from the screen derivatives of position and UV, so meshes need no
         * tangent attribute; $normalIntensity scales it. Needs php-vio's 12 sampler
         * registers; elsewhere the surface stays smooth.
         */
        public readonly ?string $normalTexture = null,
        /**
         * Splatted ground (shader plan 5.1): up to four {@see SplatLayer}s blended by a
         * splat map - then $albedoTexture is that map, its RGBA the weights of layers
         * 0-3 over $splatArea ([x, z, width, depth] in world metres). Each layer's
         * pattern height lifts it in the blend; $splatSharpness sets how hard the
         * layers meet (high = crisp, low = soft). The dominant layer's pattern shapes
         * the relief. Empty = an ordinary material.
         *
         * @var list<SplatLayer>
         */
        public readonly array $splatLayers = [],
        /** @var array{0: float, 1: float, 2: float, 3: float} */
        public readonly array $splatArea = [0.0, 0.0, 100.0, 100.0],
        public readonly float $splatSharpness = 6.0,
        /**
         * Breathing (shader plan 6.2): [amplitude in metres, breaths per second, band
         * bottom, band top] - the vertices between the band's heights (object space, e.g.
         * the chest of a torso) swell along their normal and settle, each instance in its
         * own rhythm. Amplitude 0 = off. Give the torso its own material when sleeves share
         * the look, so only the chest breathes.
         *
         * @var array{0: float, 1: float, 2: float, 3: float}
         */
        public readonly array $breathing = [0.0, 0.25, 0.0, 0.0],
        /**
         * Instance colour variation (shader plan 6.2): 0..1 - each instance (crowd, trees,
         * crates) a little lighter or darker and warmer or cooler, picked from where it
         * stands; no instance data needed.
         */
        public readonly float $instanceTint = 0.0,
        /**
         * Dappled shadow (shader plan 6.1): 0..1 - how much of this caster's sun shadow
         * breaks up into gaps, leaf-cluster sized (about half a metre) and drifting with
         * the wind, so the light under a tree crown falls in spots. For crowns, hedges,
         * pergolas; 0 = a solid shadow.
         */
        public readonly float $shadowDapple = 0.0,
        /**
         * Shader profile ({@see ShaderProfiles}): the lean program this material draws
         * with. Null = by id prefix or the smallest covering profile.
         */
        public readonly ?string $shaderProfile = null,
    ) {}

    /**
     * A soft puff of smoke, steam or dust for billboard particles (see
     * $softParticle): matte, two-sided, no environment reflection, translucent.
     */
    public static function puff(Color $albedo, float $alpha = 0.35, float $softness = 1.5, ?Color $emission = null): self
    {
        return new self(
            albedo: $albedo,
            roughness: 1.0,
            emission: $emission ?? new Color(0.0, 0.0, 0.0),
            alpha: $alpha,
            useEnvironmentMap: false,
            twoSided: true,
            softParticle: max(0.01, $softness),
        );
    }

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
     * reflection, and foliage wind with leaf flutter. `$tufts` for meshes with a
     * {@see \PHPolygon\Vegetation\PlantCanopy}: flutter shifted per leaf tuft.
     */
    public static function foliage(
        Color $albedo,
        float $flutter = 0.05,
        float $translucency = 0.35,
        ?Color $transmission = null,
        float $roughness = 0.8,
        bool $wind = true,
        bool $tufts = false,
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
            windTufts: $wind && $tufts,
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

    /** This material with another weathering susceptibility (0 = never weathers). */
    public function withWeathering(float $weathering): self
    {
        $m = $this->copy();

        return new self(...[...get_object_vars($m), 'weathering' => max(0.0, $weathering)]);
    }

    /**
     * This material wetted, on every face (0 = dry, 1 = soaked): a porous (non-metal)
     * surface darkens toward 50 %, the water film lowers the roughness and lays cloth
     * fuzz (sheen) flat, and $wetness feeds the reflections. Call it on the DRY
     * material each time - it compounds.
     * For soaked clothes, splashed rocks, a player out of the water.
     */
    public function withWetness(float $wetness): self
    {
        $w = max(0.0, min(1.0, $wetness));
        $m = $this->copy();
        $dark = 1.0 - 0.5 * $w * (1.0 - max(0.0, min(1.0, $this->metallic)));

        return new self(...[
            ...get_object_vars($m),
            'albedo' => new Color($this->albedo->r * $dark, $this->albedo->g * $dark, $this->albedo->b * $dark, $this->albedo->a),
            'roughness' => $this->roughness + (max($this->roughness * 0.35, 0.08) - $this->roughness) * $w,
            'sheen' => $this->sheen * (1.0 - 0.8 * $w),
            'wetness' => $w,
        ]);
    }

    /**
     * This material dissolving in a dither between two camera distances (shader
     * plan 6.1); end <= 0 switches the fade off.
     */
    public function withFadeDistance(float $start, float $end): self
    {
        $m = $this->copy();

        return new self(...[...get_object_vars($m), 'fadeDistance' => [max(0.0, $start), max(0.0, $end)]]);
    }

    /**
     * This material appearing between two camera distances with the dither
     * complementary to withFadeDistance() - the incoming level of a LOD
     * cross-fade (shader plan 9.1). Stored as a negative range.
     */
    public function withFadeInDistance(float $start, float $end): self
    {
        $m = $this->copy();

        return new self(...[...get_object_vars($m), 'fadeDistance' => [-max(0.0, $start), -max(1e-3, $end)]]);
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

    /**
     * This material with further parameter values, merged by name.
     *
     * @param array<string, int|float|list<float>> $params
     */
    public function withParams(array $params): self
    {
        return $this->copy(params: [...$this->params, ...$params]);
    }

    /** This material with another reactive value (see {@see $reactive}), clamped to 0..1. */
    public function withReactive(float $reactive): self
    {
        return $this->copy(reactive: max(0.0, min(1.0, $reactive)));
    }

    /**
     * This material's albedo texture read sharper or more anisotropic (see {@see $mipBias},
     * {@see $anisotropy}); null keeps a value. Anisotropy is clamped to 0..16.
     */
    public function withTextureFilter(?float $mipBias = null, ?int $anisotropy = null): self
    {
        return $this->copy(mipBias: $mipBias, anisotropy: $anisotropy !== null ? max(0, min(16, $anisotropy)) : null);
    }

    /** This material with a body wave (see {@see BodyWave}); null removes it. */
    public function withBodyWave(?BodyWave $wave): self
    {
        return $this->copy(bodyWave: $wave, clearBodyWave: $wave === null);
    }

    /** This material with a tangent-space normal map (see {@see $normalTexture}); null removes it. */
    public function withNormalTexture(?string $textureId): self
    {
        return $this->copy(normalTexture: $textureId, clearNormalTexture: $textureId === null);
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

    /**
     * @param array<string, float|int|list<float>>|null $params
     */
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
        ?array $params = null,
        ?float $reactive = null,
        ?float $mipBias = null,
        ?int $anisotropy = null,
        ?BodyWave $bodyWave = null,
        bool $clearBodyWave = false,
        ?string $normalTexture = null,
        bool $clearNormalTexture = false,
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
            windTufts: $this->windTufts,
            params: $params ?? $this->params,
            reactive: $reactive ?? $this->reactive,
            mipBias: $mipBias ?? $this->mipBias,
            anisotropy: $anisotropy ?? $this->anisotropy,
            bodyWave: $clearBodyWave ? null : ($bodyWave ?? $this->bodyWave),
            softParticle: $this->softParticle,
            triplanar: $this->triplanar,
            weathering: $this->weathering,
            glass: $this->glass,
            glassDirt: $this->glassDirt,
            windowLights: $this->windowLights,
            neonTube: $this->neonTube,
            sheen: $this->sheen,
            brushed: $this->brushed,
            glitter: $this->glitter,
            fadeDistance: $this->fadeDistance,
            rimLight: $this->rimLight,
            hairShine: $this->hairShine,
            refraction: $this->refraction,
            frost: $this->frost,
            particleTint: $this->particleTint,
            flipbook: $this->flipbook,
            flipbookFps: $this->flipbookFps,
            streakFade: $this->streakFade,
            shadingRate: $this->shadingRate,
            vatTexture: $this->vatTexture,
            vatInfo: $this->vatInfo,
            alphaCutoff: $this->alphaCutoff,
            furLength: $this->furLength,
            furShells: $this->furShells,
            furDensity: $this->furDensity,
            hair: $this->hair,
            oceanTexture: $this->oceanTexture,
            oceanPatch: $this->oceanPatch,
            oceanAmplitude: $this->oceanAmplitude,
            oceanLoop: $this->oceanLoop,
            oceanChop: $this->oceanChop,
            tessellation: $this->tessellation,
            tessNear: $this->tessNear,
            tessFar: $this->tessFar,
            normalTexture: $clearNormalTexture ? null : ($normalTexture ?? $this->normalTexture),
            splatLayers: $this->splatLayers,
            splatArea: $this->splatArea,
            splatSharpness: $this->splatSharpness,
            breathing: $this->breathing,
            instanceTint: $this->instanceTint,
            shadowDapple: $this->shadowDapple,
            shaderProfile: $this->shaderProfile,
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
