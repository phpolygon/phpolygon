<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Runtime\PerfProfiler;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\AddSpotLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetFog;
use PHPolygon\Rendering\Command\SetShader;
use PHPolygon\Rendering\Command\SetSky;
use PHPolygon\Rendering\Command\SetSkybox;
use PHPolygon\Rendering\Command\SetWaveAnimation;
use PHPolygon\Rendering\Command\SetWind;
use PHPolygon\Rendering\PostProcess\VioFxaaPass;
use PHPolygon\Rendering\PostProcess\VioShadowDebugPass;
use PHPolygon\Rendering\Quality\AntiAliasing;
use VioContext;
use VioCubemap;
use VioMesh;
use VioPipeline;
use VioRenderTarget;
use VioShader;
use VioTexture;

/**
 * 3D renderer backend using the vio extension.
 *
 * Processes RenderCommandList using vio_mesh, vio_shader, vio_pipeline,
 * vio_set_uniform, and vio_draw. Supports Blinn-Phong lighting, fog,
 * shadow mapping, texture sampling, GPU instancing, and transparency.
 */
class VioRenderer3D implements Renderer3DInterface
{
    private int $width;
    private int $height;

    /** @var array<string, VioMesh> */
    private array $meshCache = [];

    /** @var array<string, int> Last seen MeshRegistry::version per cached mesh id — used to detect re-registered (dynamic / skinned) meshes and re-upload them. */
    private array $meshCacheVersions = [];

    /** @var array<string, VioShader> */
    private array $shaderCache = [];

    /** @var array<string, VioPipeline> */
    private array $pipelineCache = [];

    /** @var array<string, VioTexture> Texture ID -> VioTexture */
    private array $textureCache = [];

    /** @var array<string, string> Cache key -> packed binary instance matrices */
    private array $staticMatrixCache = [];

    /** @var array<string, int> Cache key -> instance count */
    private array $staticInstanceCountCache = [];

    private ?string $shaderOverride = null;

    /**
     * Per-draw state-dedup trackers: the materialId / meshId whose uniforms are
     * currently resident in the bound shader's cbuffer. Because vio uniforms are
     * sticky, the draw path skips re-uploading material uniforms / mesh AABB when
     * these match the next draw. Reset (null) at every pass boundary and whenever
     * the bound shader object changes (bindPipeline) — see resetDrawStateCache().
     */
    private ?string $lastMaterialId = null;
    private ?string $lastMeshId = null;

    /**
     * The shader id whose per-frame uniforms (lights/fog/shadow/ssao/sdfao +
     * their texture binds) are currently resident in the cbuffer this frame.
     * Set by the opaque pass; the transparent pass skips re-uploading the
     * identical frame state when the shader object is unchanged (sticky cbuffer,
     * same shader). The opaque pass always refreshes this before the transparent
     * pass reads it, so it never goes stale across frames.
     */
    private ?string $frameUniformsShaderId = null;

    /** @var array<string, int> Material prefix → proc_mode cache */
    private static array $procModeCache = [];

    private ?Mat4 $currentViewMatrix = null;
    private ?Mat4 $currentProjectionMatrix = null;
    private ?Vec3 $cameraPosition = null;

    private float $globalTime = 0.0;

    // ── Flash-hunt diagnostics (PHPOLYGON_FLASH_DEBUG=1) ──────────────
    private ?bool $flashDbg = null;
    /** @var array<string, float|int> previous frame's uniform signature */
    private array $flashDbgPrev = [];
    private bool $flashDbgHadShadow = false;
    private int $flashDbgCasters = -1;

    private function flashDbgEnabled(): bool
    {
        return $this->flashDbg ??= (getenv('PHPOLYGON_FLASH_DEBUG') === '1');
    }
    private float $snowCover = 0.0;

    // Post-processing
    private ?VioRenderTarget $hdrTarget = null;
    private ?VioRenderTarget $bloomExtractTarget = null;
    private ?VioRenderTarget $bloomPingTarget = null;
    private ?VioRenderTarget $bloomPongTarget = null;
    private ?VioMesh $screenQuad = null;
    private bool $enableHdr = false;
    private float $bloomIntensity = 0.40;
    // Real-HDR threshold in LINEAR scene-luma. The scene renders into a LINEAR
    // FP16 target and the resolve tonemaps it, so "bright" = linear luma. Bright
    // sunlit DIFFUSE surfaces are not as dim as first assumed: measured peak sand
    // sits at linear luma ~0.87 (its red channel alone is ~1.4!), so the original
    // 0.9 threshold sat right ON the bright-sand edge and let large diffuse areas
    // creep into bloom — the "too bright / washed-out" look. Genuine highlights
    // are far higher (the white plaza lantern measures linear luma ~3.6). 1.3
    // (+0.5 soft knee in bloom_extract) cleanly separates the two: diffuse sand /
    // walls / sky stay crisp, only true overbrights (lantern, sun disc, glints,
    // sun-glittered water, neon) glow. See renderBloom() / bloom_extract.frag.glsl.
    private float $bloomThreshold = 1.3;
    // Resolve exposure. Kept at 1.0 so the HDR resolve's ACES+gamma reproduces
    // the old inline-tonemap LDR baseline EXACTLY for geometry (old path:
    // ACES(raw); new path: ACES(raw * exposure)). Bloom is added on top, so the
    // base image brightness/contrast is unchanged — "same scene, but highlights
    // now actually bloom". Raise above 1.0 only to deliberately brighten.
    private float $exposure = 1.0;
    /** Current half-res bloom-target dimensions (rebuilt on backbuffer resize). */
    private int $bloomWidth = 0;
    private int $bloomHeight = 0;
    /** Whether the bloom targets are currently FP16 (HDR scene path). */
    private bool $bloomTargetsHdr = false;

    // SSAO (real depth+normal screen-space AO; VIO/D3D12 only — see
    // renderSsaoPass()). The G-buffer is full-res RGBA16F (view normal + linear
    // depth); the SSAO and blur targets are HALF-res (cheaper, the result is
    // blurred anyway). All three are rebuilt on backbuffer resize alongside the
    // bloom targets. ssaoActiveThisFrame caches the per-frame gate so the opaque
    // pass binds the right AO texture without re-evaluating it.
    private ?VioRenderTarget $gbufferTarget = null;
    private ?VioRenderTarget $ssaoTarget = null;
    private ?VioRenderTarget $ssaoBlurTarget = null;
    private int $gbufferWidth = 0;
    private int $gbufferHeight = 0;
    private bool $ssaoActiveThisFrame = false;
    /** 1x1 white texture bound to u_ssao_map when SSAO is off (never leave the AO sampler unbound on D3D12). */
    private ?VioTexture $whiteTexture = null;
    /** GL texture unit wired to u_ssao_map. Distinct from albedo (0) and shadows (6,7,8,9). */
    private const SSAO_SAMPLER_SLOT = 1;

    // Fieldtracing SDF trace pass (SdfOcclusion / SdfBounce tiers; D3D only, like
    // SSAO). renderSdfAoPass() reconstructs world pos+normal from the G-buffer,
    // samples the baked SDF volume for AO + soft sun-shadow, and writes them to
    // sdfAoTarget (R = AO, G = shadow). The mesh shader samples it at slot 2.
    private ?VioRenderTarget $sdfAoTarget = null;
    private int $sdfAoWidth = 0;
    private int $sdfAoHeight = 0;
    private bool $sdfAoActiveThisFrame = false;
    /** GL texture unit wired to u_sdf_ao_map. Distinct from albedo(0), ssao(1), shadows(6-9). */
    private const SDF_AO_SAMPLER_SLOT = 2;

    // The baked SDF volume (vio_texture_3d), uploaded from SetFieldtracingVolume.
    private ?VioTexture $sdfVolumeTex = null;
    private int $sdfVolumeVersion = -1;
    private ?Vec3 $sdfVolOrigin = null;
    private ?Vec3 $sdfVolSize = null;
    private float $sdfVolRange = 4.0;
    /** Effective Fieldtracing tier for the current frame (set in render() pass 1). */
    private Quality\FieldtracingMode $frameFtMode = Quality\FieldtracingMode::Off;

    /** Mesh-pass texture units (logical; vio name-maps them to HLSL registers).
     *  Distinct from albedo(0), ssao(1), sdf_ao(2), shadows(6,8,9). The coloured
     *  probe field uses three 3D textures (R/G/B SH-L1 coeffs). */
    private const PROBE_R_SLOT = 3;
    private const PROBE_G_SLOT = 4;
    private const PROBE_B_SLOT = 5;

    /** Logical unit + registry id for the reflection-probe cubemap (mesh pass). */
    private const ENV_CUBEMAP_SLOT = 7;
    private const ENV_CUBEMAP_ID = 'reflection_probe';

    // The baked coloured irradiance probe field (3× vio_texture_3d), from
    // SetFieldtracingProbes — one 3D texture per colour channel.
    private ?VioTexture $probeTexR = null;
    private ?VioTexture $probeTexG = null;
    private ?VioTexture $probeTexB = null;
    private int $probeFieldVersion = -1;
    private ?Vec3 $probeOrigin = null;
    private ?Vec3 $probeSize = null;
    private float $probeRange = 3.0;

    // SSR (real screen-space reflections; VIO/D3D12 only — see renderSsrPass()).
    // The pass reuses the FP16 G-buffer (view normal in rg, reflectivity in b,
    // linear depth in a) that the SSAO pass already produces, ray-marches it
    // against the HDR scene colour, and composites the reflection back into the
    // scene offscreen target BEFORE bloom/tonemap. Full-res FP16 (HDR reflected
    // colour). Rebuilt on backbuffer resize. The composite blends into the scene
    // target while sampling this separate target (no read+write hazard).
    private ?VioRenderTarget $ssrTarget = null;
    private int $ssrWidth = 0;
    private int $ssrHeight = 0;

    // Shadow map
    private ?VioRenderTarget $shadowTarget = null;
    /** @var array<int, VioRenderTarget> Per-cascade shadow render targets. */
    private array $cascadeShadowTargets = [];
    /** @var array<int, Mat4> Per-cascade light-space matrices. */
    private array $cascadeLightSpaceMatrices = [];
    /**
     * A 1x1 depth render target wired to every sampler2DShadow slot in the
     * mesh3d fragment shader when no real shadow map is active. Without this
     * macOS OpenGL silently aborts the fragment stage and the frame stays
     * at the headless-fbo clear colour (~0.098 grey). See
     * uploadShadowUniforms() for the binding logic.
     */
    private ?VioRenderTarget $dummyShadowTarget = null;
    /** Per-cascade ortho-box half-extents (matches OpenGLRenderer3D). */
    private const CASCADE_ORTHO_SIZES = [15.0, 50.0, 150.0];
    private const SHADOW_MAP_RESOLUTION = 2048;
    private const SHADOW_ORTHO_SIZE = 60.0;

    /**
     * Deferred GPU-resource reallocation flags, set by applySettings() and
     * consumed at the next beginFrame() boundary.
     *
     * RATIONALE (D3D12 resource lifetime): applySettings() is called from the
     * game's onRender() callback, which runs AFTER the 3D scene pass has already
     * recorded draws (OMSetRenderTargets + barriers) referencing the offscreen /
     * shadow render targets into the frame's still-open, not-yet-executed command
     * list. Releasing + reallocating those targets synchronously there frees an
     * ID3D12Resource the in-flight command list still points at — the GPU then
     * executes commands against freed memory (DXGI_ERROR_DEVICE_REMOVED / 0xC0000005).
     *
     * Instead we only record intent here and perform the actual release/realloc at
     * the START of beginFrame(), BEFORE vio_begin() opens the next command list and
     * before anything is bound. That is a safe boundary: the previous frame has been
     * submitted (and presented), and php-vio's d3d12_destroy_render_target performs a
     * full vio_d3d12_wait_for_gpu() before ID3D12Resource_Release(), so the prior
     * frame's GPU work that referenced the target is guaranteed complete before the
     * resource is freed. Harmless on OpenGL/Vulkan/Metal (no command-list-lifetime
     * hazard there) — the deferral just moves the realloc one boundary earlier.
     */
    private bool $offscreenDirty = false;
    private bool $shadowDirty = false;

    /** Source directory for Vio shader programs. Loaded at init time via loadShader(). */
    private const SHADER_DIR = __DIR__ . '/../../resources/shaders/source/vio/';

    // Skybox / cubemaps
    private ?VioMesh $skyboxMesh = null;
    /**
     * Cached GPU cubemaps, paired with the registry source object they were
     * uploaded from. When DayNightSystem regenerates the procedural sky,
     * CubemapRegistry hands out a new CubemapData instance and loadCubemap()
     * re-uploads instead of returning the stale GPU texture.
     *
     * @var array<string, array{cubemap: VioCubemap, source: object|null}>
     */
    private array $cubemapCache = [];
    private ?string $pendingSkyboxId = null;
    private ?SetSky $pendingSky = null;
    private float $rainWetness = 0.0;

    /** Current wind state, overwritten by SetWind dispatch. */
    /** @var array{0:float,1:float,2:float} */
    private array $windDirection = [0.0, 0.0, 1.0];
    private float $windIntensity = 0.5;

    /**
     * @var array<string, array{min: array{0:float,1:float,2:float}, max: array{0:float,1:float,2:float}}>
     *      Per-mesh local AABB cache. Computed on first upload via
     *      meshAabb(); drives the cloth-sway anchor weighting.
     */
    private array $meshAabbCache = [];

    /**
     * Per-material "does this cast a shadow?" cache (hoists the sky_/sun_/…
     * prefix test out of the 3×-per-frame shadow cascade loop).
     *
     * @var array<string, bool>
     */
    private array $castsShadowCache = [];

    private ?VioTextureManager $textureManager = null;

    /**
     * Live graphics settings. applySettings() may be called at any time to
     * hot-swap shadow tier, view-distance clamp, fog, cloud-shadows, anisotropy
     * and the global shader override (the latter is propagated through
     * ShaderManager + SetShader rather than directly here).
     */
    private GraphicsSettings $settings;

    /**
     * Phase 1.5 off-screen pipeline: render the 3D pass into a scaled vio
     * render target and present via FXAA or a passthrough blit. Stays null
     * for the fast path (renderScale == 1.0 AND antiAliasing == Off).
     */
    private ?VioOffscreenTarget $offscreenTarget = null;

    /** Lazy FXAA post-process pass. Allocated when AntiAliasing == Fxaa. */
    private ?VioFxaaPass $fxaaPass = null;

    /** Lazy shadow-map raw-depth debug blit. Allocated when PHPOLYGON_DEBUG_SHADOWMAP=1. */
    private ?VioShadowDebugPass $shadowDebugPass = null;

    /**
     * True when the current frame is being rendered into the offscreen target
     * rather than directly into the backbuffer. Set by beginFrame(), read by
     * endFrame() to know whether to run the present pass.
     */
    private bool $offscreenActive = false;

    /** Backbuffer resolution captured at frame start (for blit destination). */
    private int $backbufferWidth = 0;
    private int $backbufferHeight = 0;

    private ?BackendConventions $conventions = null;

    public function __construct(
        private readonly VioContext $ctx,
        int $width = 1280,
        int $height = 720,
        ?GraphicsSettings $settings = null,
    ) {
        $this->width = $width;
        $this->height = $height;
        $this->settings = $settings ?? new GraphicsSettings();
        $this->initShaders();
        $this->initShadowMap();
        $this->initSkyboxMesh();
        $this->initPostProcess();
    }

    /**
     * The active vio rendering context. Exposed so load-time GPU compute work
     * (e.g. the fieldtracing SDF bake via {@see \PHPolygon\Fieldtracing\GpuSdfBaker})
     * can dispatch on the same device the renderer draws with.
     */
    public function vioContext(): VioContext
    {
        return $this->ctx;
    }

    /**
     * Rendering conventions for the active vio backend. Cached — the backend
     * cannot change for the lifetime of a context.
     */
    private function conventions(): BackendConventions
    {
        return $this->conventions ??= BackendConventions::forBackend(vio_backend_name($this->ctx));
    }

    public function applySettings(GraphicsSettings $settings): void
    {
        $previous = $this->settings;
        $this->settings = $settings;

        // Bloom toggle is read live from $this->settings during render.
        $this->enableHdr = $this->enableHdr && $settings->bloom;

        // Resource (re)allocation is DEFERRED to the next beginFrame() boundary,
        // never performed synchronously here. applySettings() can be called from
        // the game's onRender() callback mid-frame, while the frame's command list
        // is still open and references the offscreen / shadow render targets. On
        // D3D12 releasing those resources here is a use-after-free that removes the
        // device (see the $offscreenDirty / $shadowDirty field docs). We only flag
        // intent; beginFrame() does the release/realloc at a safe point.

        // Shadow-map tier change → rebuild the shadow targets next beginFrame().
        if ($previous->shadowQuality !== $settings->shadowQuality) {
            $this->shadowDirty = true;
        }

        // Render-scale / AA / bloom / HDR all change the offscreen scene target's
        // size, sample count, or pixel format (see resizeOffscreenIfNeeded /
        // offscreenIsActive / offscreenIsHdr), or flip the offscreen pipeline on
        // or off entirely. HDR flips the offscreen target FORMAT (FP16 ↔ RGBA8);
        // the realloc is deferred to applyDeferredSettings() at beginFrame so the
        // format change never frees mid-frame. Any of these means the offscreen
        // target must be rebuilt (or released) at the next safe boundary.
        if ($previous->renderScale !== $settings->renderScale
            || $previous->antiAliasing !== $settings->antiAliasing
            || $previous->bloom !== $settings->bloom
            || $previous->hdr !== $settings->hdr) {
            $this->offscreenDirty = true;
        }
    }

    /**
     * Capability-gate a requested Fieldtracing tier. ProbesOnly needs no GPU
     * feature (analytic hemisphere ambient in the mesh shader). SdfOcclusion /
     * SdfBounce sample a baked SDF volume (3D texture) via the separate trace
     * pass; on a backend without 3D-texture support they degrade to the highest
     * runnable tier. Gating is against capabilities, never backend names.
     */
    private function gateFieldtracing(Quality\FieldtracingMode $mode): Quality\FieldtracingMode
    {
        while (($mode === Quality\FieldtracingMode::SdfOcclusion
                || $mode === Quality\FieldtracingMode::SdfBounce)
               && !$this->supportsTexture3D()) {
            $mode = $mode->degraded();
        }
        return $mode;
    }

    private function supportsTexture3D(): bool
    {
        return defined('VIO_FEATURE_TEXTURE_3D')
            && vio_supports_feature($this->ctx, VIO_FEATURE_TEXTURE_3D);
    }

    private function fieldtracingModeCode(Quality\FieldtracingMode $mode): int
    {
        return match ($mode) {
            Quality\FieldtracingMode::Off          => 0,
            Quality\FieldtracingMode::ProbesOnly   => 1,
            Quality\FieldtracingMode::SdfOcclusion => 2,
            Quality\FieldtracingMode::SdfBounce    => 3,
        };
    }

    /**
     * Upload a baked SDF volume (SetFieldtracingVolume) to a 3D texture, caching
     * by version so re-emitting the command every frame is cheap. Stores the
     * world transform for the trace pass.
     */
    private function ingestSdfVolume(Command\SetFieldtracingVolume $cmd): void
    {
        $this->sdfVolOrigin = $cmd->origin;
        $this->sdfVolSize   = $cmd->size;
        $this->sdfVolRange  = $cmd->range;

        if ($this->sdfVolumeTex !== null && $this->sdfVolumeVersion === $cmd->version) {
            return; // unchanged — keep the cached upload
        }
        if (!function_exists('vio_texture_3d') || !$this->supportsTexture3D()) {
            return; // backend can't store a 3D volume; tier degrades to ProbesOnly
        }

        $tex = vio_texture_3d($this->ctx, [
            'data'   => $cmd->data,
            'width'  => $cmd->width,
            'height' => $cmd->height,
            'depth'  => $cmd->depth,
            'filter' => VIO_FILTER_LINEAR,
            'wrap'   => VIO_WRAP_CLAMP,
        ]);
        if ($tex !== false) {
            $this->sdfVolumeTex = $tex;
            $this->sdfVolumeVersion = $cmd->version;
        }
    }

    /**
     * Upload a baked coloured irradiance probe field (SetFieldtracingProbes) to
     * three 3D textures (R/G/B SH-L1 coeffs), cached by version. Sampled in the
     * mesh shader (ProbesOnly+ tiers), so it works on any backend with 3D-texture
     * support, not just D3D.
     */
    private function ingestProbeField(Command\SetFieldtracingProbes $cmd): void
    {
        $this->probeOrigin = $cmd->origin;
        $this->probeSize   = $cmd->size;
        $this->probeRange  = $cmd->range;

        if ($this->probeTexR !== null && $this->probeFieldVersion === $cmd->version) {
            return;
        }
        if (!function_exists('vio_texture_3d') || !$this->supportsTexture3D()) {
            return;
        }

        $upload = fn(string $data): VioTexture|false => vio_texture_3d($this->ctx, [
            'data'   => $data,
            'width'  => $cmd->width,
            'height' => $cmd->height,
            'depth'  => $cmd->depth,
            'filter' => VIO_FILTER_LINEAR,
            'wrap'   => VIO_WRAP_CLAMP,
        ]);

        $r = $upload($cmd->dataR);
        $g = $upload($cmd->dataG);
        $b = $upload($cmd->dataB);
        if ($r !== false && $g !== false && $b !== false) {
            $this->probeTexR = $r;
            $this->probeTexG = $g;
            $this->probeTexB = $b;
            $this->probeFieldVersion = $cmd->version;
        }
    }

    /** SdfOcclusion / SdfBounce trace pass runs only on D3D with a volume present. */
    private function sdfAoEnabledThisFrame(): bool
    {
        if (getenv('PHPOLYGON_SDF_AO') === '0') {
            return false;
        }
        return ($this->frameFtMode === Quality\FieldtracingMode::SdfOcclusion
                || $this->frameFtMode === Quality\FieldtracingMode::SdfBounce)
            && $this->sdfVolumeTex !== null
            && $this->sdfVolOrigin !== null
            && $this->sdfVolSize !== null
            && $this->conventions()->isDirect3D();
    }

    private function ensureSdfAoTarget(): void
    {
        $w = max(1, $this->backbufferWidth);
        $h = max(1, $this->backbufferHeight);
        if ($this->sdfAoTarget !== null && $this->sdfAoWidth === $w && $this->sdfAoHeight === $h) {
            return;
        }
        $this->sdfAoTarget = vio_render_target($this->ctx, ['width' => $w, 'height' => $h]) ?: null;
        $this->sdfAoWidth = $w;
        $this->sdfAoHeight = $h;
    }

    /**
     * Screen-space SDF AO + soft sun-shadow. Reconstructs world position/normal
     * from the G-buffer, samples the baked SDF volume, writes R=AO, G=shadow to
     * sdfAoTarget. The mesh shader samples it during the opaque/transparent pass.
     *
     * @param array{dirLights: list<SetDirectionalLight>, ftAoRadius: float, ...} $frameState
     */
    private function renderSdfAoPass(array $frameState): void
    {
        $this->sdfAoActiveThisFrame = false;

        if (!$this->sdfAoEnabledThisFrame()) {
            return;
        }

        $gbuffer = $this->gbufferTarget;
        if ($gbuffer === null || $this->screenQuad === null
            || $this->currentProjectionMatrix === null || $this->currentViewMatrix === null
            || $this->sdfVolumeTex === null || $this->sdfVolOrigin === null || $this->sdfVolSize === null) {
            return;
        }

        // Capture into locals before any method call (which would void the
        // null-narrowing PHPStan derived from the guard above).
        $quad    = $this->screenQuad;
        $volTex  = $this->sdfVolumeTex;
        $origin  = $this->sdfVolOrigin;
        $size    = $this->sdfVolSize;
        $proj    = $this->currentProjectionMatrix->toArray();
        $invView = $this->currentViewMatrix->inverse()->toArray();

        $this->ensureSdfAoTarget();
        $target = $this->sdfAoTarget;
        if ($target === null) {
            return;
        }

        $w = max(1, $this->sdfAoWidth);
        $h = max(1, $this->sdfAoHeight);
        $uvFlipY = $this->conventions()->flipRenderTargetClipY() ? -1.0 : 1.0;

        // Sun direction (toward the sun) from the primary directional light.
        $sun = [0.0, 1.0, 0.0];
        if (isset($frameState['dirLights'][0])) {
            $d = $frameState['dirLights'][0]->direction;
            $len = sqrt($d->x * $d->x + $d->y * $d->y + $d->z * $d->z) ?: 1.0;
            $sun = [-$d->x / $len, -$d->y / $len, -$d->z / $len];
        }

        vio_bind_render_target($this->ctx, $target);
        vio_viewport($this->ctx, 0, 0, $w, $h);
        vio_clear($this->ctx, 1, 1, 0, 1);
        $this->bindPostProcessPipeline('sdf_ao');

        // The 3D volume goes to slot 0 and the 2D G-buffer to slot 1: binding the
        // sampler3D as the FIRST sampler matches the working standalone volume
        // pipeline; mixing it after a sampler2D mis-binds on the D3D sampler table.
        vio_bind_texture($this->ctx, $volTex, 0);
        vio_set_uniform($this->ctx, 'u_sdf_volume', 0);
        vio_bind_texture($this->ctx, vio_render_target_texture($gbuffer), 1);
        vio_set_uniform($this->ctx, 'u_gbuffer', 1);

        vio_set_uniform($this->ctx, 'u_proj00', $proj[0]);
        vio_set_uniform($this->ctx, 'u_proj11', $proj[5]);
        vio_set_uniform($this->ctx, 'u_uv_flip_y', $uvFlipY);
        vio_set_uniform($this->ctx, 'u_inv_view', $invView);
        vio_set_uniform($this->ctx, 'u_sun_dir', $sun);
        vio_set_uniform($this->ctx, 'u_vol_origin', [$origin->x, $origin->y, $origin->z]);
        vio_set_uniform($this->ctx, 'u_vol_size', [$size->x, $size->y, $size->z]);
        vio_set_uniform($this->ctx, 'u_vol_range', $this->sdfVolRange);
        vio_set_uniform($this->ctx, 'u_ao_radius', (float) $frameState['ftAoRadius']);
        vio_draw($this->ctx, $quad);
        vio_unbind_render_target($this->ctx);

        $this->sdfAoActiveThisFrame = true;
    }

    /** Bind the SDF-AO screen map (or white fallback) for the mesh pass. */
    private function uploadSdfAoUniforms(): void
    {
        $enabled = $this->sdfAoActiveThisFrame && $this->sdfAoTarget !== null;
        if ($enabled) {
            $tex = vio_render_target_texture($this->sdfAoTarget);
        } else {
            $this->ensureWhiteTexture();
            $tex = $this->whiteTexture;
        }
        if ($tex !== null) {
            vio_bind_texture($this->ctx, $tex, self::SDF_AO_SAMPLER_SLOT);
            vio_set_uniform($this->ctx, 'u_sdf_ao_map', self::SDF_AO_SAMPLER_SLOT);
        }
        vio_set_uniform($this->ctx, 'u_sdf_ao_enabled', $enabled ? 1.0 : 0.0);
    }

    /**
     * Perform any deferred render-target release/realloc requested by
     * applySettings(). Runs at the START of beginFrame(), BEFORE vio_begin()
     * opens the next frame's command list and before anything is bound — the
     * only point where the previous frame's GPU work (which referenced these
     * targets) is guaranteed submitted and php-vio's destroy path can safely
     * wait_for_gpu() before freeing the underlying ID3D12Resource.
     *
     * See the $offscreenDirty / $shadowDirty field documentation for the full
     * D3D12 resource-lifetime rationale.
     */
    private function applyDeferredSettings(): void
    {
        if ($this->shadowDirty) {
            $this->shadowDirty = false;
            // initShadowMap() releases the old cascade targets (cascadeShadowTargets
            // = []) and allocates new ones at the current tier — or clears them when
            // the tier is Off. Doing it here (not lazily inside renderShadowPass)
            // keeps the free off the open command list.
            $this->initShadowMap();
        }

        if ($this->offscreenDirty) {
            $this->offscreenDirty = false;
            // resizeOffscreenIfNeeded() rebuilds the offscreen target to the new
            // size/samples/HDR format, or releases it when the offscreen pipeline
            // is no longer active. No-op until the backbuffer size is known.
            $this->resizeOffscreenIfNeeded();
        }
    }

    public function getSettings(): GraphicsSettings
    {
        return $this->settings;
    }

    public function setTextureManager(VioTextureManager $textureManager): void
    {
        $this->textureManager = $textureManager;
    }

    /**
     * Decide whether the offscreen pipeline is needed and bind the target.
     * Called from beginFrame() so the 3D pass renders into the offscreen
     * render target. Returns silently when the fast path applies
     * (renderScale == 1.0 AND AntiAliasing == Off), keeping behaviour
     * identical to pre-Phase-1.5 frames at default settings.
     */
    private function beginOffscreenIfRequired(): void
    {
        if (!$this->offscreenIsActive()) {
            $this->offscreenActive = false;
            return;
        }

        $this->ensureOffscreenTarget();
        if ($this->offscreenTarget === null || !$this->offscreenTarget->isAllocated()) {
            $this->offscreenActive = false;
            return;
        }

        if ($this->settings->antiAliasing === AntiAliasing::Fxaa && $this->fxaaPass === null) {
            $this->fxaaPass = new VioFxaaPass($this->ctx);
        }

        $this->offscreenTarget->bindForDraw();
        vio_viewport($this->ctx, 0, 0, $this->offscreenTarget->width(), $this->offscreenTarget->height());
        $this->offscreenActive = true;
    }

    /**
     * Recompute target size when applySettings() flips render scale or AA mode.
     * No-op until the backbuffer dimensions are known (typically after the
     * first beginFrame()).
     */
    private function resizeOffscreenIfNeeded(): void
    {
        if ($this->backbufferWidth <= 0 || $this->backbufferHeight <= 0) {
            return;
        }

        if (!$this->offscreenIsActive()) {
            $this->offscreenTarget?->release();
            return;
        }

        $this->ensureOffscreenTarget();
    }

    private function ensureOffscreenTarget(): void
    {
        if ($this->backbufferWidth <= 0 || $this->backbufferHeight <= 0) {
            return;
        }

        if ($this->offscreenTarget === null) {
            $this->offscreenTarget = new VioOffscreenTarget($this->ctx);
        }

        $targetW = max(1, (int)round($this->backbufferWidth  * $this->settings->renderScale));
        $targetH = max(1, (int)round($this->backbufferHeight * $this->settings->renderScale));
        $samples = max(1, $this->settings->antiAliasing->sampleCount());

        $this->offscreenTarget->resize($targetW, $targetH, $samples, $this->offscreenIsHdr());
    }

    /**
     * Whether the offscreen scene target should be FP16 (linear-HDR) this frame.
     *
     * True only on the Direct3D backends, where the php-vio pipeline 'hdr' flag
     * makes the PSO RTV format FP16 to match the target (see bindPipeline() and
     * the SSAO G-buffer path). On OpenGL/Vulkan/Metal we keep the legacy RGBA8
     * offscreen target and inline-tonemapped geometry — flipping those to FP16
     * would need the same per-pipeline format plumbing and can't be runtime
     * tested here, so their behaviour stays exactly as before.
     *
     * Player-controlled: HDR is now an explicit graphics setting
     * ($settings->hdr), no longer derived from bloom. HDR on → FP16 scene +
     * tonemap-on-resolve, so bloom (if enabled) is real HDR bloom extracted from
     * the unclamped highlights. HDR off → legacy RGBA8 offscreen with inline
     * tonemapping (u_linear_output=0), so bloom (if enabled) is the cheaper LDR
     * bloom. Render-scale / FXAA still drive the offscreen pipeline independently.
     */
    private function offscreenIsHdr(): bool
    {
        if (getenv('PHPOLYGON_VIO_HDR') === '0') {
            return false; // escape hatch: force the legacy LDR offscreen target
        }
        return $this->settings->hdr && $this->conventions()->isDirect3D();
    }

    private function offscreenIsActive(): bool
    {
        // The render-scale + AA offscreen pipeline. It was hard-disabled for a
        // long time because the present blit rendered black — the real cause was
        // a php-vio bug: vio_pipeline built the vertex input layout from shader
        // reflection WITHOUT sorting by location, so postprocess.vert's
        // (a_uv@loc1, a_position@loc0) got swapped offsets and the fullscreen
        // quad collapsed to a point. Fixed in php-vio (sort by location) plus the
        // screenQuad UV V-flip, so the pipeline is correct now and runs whenever
        // AA or render-scale actually need an intermediate target. Escape hatch:
        // PHPOLYGON_VIO_OFFSCREEN=0 forces the legacy direct-to-swapchain path.
        if (getenv('PHPOLYGON_VIO_OFFSCREEN') === '0') {
            return false;
        }
        // Any effect that needs an intermediate scene texture forces the
        // offscreen path: FXAA/TAA, render-scale, or bloom (which extracts from
        // the rendered scene). Each is individually toggleable via GraphicsSettings.
        return $this->settings->antiAliasing !== AntiAliasing::Off
            || $this->settings->renderScale !== 1.0
            || $this->settings->bloom;
    }

    /**
     * Present the offscreen target to the swapchain at backbuffer resolution.
     *
     * For AntiAliasing::Fxaa we run a fullscreen FXAA pass that samples the
     * offscreen colour. For other AA modes (or AA::Off with renderScale != 1)
     * we use a passthrough blit shader; the bilinear sampler handles the
     * up/downscale implicitly when source and destination dimensions differ.
     *
     * Caller (endFrame()) only invokes this when offscreenActive is true.
     */
    private function presentOffscreenIfActive(): void
    {
        if (!$this->offscreenActive) {
            return;
        }

        $target = $this->offscreenTarget;
        $quad   = $this->screenQuad;
        if ($target === null || $quad === null) {
            $this->offscreenActive = false;
            return;
        }

        $sceneTex = $target->texture();
        if ($sceneTex === null) {
            $this->offscreenActive = false;
            return;
        }

        // Unbind the offscreen target so subsequent draws hit the swapchain.
        $target->unbind();

        // Bloom (GraphicsSettings::$bloom): extract + blur the bright pixels of
        // the rendered scene; the present shader adds the glow back. Runs while
        // the offscreen colour is in shader-resource state (just unbound), and
        // leaves the swapchain bound for the present pass below.
        $bloomTex = $this->settings->bloom ? $this->renderBloom($sceneTex, $quad) : null;
        $post = $this->postFinishParams();

        vio_viewport($this->ctx, 0, 0, $this->backbufferWidth, $this->backbufferHeight);

        if ($this->settings->antiAliasing === AntiAliasing::Fxaa && $this->fxaaPass !== null) {
            $this->fxaaPass->apply(
                $sceneTex, $target->width(), $target->height(), $quad,
                $bloomTex, $this->bloomIntensity, $post,
            );
        } else {
            $this->bindPostProcessPipeline('passthrough_blit');
            vio_bind_texture($this->ctx, $sceneTex, 0);
            vio_set_uniform($this->ctx, 'u_source', 0);
            if ($bloomTex !== null) {
                vio_bind_texture($this->ctx, $bloomTex, 1);
                vio_set_uniform($this->ctx, 'u_bloom', 1);
                vio_set_uniform($this->ctx, 'u_bloom_intensity', $this->bloomIntensity);
            } else {
                vio_set_uniform($this->ctx, 'u_bloom_intensity', 0.0);
            }
            $this->setPostFinishUniforms($post);
            vio_draw($this->ctx, $quad);
        }

        $this->offscreenActive = false;
    }

    /**
     * Colour-grade + vignette parameters for the final present pass, from
     * GraphicsSettings. Grade and vignette are applied full-screen on the
     * composited image (geometry + sky + bloom) so they cover everything
     * uniformly — the mesh shader no longer bakes them per-fragment.
     *
     * @return array{lift: list<float>, gamma: list<float>, gain: list<float>, saturation: float, vignette: float, viewport: list<float>, hdr: int, exposure: float}
     */
    private function postFinishParams(): array
    {
        $g = $this->settings->colorGrading->params();
        return [
            'lift'       => $g['lift'],
            'gamma'      => $g['gamma'],
            'gain'       => $g['gain'],
            'saturation' => $g['saturation'],
            'vignette'   => (float) $this->settings->vignetteIntensity,
            'viewport'   => [(float) $this->backbufferWidth, (float) $this->backbufferHeight],
            // HDR resolve: when the offscreen scene was FP16 linear, the resolve
            // pass (passthrough/fxaa) must add bloom in linear, then exposure +
            // ACES + gamma BEFORE grade/vignette. On the LDR path these are off
            // and the resolve behaves byte-for-byte as before.
            'hdr'        => $this->sceneTargetIsHdr() ? 1 : 0,
            'exposure'   => $this->exposure,
        ];
    }

    /**
     * Upload the {@see postFinishParams} onto the currently bound present shader.
     *
     * @param array{lift: list<float>, gamma: list<float>, gain: list<float>, saturation: float, vignette: float, viewport: list<float>, hdr: int, exposure: float} $post
     */
    private function setPostFinishUniforms(array $post): void
    {
        vio_set_uniform($this->ctx, 'u_grade_lift', $post['lift']);
        vio_set_uniform($this->ctx, 'u_grade_gamma', $post['gamma']);
        vio_set_uniform($this->ctx, 'u_grade_gain', $post['gain']);
        vio_set_uniform($this->ctx, 'u_grade_saturation', $post['saturation']);
        vio_set_uniform($this->ctx, 'u_vignette_intensity', $post['vignette']);
        vio_set_uniform($this->ctx, 'u_viewport_size', $post['viewport']);
        vio_set_uniform($this->ctx, 'u_hdr_resolve', $post['hdr']);
        vio_set_uniform($this->ctx, 'u_exposure', $post['exposure']);
    }

    /**
     * Extract + two-pass-blur the bright pixels of the (tonemapped LDR) scene
     * into a half-res bloom buffer, returning the blurred texture for the
     * present pass to add. Returns null when the bloom targets are unavailable.
     * Leaves the swapchain bound (the final vio_unbind_render_target restores it).
     */
    private function renderBloom(VioTexture $sceneTex, VioMesh $quad): ?VioTexture
    {
        $this->ensureBloomTargets();
        $extract = $this->bloomExtractTarget;
        $ping = $this->bloomPingTarget;
        $pong = $this->bloomPongTarget;
        if ($extract === null || $ping === null || $pong === null) {
            return null;
        }
        $bw = max(1, $this->bloomWidth);
        $bh = max(1, $this->bloomHeight);
        // FP16 PSO variant when the bloom targets are HDR (matches the target on
        // D3D12). The scene texture is linear HDR on this path, so the bright
        // pass extracts the >threshold linear energy unclamped.
        $hdr = $this->bloomTargetsHdr;

        // Bright-pass extract: scene → extract.
        vio_bind_render_target($this->ctx, $extract);
        vio_viewport($this->ctx, 0, 0, $bw, $bh);
        vio_clear($this->ctx, 0, 0, 0, 1);
        $this->bindPostProcessPipeline('bloom_extract', $hdr);
        vio_bind_texture($this->ctx, $sceneTex, 0);
        vio_set_uniform($this->ctx, 'u_scene', 0);
        vio_set_uniform($this->ctx, 'u_threshold', $this->bloomThreshold);
        vio_draw($this->ctx, $quad);
        vio_unbind_render_target($this->ctx);

        // Horizontal blur: extract → ping.
        vio_bind_render_target($this->ctx, $ping);
        vio_viewport($this->ctx, 0, 0, $bw, $bh);
        vio_clear($this->ctx, 0, 0, 0, 1);
        $this->bindPostProcessPipeline('bloom_blur', $hdr);
        vio_bind_texture($this->ctx, vio_render_target_texture($extract), 0);
        vio_set_uniform($this->ctx, 'u_source', 0);
        vio_set_uniform($this->ctx, 'u_direction', [1.0 / $bw, 0.0]);
        vio_draw($this->ctx, $quad);
        vio_unbind_render_target($this->ctx);

        // Vertical blur: ping → pong.
        vio_bind_render_target($this->ctx, $pong);
        vio_viewport($this->ctx, 0, 0, $bw, $bh);
        vio_clear($this->ctx, 0, 0, 0, 1);
        $this->bindPostProcessPipeline('bloom_blur', $hdr);
        vio_bind_texture($this->ctx, vio_render_target_texture($ping), 0);
        vio_set_uniform($this->ctx, 'u_source', 0);
        vio_set_uniform($this->ctx, 'u_direction', [0.0, 1.0 / $bh]);
        vio_draw($this->ctx, $quad);
        vio_unbind_render_target($this->ctx);

        return vio_render_target_texture($pong);
    }

    /**
     * (Re)allocate the half-res bloom targets to match the current backbuffer.
     * initPostProcess() creates them at the splash resolution, so they must be
     * rebuilt once the real window size is known and whenever it changes.
     */
    private function ensureBloomTargets(): void
    {
        $bw = max(1, (int)($this->backbufferWidth / 2));
        $bh = max(1, (int)($this->backbufferHeight / 2));
        // FP16 bloom targets when the scene is HDR, so extracted highlights keep
        // their >1 energy through the blur for a soft, bright glow (RGBA8 would
        // clamp the bright pass back to 1.0 and flatten the bloom). Tracked so a
        // change in the HDR gate rebuilds them alongside a size change.
        $hdr = $this->sceneTargetIsHdr();
        if ($this->bloomExtractTarget !== null
            && $this->bloomWidth === $bw && $this->bloomHeight === $bh
            && $this->bloomTargetsHdr === $hdr) {
            return;
        }
        $cfg = ['width' => $bw, 'height' => $bh];
        if ($hdr) {
            $cfg['hdr'] = true;
        }
        $this->bloomExtractTarget = vio_render_target($this->ctx, $cfg) ?: null;
        $this->bloomPingTarget    = vio_render_target($this->ctx, $cfg) ?: null;
        $this->bloomPongTarget    = vio_render_target($this->ctx, $cfg) ?: null;
        $this->bloomWidth  = $bw;
        $this->bloomHeight = $bh;
        $this->bloomTargetsHdr = $hdr;
    }

    public function beginFrame(): void
    {
        $size = vio_framebuffer_size($this->ctx);
        if ($size[0] > 0 && $size[1] > 0) {
            $this->width = $size[0];
            $this->height = $size[1];
        }

        $this->backbufferWidth  = $this->width;
        $this->backbufferHeight = $this->height;

        $this->shaderOverride = null;
        $this->globalTime += 1.0 / 60.0;

        // Apply any settings change queued by applySettings() since the last
        // frame. MUST run here — before beginOffscreenIfRequired() binds the
        // offscreen target and before renderer2D->beginFrame()/vio_begin() opens
        // the command list — so releasing/reallocating GPU render targets never
        // happens while a frame's command list is open and still references them.
        $this->applyDeferredSettings();

        $this->beginOffscreenIfRequired();
    }

    public function endFrame(): void
    {
        $this->presentOffscreenIfActive();
        $this->renderShadowMapDebug();
        vio_draw_3d($this->ctx);
    }

    /**
     * Debug overlay (env PHPOLYGON_DEBUG_SHADOWMAP=1): blit each CSM cascade's
     * RAW stored depth into a tile across the top-left of the screen, using a
     * plain sampler2D (no PCF comparison). See {@see VioShadowDebugPass}. This
     * shows what the shadow map actually STORES, as opposed to the in-game disc
     * which is the comparison RESULT — the two answer different questions.
     */
    private function renderShadowMapDebug(): void
    {
        if (getenv('PHPOLYGON_DEBUG_SHADOWMAP') !== '1') {
            return;
        }
        $quad = $this->screenQuad;
        if ($quad === null || empty($this->cascadeShadowTargets)) {
            return;
        }
        $this->shadowDebugPass ??= new VioShadowDebugPass($this->ctx);

        $w = max(1, $this->backbufferWidth);
        $h = max(1, $this->backbufferHeight);

        // Square tiles in a row along the top edge. Placement is done in the
        // vertex shader via an NDC scale/offset (the fullscreen quad spans 2
        // NDC units = the full backbuffer; scale = tilePx / dimPx).
        $tilePx = (int) max(160, min(280, (int) ($w / 5)));
        $padPx  = 8;
        $scaleX = $tilePx / $w;
        $scaleY = $tilePx / $h;

        $i = 0;
        foreach ($this->cascadeShadowTargets as $target) {
            $tex = vio_render_target_texture($target);
            // Tile centre in pixels (top-left origin), then to NDC.
            $cx = $padPx + $tilePx / 2 + $i * ($tilePx + $padPx);
            $cy = $padPx + $tilePx / 2;
            $offsetX = $cx / $w * 2.0 - 1.0;
            $offsetY = 1.0 - $cy / $h * 2.0;
            $this->shadowDebugPass->draw($tex, $quad, [$scaleX, $scaleY, $offsetX, $offsetY]);
            $i++;
        }
    }

    public function clear(Color $color): void
    {
        vio_clear($this->ctx, $color->r, $color->g, $color->b, $color->a);
    }

    public function setViewport(int $x, int $y, int $width, int $height): void
    {
        $this->width = $width;
        $this->height = $height;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function render(RenderCommandList $commandList): void
    {
        $commands = $commandList->getCommands();

        if (getenv('VIO_DEBUG') === '1') {
            $cmdTypes = [];
            foreach ($commands as $c) {
                $cls = (new \ReflectionClass($c))->getShortName();
                $cmdTypes[$cls] = ($cmdTypes[$cls] ?? 0) + 1;
            }
            fprintf(STDERR, "[VioRenderer3D] render() called with %d commands: %s\n", count($commands), json_encode($cmdTypes));
        }

        // --- Pass 1: Collect state ---
        $ambientColor = new Color(0.1, 0.1, 0.1);
        $ambientIntensity = M_PI; // fallback matches the pre-2026-06 premultiplied brightness
        $dirLights = [];
        $pointLights = [];
        $spotLights = [];
        $fogColor = new Color(0.0, 0.0, 0.0);
        $fogNear = 1000.0;
        $fogFar = 2000.0;
        $waveEnabled = false;
        $waveAmplitude = 0.3;
        $waveFrequency = 0.5;
        $wavePhase = 0.0;

        // Fieldtracing: default to the persisted GraphicsSettings tier (the
        // renderer is the authority since applySettings() always feeds it). A
        // SetFieldtracing command in the list overrides it for this frame.
        $ftMode = $this->gateFieldtracing($this->settings->fieldtracing);
        $ftIntensity = 1.0;
        $ftAoRadius = 1.5;

        foreach ($commands as $cmd) {
            if ($cmd instanceof SetCamera) {
                $this->currentViewMatrix = $cmd->viewMatrix;
                $this->currentProjectionMatrix = $cmd->projectionMatrix;
                $this->cameraPosition = $this->extractCameraPosition($cmd->viewMatrix);
            } elseif ($cmd instanceof SetAmbientLight) {
                $ambientColor = $cmd->color;
                $ambientIntensity = $cmd->intensity;
            } elseif ($cmd instanceof SetDirectionalLight) {
                $dirLights[] = $cmd;
            } elseif ($cmd instanceof AddPointLight) {
                $pointLights[] = $cmd;
            } elseif ($cmd instanceof AddSpotLight) {
                $spotLights[] = $cmd;
            } elseif ($cmd instanceof SetFog) {
                if ($this->settings->fog) {
                    $fogColor = $cmd->color;
                    $fogFar = min($cmd->far, $this->settings->viewDistance);
                    $fogNear = min($cmd->near, max(0.0, $fogFar - 1.0));
                } else {
                    // Push fog out of range to neutralise it without altering the shader path.
                    $fogNear = 99998.0;
                    $fogFar = 99999.0;
                }
            } elseif ($cmd instanceof SetSkybox) {
                $this->pendingSkyboxId = $cmd->cubemapId;
            } elseif ($cmd instanceof SetSky) {
                $this->pendingSky = $cmd;
            } elseif ($cmd instanceof SetShader) {
                $this->shaderOverride = $cmd->shaderId;
            } elseif ($cmd instanceof Command\SetSnowCover) {
                $this->snowCover = $cmd->cover;
            } elseif ($cmd instanceof Command\SetGroundWetness) {
                $this->rainWetness = $cmd->rainWetness;
            } elseif ($cmd instanceof SetWaveAnimation) {
                $waveEnabled = $cmd->enabled;
                $waveAmplitude = $cmd->amplitude;
                $waveFrequency = $cmd->frequency;
                $wavePhase = $cmd->phase;
            } elseif ($cmd instanceof SetWind) {
                $this->windDirection = [
                    $cmd->direction->x, $cmd->direction->y, $cmd->direction->z,
                ];
                $this->windIntensity = $cmd->intensity;
            } elseif ($cmd instanceof Command\SetFieldtracing) {
                // Per-frame override of the settings-derived tier, capability-gated.
                $ftMode = $this->gateFieldtracing($cmd->mode);
                $ftIntensity = $cmd->intensity;
                $ftAoRadius = $cmd->aoRadius;
            } elseif ($cmd instanceof Command\SetFieldtracingVolume) {
                $this->ingestSdfVolume($cmd);
            } elseif ($cmd instanceof Command\SetFieldtracingProbes) {
                $this->ingestProbeField($cmd);
            }
        }

        if ($this->currentViewMatrix === null || $this->currentProjectionMatrix === null) {
            if (getenv('VIO_DEBUG') === '1') {
                fprintf(STDERR, "[VioRenderer3D] EARLY RETURN — no camera! viewMatrix=%s projMatrix=%s\n",
                    $this->currentViewMatrix === null ? 'null' : 'set',
                    $this->currentProjectionMatrix === null ? 'null' : 'set');
            }
            return;
        }

        $frameState = [
            'ambientColor' => $ambientColor,
            'ambientIntensity' => $ambientIntensity,
            'dirLights' => $dirLights,
            'pointLights' => $pointLights,
            'spotLights' => $spotLights,
            'fogColor' => $fogColor,
            'fogNear' => $fogNear,
            'fogFar' => $fogFar,
            'waveEnabled' => $waveEnabled,
            'waveAmplitude' => $waveAmplitude,
            'waveFrequency' => $waveFrequency,
            'wavePhase' => $wavePhase,
            'ftMode' => $ftMode,
            'ftIntensity' => $ftIntensity,
            'ftAoRadius' => $ftAoRadius,
        ];
        $this->frameFtMode = $ftMode;

        // --- Shadow pass ---
        PerfProfiler::begin('render3d.submit.shadow');
        $hasShadowMap = $this->renderShadowPass($commandList, $dirLights);
        PerfProfiler::end();
        if ($this->flashDbgEnabled() && $hasShadowMap !== $this->flashDbgHadShadow) {
            fprintf(STDERR, "[flashdbg] %s  shadow pass flipped: %s -> %s\n",
                date('H:i:s'),
                $this->flashDbgHadShadow ? 'ON' : 'OFF',
                $hasShadowMap ? 'ON' : 'OFF');
            $this->flashDbgHadShadow = $hasShadowMap;
        }

        // --- SSAO G-buffer + occlusion + blur pass ---
        // Runs only when the AO tier wants real SSAO and the backend supports it
        // (see ssaoEnabledThisFrame()). Binds/unbinds its OWN targets, exactly
        // like the shadow pass above, so it must run BEFORE the scene target is
        // bound below. Leaves $this->ssaoActiveThisFrame set for the opaque pass.
        PerfProfiler::begin('render3d.submit.ssao');
        $this->renderSsaoPass($commands, $frameState);
        PerfProfiler::end();
        PerfProfiler::begin('render3d.submit.sdfao');
        $this->renderSdfAoPass($frameState);
        PerfProfiler::end();

        // HDR/Bloom disabled — D3D11 fullscreen quad draw produces no pixels (needs investigation)
        $hdrTarget = $this->enableHdr ? $this->hdrTarget : null;

        if ($hdrTarget !== null) {
            vio_bind_render_target($this->ctx, $hdrTarget);
            vio_clear($this->ctx, 0, 0, 0, 1);
            $sceneViewportW = $this->width;
            $sceneViewportH = $this->height;
        } elseif ($this->offscreenActive && $this->offscreenTarget !== null) {
            // The shadow pass unbinds its own target; restore the Phase 1.5
            // offscreen target for the main scene draws.
            $this->offscreenTarget->bindForDraw();
            $sceneViewportW = $this->offscreenTarget->width();
            $sceneViewportH = $this->offscreenTarget->height();
        } else {
            $sceneViewportW = $this->width;
            $sceneViewportH = $this->height;
        }

        vio_viewport($this->ctx, 0, 0, $sceneViewportW, $sceneViewportH);

        static $vpDbg = false;
        if (!$vpDbg && getenv('VIO_DEBUG') === '1') {
            fprintf(STDERR, "[VioRenderer3D] viewport: %dx%d (offscreen=%s)\n",
                $sceneViewportW, $sceneViewportH, $this->offscreenActive ? 'yes' : 'no');
            $vpDbg = true;
        }

        // --- Atmospheric sky (fullscreen, depth test off, rendered first).
        // The fragment shader reconstructs a world-space view ray per pixel
        // from u_sky_inv_vp and evaluates the gradient + sun/moon analytically
        // — no skybox geometry. Opaque geometry overwrites wherever it draws.
        // Sky / skybox state is STICKY: the most recent SetSky / SetSkybox
        // persists across frames and is re-drawn every frame, even when no
        // command is issued this frame. This is required because the colour
        // buffer is cleared to depth-only and is filled by the sky — and a
        // game may emit SetSky from onUpdate(), which (under the fixed-timestep
        // loop) does NOT run on every rendered frame. Without persistence the
        // sky vanishes on sky-less frames, leaving an unfilled D3D12 flip
        // backbuffer → background flicker. Mirrors how camera / fog state is
        // retained between commands.
        if ($this->pendingSky !== null) {
            $this->renderAtmosphericSky($this->pendingSky);
        } elseif ($this->pendingSkyboxId !== null) {
            // Legacy cubemap skybox — only when no SetSky has ever been issued.
            $this->renderSkybox($this->pendingSkyboxId);
        }

        // --- Pass 2: Opaque geometry ---
        PerfProfiler::begin('render3d.submit.opaque');
        $this->bindPipeline('opaque');
        $this->uploadFrameUniforms($frameState);
        $this->uploadShadowUniforms($hasShadowMap, $dirLights);
        $this->uploadSsaoUniforms();
        $this->uploadSdfAoUniforms();
        $this->frameUniformsShaderId = $this->shaderOverride ?? 'default';

        // Collect opaque-eligible draws (resolving each material ONCE) and sort by
        // (materialId, meshId) so identical draws cluster — that makes the per-draw
        // material-uniform / mesh-AABB dedup in drawMeshCommand effective (a sorted
        // run re-uploads the ~28 material uniforms once per material instead of per
        // draw). Opaque is depth-buffer order-independent (VIO_BLEND_NONE), so
        // reordering cannot change the image.
        $opaque = [];
        foreach ($commands as $cmd) {
            if ($cmd instanceof DrawMesh || $cmd instanceof DrawMeshInstanced) {
                $material = MaterialRegistry::get($cmd->materialId);
                if ($material === null || $material->alpha < 1.0) {
                    continue;
                }
                $opaque[] = [$cmd->materialId, $cmd->meshId, $cmd, $material];
            }
        }
        usort($opaque, static fn (array $a, array $b): int => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]));
        foreach ($opaque as [, , $cmd, $material]) {
            if ($cmd instanceof DrawMesh) {
                $this->drawMeshCommand($cmd->meshId, $material, $cmd->modelMatrix, $cmd->materialId);
            } else {
                $this->drawMeshInstancedCommand($cmd, $material);
            }
        }
        PerfProfiler::end();

        // --- Pass 3: Transparent geometry ---
        PerfProfiler::begin('render3d.submit.transparent');
        $this->bindPipeline('transparent');
        // The opaque pass already uploaded the frame uniforms (+ probe/ssao/sdfao
        // texture binds) into this same shader object's sticky cbuffer; re-upload
        // only if the shader actually changed (it doesn't within a normal frame).
        $transparentShaderId = $this->shaderOverride ?? 'default';
        if ($transparentShaderId !== $this->frameUniformsShaderId) {
            $this->uploadFrameUniforms($frameState);
            $this->uploadShadowUniforms($hasShadowMap, $dirLights);
            $this->uploadSsaoUniforms();
            $this->uploadSdfAoUniforms();
            $this->frameUniformsShaderId = $transparentShaderId;
        }

        foreach ($commands as $cmd) {
            if ($cmd instanceof DrawMesh) {
                $material = MaterialRegistry::get($cmd->materialId);
                if ($material === null || $material->alpha >= 1.0) {
                    continue;
                }
                $this->drawMeshCommand($cmd->meshId, $material, $cmd->modelMatrix, $cmd->materialId);
            } elseif ($cmd instanceof DrawMeshInstanced) {
                $material = MaterialRegistry::get($cmd->materialId);
                if ($material === null || $material->alpha >= 1.0) {
                    continue;
                }
                $this->drawMeshInstancedCommand($cmd, $material);
            }
        }
        PerfProfiler::end();

        // --- Screen-space reflections (VIO/D3D12, HDR path) ---
        // Ray-march the G-buffer against the just-rendered HDR scene colour and
        // composite the reflection back into the offscreen scene target BEFORE
        // the present/bloom resolve, so reflected highlights bloom and tonemap
        // with the rest of the scene. No-op unless ssrEnabledThisFrame().
        $this->renderSsrPass();

        // --- Post-processing: HDR → Bloom → Tonemap → Backbuffer ---
        $quad = $this->screenQuad;
        if ($hdrTarget !== null && $quad !== null) {
            vio_unbind_render_target($this->ctx);
            $sceneTex = vio_render_target_texture($hdrTarget);
            $bloomExtract = $this->bloomExtractTarget;
            $bloomPing = $this->bloomPingTarget;
            $bloomPong = $this->bloomPongTarget;
            $bloomTex = null;

            if ($bloomExtract !== null && $bloomPing !== null && $bloomPong !== null) {
                $bw = max(1, (int)($this->width / 2));
                $bh = max(1, (int)($this->height / 2));

                // Extract bright pixels
                vio_bind_render_target($this->ctx, $bloomExtract);
                vio_viewport($this->ctx, 0, 0, $bw, $bh);
                vio_clear($this->ctx, 0, 0, 0, 1);
                $this->bindPostProcessPipeline('bloom_extract');
                vio_bind_texture($this->ctx, $sceneTex, 0);
                vio_set_uniform($this->ctx, 'u_scene', 0);
                vio_set_uniform($this->ctx, 'u_threshold', $this->bloomThreshold);
                vio_draw($this->ctx, $quad);
                vio_unbind_render_target($this->ctx);

                // Horizontal blur: extract → ping
                $extractTex = vio_render_target_texture($bloomExtract);
                vio_bind_render_target($this->ctx, $bloomPing);
                vio_viewport($this->ctx, 0, 0, $bw, $bh);
                vio_clear($this->ctx, 0, 0, 0, 1);
                $this->bindPostProcessPipeline('bloom_blur');
                vio_bind_texture($this->ctx, $extractTex, 0);
                vio_set_uniform($this->ctx, 'u_source', 0);
                vio_set_uniform($this->ctx, 'u_direction', [1.0 / $bw, 0.0]);
                vio_draw($this->ctx, $quad);
                vio_unbind_render_target($this->ctx);

                // Vertical blur: ping → pong
                $pingTex = vio_render_target_texture($bloomPing);
                vio_bind_render_target($this->ctx, $bloomPong);
                vio_viewport($this->ctx, 0, 0, $bw, $bh);
                vio_clear($this->ctx, 0, 0, 0, 1);
                $this->bindPostProcessPipeline('bloom_blur');
                vio_bind_texture($this->ctx, $pingTex, 0);
                vio_set_uniform($this->ctx, 'u_source', 0);
                vio_set_uniform($this->ctx, 'u_direction', [0.0, 1.0 / $bh]);
                vio_draw($this->ctx, $quad);
                vio_unbind_render_target($this->ctx);

                $bloomTex = vio_render_target_texture($bloomPong);
            }

            // Tonemap + composite
            vio_viewport($this->ctx, 0, 0, $this->width, $this->height);

            $this->bindPostProcessPipeline('tonemap');
            vio_set_uniform($this->ctx, 'u_scene', 0);
            vio_bind_texture($this->ctx, $sceneTex, 0);
            if ($bloomTex) {
                vio_set_uniform($this->ctx, 'u_bloom', 1);
                vio_bind_texture($this->ctx, $bloomTex, 1);
                vio_set_uniform($this->ctx, 'u_bloom_intensity', $this->bloomIntensity);
            } else {
                vio_set_uniform($this->ctx, 'u_bloom_intensity', 0.0);
            }
            vio_set_uniform($this->ctx, 'u_exposure', $this->exposure);
            vio_draw($this->ctx, $quad);
        }
    }

    // ----------------------------------------------------------------
    // Shader management
    // ----------------------------------------------------------------

    private function initShaders(): void
    {
        $this->compileShaderFromFiles('default',    'mesh3d.vert.glsl',     'mesh3d.frag.glsl');
        $this->compileShaderFromFiles('unlit',      'unlit.vert.glsl',      'unlit.frag.glsl');
        $this->compileShaderFromFiles('shadow',     'shadow.vert.glsl',     'shadow.frag.glsl');
        $this->compileShaderFromFiles('depth',      'depth.vert.glsl',      'depth.frag.glsl');
        $this->compileShaderFromFiles('normals',    'normals.vert.glsl',    'normals.frag.glsl');
        $this->compileShaderFromFiles('skybox',     'skybox.vert.glsl',     'skybox.frag.glsl');
        // SSAO G-buffer (its own geometry vertex stage) + the two fullscreen
        // post passes (reuse postprocess.vert, whose quad UV.v is pre-flipped).
        $this->compileShaderFromFiles('gbuffer',    'gbuffer.vert.glsl',    'gbuffer.frag.glsl');
        $this->compileShaderFromFiles('ssao',       'postprocess.vert.glsl', 'ssao.frag.glsl');
        $this->compileShaderFromFiles('sdf_ao',     'postprocess.vert.glsl', 'sdf_ao.frag.glsl');
        $this->compileShaderFromFiles('ssao_blur',  'postprocess.vert.glsl', 'ssao_blur.frag.glsl');
        // SSR: ray-march the G-buffer (depth+normal+reflectivity) against the HDR
        // scene colour, then composite the reflection back over the scene.
        $this->compileShaderFromFiles('ssr',           'postprocess.vert.glsl', 'ssr.frag.glsl');
        $this->compileShaderFromFiles('ssr_composite', 'postprocess.vert.glsl', 'ssr_composite.frag.glsl');
        // Atmosphere split into one layered pass per element (each toggleable +
        // independently editable). All share atmosphere.vert (emits v_ndc).
        $this->compileShaderFromFiles('sky_gradient', 'atmosphere.vert.glsl', 'sky_gradient.frag.glsl');
        $this->compileShaderFromFiles('sky_sun',      'atmosphere.vert.glsl', 'sky_sun.frag.glsl');
        $this->compileShaderFromFiles('sky_moon',     'atmosphere.vert.glsl', 'sky_moon.frag.glsl');
        $this->compileShaderFromFiles('sky_stars',    'atmosphere.vert.glsl', 'sky_stars.frag.glsl');
        $this->compileShaderFromFiles('sky_clouds',   'atmosphere.vert.glsl', 'sky_clouds.frag.glsl');
        $this->compileShaderFromFiles('sky_haze',     'atmosphere.vert.glsl', 'sky_haze.frag.glsl');
    }

    private function initPostProcess(): void
    {
        $this->hdrTarget = vio_render_target($this->ctx, [
            'width' => $this->width,
            'height' => $this->height,
            'hdr' => true,
        ]) ?: null;
        if ($this->hdrTarget === null) {
            return;
        }

        $bw = max(1, (int)($this->width / 2));
        $bh = max(1, (int)($this->height / 2));
        $this->bloomExtractTarget = vio_render_target($this->ctx, ['width' => $bw, 'height' => $bh]) ?: null;
        $this->bloomPingTarget = vio_render_target($this->ctx, ['width' => $bw, 'height' => $bh]) ?: null;
        $this->bloomPongTarget = vio_render_target($this->ctx, ['width' => $bw, 'height' => $bh]) ?: null;

        // UV.v is FLIPPED relative to the NDC position: on the php-vio backends
        // (D3D12/D3D11/Vulkan/Metal) the render-target texel origin is top-left
        // while NDC +Y is up, so sampling an offscreen-rendered texture with the
        // naive mapping comes out vertically mirrored. Flipping v here once makes
        // every fullscreen pass that samples an RT (present blit, FXAA, bloom)
        // render upright. The atmospheric sky reconstructs its ray from NDC, not
        // v_uv, so it is unaffected.
        $this->screenQuad = vio_mesh($this->ctx, [
            'vertices' => [
                -1, -1, 0,  0, 1,
                 1, -1, 0,  1, 1,
                 1,  1, 0,  1, 0,
                -1,  1, 0,  0, 0,
            ],
            'indices' => [0, 1, 2, 0, 2, 3],
            'layout' => [VIO_FLOAT3, VIO_FLOAT2],
        ]) ?: null;

        $this->compileShaderFromFiles('bloom_extract',    'postprocess.vert.glsl', 'bloom_extract.frag.glsl');
        $this->compileShaderFromFiles('bloom_blur',       'postprocess.vert.glsl', 'bloom_blur.frag.glsl');
        $this->compileShaderFromFiles('tonemap',          'postprocess.vert.glsl', 'tonemap.frag.glsl');
        $this->compileShaderFromFiles('passthrough_blit', 'postprocess.vert.glsl', 'passthrough_blit.frag.glsl');

    }

    private function compileShaderFromFiles(string $id, string $vertFile, string $fragFile): void
    {
        $this->compileShader($id, $this->loadShader($vertFile), $this->loadShader($fragFile));
    }

    private function loadShader(string $relativeFile): string
    {
        $path = self::SHADER_DIR . $relativeFile;
        $source = @file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException("VioRenderer3D: Failed to read shader source '{$relativeFile}' (path={$path})");
        }
        return $source;
    }

    private function compileShader(string $id, string $vertSrc, string $fragSrc): void
    {
        // VIO_SHADER_GLSL_RAW = OpenGL passthrough only. On Metal/Vulkan, vio
        // must transpile GLSL → SPIR-V → MSL, which is what VIO_SHADER_GLSL
        // selects. Without this branch, pipeline creation silently fails on
        // Metal and the screen stays at the layer's default (white).
        $format = $this->conventions()->shaderSourceFormat();

        $shader = vio_shader($this->ctx, [
            'vertex' => $vertSrc,
            'fragment' => $fragSrc,
            'format' => $format,
        ]);

        if ($shader === false) {
            throw new \RuntimeException("VioRenderer3D: Failed to compile shader '{$id}' (format={$format})");
        }

        $this->shaderCache[$id] = $shader;
    }

    // ----------------------------------------------------------------
    // Pipeline management
    // ----------------------------------------------------------------

    private function bindPipeline(string $pass): void
    {
        $shaderId = $this->shaderOverride ?? 'default';
        $hdr = $this->sceneTargetIsHdr();
        // Cache LDR and HDR pipeline variants under distinct keys: on D3D12 the
        // PSO RTV format (R8 vs FP16) is baked in and must match the bound target.
        $key = $pass . ':' . $shaderId . ($hdr ? ':hdr' : '');

        if (!isset($this->pipelineCache[$key])) {
            $shader = $this->shaderCache[$shaderId] ?? $this->shaderCache['default'];

            $pipeline = vio_pipeline($this->ctx, [
                'shader' => $shader,
                'depth_test' => true,
                'cull_mode' => VIO_CULL_NONE,
                'blend' => $pass === 'transparent' ? VIO_BLEND_ALPHA : VIO_BLEND_NONE,
                // FP16 scene target → PSO RTVFormats[0] = R16G16B16A16_FLOAT so
                // the draw isn't dropped with "render target format does not
                // match". No-op on backends that derive format from the target.
                'hdr' => $hdr,
            ]);

            if ($pipeline === false) {
                return;
            }

            $this->pipelineCache[$key] = $pipeline;
        }

        vio_bind_pipeline($this->ctx, $this->pipelineCache[$key]);

        // A pipeline bind may switch the bound shader object (its own cbuffer),
        // and the new pipeline starts a fresh draw run — invalidate the per-draw
        // material/mesh dedup trackers so the first draw after the bind always
        // re-uploads its material uniforms + AABB.
        $this->resetDrawStateCache();
    }

    /**
     * Forget which material/mesh uniforms are resident in the bound cbuffer, so
     * the next drawMeshCommand re-applies them unconditionally. Call at pass
     * boundaries / pipeline binds (done in bindPipeline).
     */
    private function resetDrawStateCache(): void
    {
        $this->lastMaterialId = null;
        $this->lastMeshId = null;
    }

    /**
     * Whether the scene-geometry / sky pipelines bound this frame draw into the
     * FP16 (linear-HDR) offscreen target. True only when the offscreen path is
     * active AND that target was allocated HDR (see offscreenIsHdr()). Every
     * pipeline that draws into the scene target — opaque, transparent, sky x6,
     * skybox — must agree with this so the D3D12 PSO RTV format matches.
     */
    private function sceneTargetIsHdr(): bool
    {
        return $this->offscreenActive
            && $this->offscreenTarget !== null
            && $this->offscreenTarget->isHdr();
    }

    /**
     * Bind a fullscreen post-process pipeline. $hdr selects the FP16-output PSO
     * variant for passes that draw into an FP16 target (the bloom extract/blur
     * targets on the HDR scene path). The SSAO and final-resolve passes always
     * write RGBA8 and pass $hdr=false (the default). On D3D12 the PSO RTV format
     * must match the bound target; off-D3D the flag is a no-op.
     */
    private function bindPostProcessPipeline(string $shaderId, bool $hdr = false): void
    {
        $key = 'postprocess:' . $shaderId . ($hdr ? ':hdr' : '');
        if (!isset($this->pipelineCache[$key])) {
            $pipeline = vio_pipeline($this->ctx, [
                'shader' => $this->shaderCache[$shaderId],
                'depth_test' => false,
                'cull_mode' => VIO_CULL_NONE,
                'blend' => VIO_BLEND_NONE,
                'hdr' => $hdr,
            ]);
            if ($pipeline === false) {
                return;
            }
            $this->pipelineCache[$key] = $pipeline;
        }
        vio_bind_pipeline($this->ctx, $this->pipelineCache[$key]);
    }

    // ----------------------------------------------------------------
    // Shadow map
    // ----------------------------------------------------------------

    private function initShadowMap(): void
    {
        if ($this->settings->shadowQuality === \PHPolygon\Rendering\Quality\ShadowQuality::Off) {
            $this->shadowTarget = null;
            $this->cascadeShadowTargets = [];
            return;
        }
        $resolution = $this->settings->shadowQuality->resolution();
        if ($resolution <= 0) {
            $resolution = self::SHADOW_MAP_RESOLUTION;
        }

        $this->cascadeShadowTargets = [];
        foreach (self::CASCADE_ORTHO_SIZES as $cIdx => $_size) {
            $target = vio_render_target($this->ctx, [
                'width'  => $resolution,
                'height' => $resolution,
                'depth_only' => true,
            ]);
            if ($target === false) {
                // Backend rejected - fall back to single-map mode for this run.
                $this->cascadeShadowTargets = [];
                return;
            }
            $this->cascadeShadowTargets[$cIdx] = $target;
        }
        // Cascade 0 doubles as the legacy single-map handle.
        $this->shadowTarget = $this->cascadeShadowTargets[0] ?? null;
    }

    /**
     * Render depth-only shadow pass from the brightest directional light.
     *
     * @param list<SetDirectionalLight> $dirLights
     * @return bool Whether shadow map was rendered
     */
    private function renderShadowPass(RenderCommandList $commandList, array $dirLights): bool
    {
        if ($this->settings->shadowQuality === \PHPolygon\Rendering\Quality\ShadowQuality::Off) {
            return false;
        }
        if ($this->shadowTarget === null) {
            $this->initShadowMap();
        }
        if ($this->shadowTarget === null || empty($dirLights)) {
            return false;
        }

        // Find brightest directional light
        $lightDir = null;
        $lightIntensity = 0.0;
        foreach ($dirLights as $dl) {
            if ($dl->intensity > $lightIntensity) {
                $lightDir = $dl->direction;
                $lightIntensity = $dl->intensity;
            }
        }

        if ($lightDir === null || $lightIntensity < 0.05) {
            return false;
        }

        $shadowCenter = $this->currentViewMatrix?->inverse()->getTranslation();
        $shadowRes = $this->settings->shadowQuality->resolution();
        if ($shadowRes <= 0) {
            $shadowRes = self::SHADOW_MAP_RESOLUTION;
        }

        // CSM: render the scene once per cascade into its own target.
        //
        // Conservative per-cascade culling. Each cascade's ortho box is centred
        // on $shadowCenter (the camera) and spans ±orthoSize in the two axes
        // perpendicular to the light. A caster only needs drawing into a cascade
        // if it lies within that lateral footprint, so we cull casters whose
        // centre is laterally (perpendicular to the light) farther than
        // orthoSize + radius + margin. We cull ONLY laterally — never along the
        // light axis, so occluders in front of the box still cast — and never
        // cull casters bigger than the cascade (ground/water planes). The near
        // cascade (15u) then skips almost every far building when looking across
        // the dense city centre, which is the view-dependent cost.
        //
        // Pre-pass (once, not per-cascade): filter to shadow casters and
        // precompute each non-instanced caster's world-space bounding sphere.
        $cull = false;
        $lx = 0.0; $ly = 0.0; $lz = 0.0;
        $scx = 0.0; $scy = 0.0; $scz = 0.0;
        if ($shadowCenter !== null) {
            $llen = sqrt($lightDir->x * $lightDir->x + $lightDir->y * $lightDir->y + $lightDir->z * $lightDir->z);
            if ($llen > 1e-6) {
                $lx = $lightDir->x / $llen;
                $ly = $lightDir->y / $llen;
                $lz = $lightDir->z / $llen;
                $scx = $shadowCenter->x;
                $scy = $shadowCenter->y;
                $scz = $shadowCenter->z;
                $cull = true;
            }
        }

        /** @var list<array{0: DrawMesh, 1: float, 2: float, 3: float, 4: float}> $casters */
        $casters = [];
        /** @var list<array{0: DrawMeshInstanced, 1: Material}> $instancedCasters */
        $instancedCasters = [];
        foreach ($commandList->getCommands() as $cmd) {
            if ($cmd instanceof DrawMesh) {
                $mat = MaterialRegistry::get($cmd->materialId);
                if ($mat === null || $mat->alpha < 0.9 || !$this->castsShadow($cmd->materialId)) {
                    continue;
                }
                [$cx, $cy, $cz, $r] = $this->worldMeshSphere($cmd->meshId, $cmd->modelMatrix);
                $casters[] = [$cmd, $cx, $cy, $cz, $r];
            } elseif ($cmd instanceof DrawMeshInstanced) {
                $mat = MaterialRegistry::get($cmd->materialId);
                if ($mat === null || $mat->alpha < 0.9 || !$this->castsShadow($cmd->materialId)) {
                    continue;
                }
                $instancedCasters[] = [$cmd, $mat];
            }
        }

        if ($this->flashDbgEnabled()) {
            $n = count($casters) + count($instancedCasters);
            // Camera forward (world space) from the inverse view: where is the
            // player LOOKING? fwdY ≈ -1 straight down, +1 straight up.
            $fwdY = 0.0;
            if ($this->currentViewMatrix !== null) {
                $inv = $this->currentViewMatrix->inverse();
                $eye = $inv->transformPoint(new \PHPolygon\Math\Vec3(0.0, 0.0, 0.0));
                $ahead = $inv->transformPoint(new \PHPolygon\Math\Vec3(0.0, 0.0, -1.0));
                $fwdY = $ahead->y - $eye->y;
            }
            if ($this->flashDbgCasters >= 0 && abs($n - $this->flashDbgCasters) > max(10, (int) ($this->flashDbgCasters * 0.5))) {
                fprintf(STDERR, "[flashdbg] %s  shadow caster count jump: %d -> %d (fwdY %.2f)\n",
                    date('H:i:s'), $this->flashDbgCasters, $n, $fwdY);
            }
            // The smoking gun for a cull glitch: almost no casters while the
            // player is clearly looking DOWN at the world. Normal play never
            // produces this — looking down means terrain+buildings in view.
            if ($n < 60 && $fwdY < -0.35) {
                fprintf(STDERR, "[flashdbg] %s  !! LOW casters (%d) while looking DOWN (fwdY %.2f)\n",
                    date('H:i:s'), $n, $fwdY);
            }
            $this->flashDbgCasters = $n;
        }

        $this->cascadeLightSpaceMatrices = [];
        foreach (self::CASCADE_ORTHO_SIZES as $cIdx => $orthoSize) {
            $target = $this->cascadeShadowTargets[$cIdx] ?? null;
            if ($target === null) continue;

            [$lightProj, $lightView] = $this->computeLightProjView($lightDir, $shadowCenter, $orthoSize);
            // Sampling reconstructs depth from the combined matrix in mesh3d.frag.
            $this->cascadeLightSpaceMatrices[$cIdx] = $lightProj->multiply($lightView);

            vio_bind_render_target($this->ctx, $target);
            vio_viewport($this->ctx, 0, 0, $shadowRes, $shadowRes);
            vio_clear($this->ctx, 1.0, 1.0, 1.0, 1.0);

            $this->bindShadowPipeline();
            // Submit projection + view separately (not the combined matrix with
            // an identity projection) so vio's D3D12 clip/depth handling — which
            // keys off the projection matrix — applies just like the colour pass.
            vio_set_uniform($this->ctx, 'u_view', $lightView->toArray());
            vio_set_uniform($this->ctx, 'u_projection', $lightProj->toArray());

            // Lateral cull limit margin: covers the texel-snap offset of the box
            // centre plus the sphere-from-AABB approximation. Generous on purpose
            // (over-keeping only costs a draw; under-keeping would drop a shadow).
            $margin = $orthoSize * 0.15 + 2.0;

            foreach ($casters as [$cmd, $cx, $cy, $cz, $r]) {
                if ($cull && $r < $orthoSize) {
                    $dx = $cx - $scx; $dy = $cy - $scy; $dz = $cz - $scz;
                    $along = $dx * $lx + $dy * $ly + $dz * $lz;
                    $px = $dx - $along * $lx;
                    $py = $dy - $along * $ly;
                    $pz = $dz - $along * $lz;
                    $lim = $orthoSize + $r + $margin;
                    if (($px * $px + $py * $py + $pz * $pz) > $lim * $lim) {
                        continue; // laterally outside this cascade's shadow box
                    }
                }
                $mesh = $this->uploadMesh($cmd->meshId);
                if ($mesh === null) {
                    continue;
                }
                vio_set_uniform($this->ctx, 'u_model', $cmd->modelMatrix->toArray());
                vio_set_uniform($this->ctx, 'u_use_instancing', 0);
                vio_draw($this->ctx, $mesh);
            }

            foreach ($instancedCasters as [$cmd, $mat]) {
                $mesh = $this->uploadMesh($cmd->meshId);
                if ($mesh === null) {
                    continue;
                }
                [$flatMatrices, $instanceCount] = $this->resolveInstanceData($cmd->meshId, $mat, $cmd);
                vio_set_uniform($this->ctx, 'u_use_instancing', 1);
                vio_draw_instanced($this->ctx, $mesh, $flatMatrices, $instanceCount);
            }
        }

        vio_unbind_render_target($this->ctx);

        // Cascade 0 also fills the legacy single-map slot for cloud-shadow paths.
        return true;
    }

    // ----------------------------------------------------------------
    // SSAO — real depth+normal screen-space ambient occlusion
    // ----------------------------------------------------------------

    /**
     * True when real (G-buffer) SSAO should run this frame: the AO tier is
     * Medium/High (curvature-only at Off/Low) AND the backend is D3D — this is
     * the VIO/D3D12 ship path; OpenGL keeps its own mesh3d.frag (curvature AO)
     * and is intentionally untouched here. The AdaptiveTierStack downgrades
     * $settings->ambientOcclusion in place, so reading the live setting is all
     * the "downgraded below Medium" gate we need.
     */
    private function ssaoEnabledThisFrame(): bool
    {
        if (getenv('PHPOLYGON_SSAO') === '0') {
            return false; // escape hatch for A/B testing
        }
        return $this->settings->ambientOcclusion->usesGbuffer()
            && $this->conventions()->isDirect3D();
    }

    /**
     * True when real (ray-marched) SSR should run this frame: the SSR tier is
     * Low/High AND the backend is D3D AND the scene renders into the FP16 HDR
     * offscreen target (the ray-march samples that linear HDR colour). Like
     * SSAO, the AdaptiveTierStack downgrades $settings->ssr in place, so reading
     * the live tier IS the "downgraded to Off" gate. SSR additionally needs the
     * HDR scene target — without it there is no linear scene colour to reflect
     * (and bloom would be operating on tonemapped LDR), so SSR rides the same
     * HDR gate as bloom; off the HDR path the forward wetness surrogate is used.
     */
    private function ssrEnabledThisFrame(): bool
    {
        if (getenv('PHPOLYGON_SSR') === '0') {
            return false; // escape hatch for A/B testing
        }
        return $this->settings->ssr->usesRaymarch()
            && $this->conventions()->isDirect3D()
            && $this->sceneTargetIsHdr();
    }

    /**
     * The FP16 G-buffer (view normal + reflectivity + linear depth) is shared by
     * SSAO and SSR — build it when EITHER effect is active this frame.
     */
    private function gbufferNeededThisFrame(): bool
    {
        return $this->ssaoEnabledThisFrame() || $this->ssrEnabledThisFrame()
            || $this->sdfAoEnabledThisFrame();
    }

    /**
     * Render the SSAO chain into the half-res blur target:
     *   1. G-buffer  : opaque geometry -> view normal (rgb) + linear depth (a)
     *   2. SSAO      : hemisphere kernel occlusion -> half-res AO (R)
     *   3. Blur      : 4x4 box blur -> half-res blurred AO (R)
     *
     * Mirrors the shadow pass's "bind my own target, draw, unbind" structure so
     * the caller can run it between the shadow pass and the scene-target bind.
     * Sets $this->ssaoActiveThisFrame for uploadSsaoUniforms() to consume.
     *
     * @param list<object>                                                      $commands   same command list as the forward pass
     * @param array{waveAmplitude: float, waveFrequency: float, wavePhase: float} $frameState frame-global lighting/fog/wave state (only the wave keys are read here)
     */
    private function renderSsaoPass(array $commands, array $frameState): void
    {
        $this->ssaoActiveThisFrame = false;

        // Build the G-buffer when EITHER SSAO or SSR needs it. The SSAO occlusion
        // + blur sub-passes below run only when SSAO itself is enabled; SSR reads
        // the same G-buffer in renderSsrPass() after the scene draws.
        if (!$this->gbufferNeededThisFrame()
            || $this->currentViewMatrix === null
            || $this->currentProjectionMatrix === null
            || $this->screenQuad === null) {
            return;
        }

        // Capture nullable matrices locally so the intervening method calls
        // (ensureSsaoTargets / bindGbufferPipeline) don't re-widen them to null.
        $viewMatrix = $this->currentViewMatrix;
        $projMatrix = $this->currentProjectionMatrix;

        $this->ensureSsaoTargets();
        $gbuffer = $this->gbufferTarget;
        $ssao    = $this->ssaoTarget;
        $blur    = $this->ssaoBlurTarget;
        if ($gbuffer === null || $ssao === null || $blur === null) {
            return; // backend refused an RT; forward pass falls back to white AO
        }

        $fullW = max(1, $this->gbufferWidth);
        $fullH = max(1, $this->gbufferHeight);
        $halfW = max(1, (int) ($fullW / 2));
        $halfH = max(1, (int) ($fullH / 2));

        // --- 1. G-buffer (full-res): view normal + linear view depth ---------
        // Clear to (0,0,0,0): alpha 0 marks "sky / no geometry" so the SSAO pass
        // masks it out. Depth test on; same opaque geometry as the forward pass.
        vio_bind_render_target($this->ctx, $gbuffer);
        vio_viewport($this->ctx, 0, 0, $fullW, $fullH);
        vio_clear($this->ctx, 0, 0, 0, 0);
        $this->bindGbufferPipeline();
        vio_set_uniform($this->ctx, 'u_view', $viewMatrix->toArray());
        vio_set_uniform($this->ctx, 'u_projection', $projMatrix->toArray());
        vio_set_uniform($this->ctx, 'u_time', $this->globalTime);
        // Frame-global vertex-animation state (water swell + wind). These come
        // from the SetWaveAnimation / SetWind commands, not per-material — set
        // them ONCE so the G-buffer water surface deforms exactly like the
        // forward pass's water draw (uploadFrameUniforms feeds the same values
        // there). Per-material toggles (u_vertex_anim, cloth) are set per draw.
        vio_set_uniform($this->ctx, 'u_wave_amplitude', $frameState['waveAmplitude']);
        vio_set_uniform($this->ctx, 'u_wave_frequency', $frameState['waveFrequency']);
        vio_set_uniform($this->ctx, 'u_wave_phase', $frameState['wavePhase']);
        vio_set_uniform($this->ctx, 'u_wind_direction', $this->windDirection);
        vio_set_uniform($this->ctx, 'u_wind_intensity', $this->windIntensity);
        // Global rain wetness feeds the G-buffer reflectivity (channel b) so wet
        // ground after rain catches the SSR reflection. Per-material wetness /
        // metallic / roughness are pushed per draw in applyGbufferVertexAnim.
        vio_set_uniform($this->ctx, 'u_rain_wetness', $this->rainWetness);
        // The G-buffer now stores raw LINEAR view depth in the FP16 alpha channel
        // (world units), so there is no normalisation range to share with the SSAO
        // pass any more — both sides read depth directly.

        foreach ($commands as $cmd) {
            if ($cmd instanceof DrawMesh) {
                $material = MaterialRegistry::get($cmd->materialId);
                if ($material === null || $material->alpha < 1.0) {
                    continue;
                }
                $this->drawGbufferMesh($cmd->meshId, $material, $cmd->modelMatrix, $cmd->materialId);
            } elseif ($cmd instanceof DrawMeshInstanced) {
                $material = MaterialRegistry::get($cmd->materialId);
                if ($material === null || $material->alpha < 1.0) {
                    continue;
                }
                $this->drawGbufferMeshInstanced($cmd, $material);
            }
        }
        vio_unbind_render_target($this->ctx);

        // SSAO occlusion + blur run only when SSAO is enabled; an SSR-only frame
        // skips them (the G-buffer is enough for the SSR pass). The water-append
        // below runs regardless, so do it after this block via a goto-free split.
        if ($this->ssaoEnabledThisFrame()) {
            $this->renderSsaoOcclusion($gbuffer, $ssao, $blur, $halfW, $halfH);
        }

        // Reflective TRANSPARENT surfaces (water) into the G-buffer — AFTER SSAO
        // has consumed the opaque-only G-buffer, so SSAO is byte-for-byte
        // unaffected, but the SSR pass (which runs later) sees water depth /
        // normal / reflectivity and can reflect off it. Gated on SSR being on.
        if ($this->ssrEnabledThisFrame()) {
            $this->appendReflectiveTransparentToGbuffer($commands, $gbuffer, $fullW, $fullH);
        }
    }

    /**
     * SSAO occlusion + blur sub-passes (extracted so an SSR-only frame can skip
     * them while still building/using the shared G-buffer). Sets
     * ssaoActiveThisFrame on success.
     */
    private function renderSsaoOcclusion(
        VioRenderTarget $gbuffer,
        VioRenderTarget $ssao,
        VioRenderTarget $blur,
        int $halfW,
        int $halfH,
    ): void {
        // Re-assert the invariants the caller (renderSsaoPass) already checked so
        // the matrix/quad uses below are statically non-null.
        if ($this->currentProjectionMatrix === null || $this->screenQuad === null) {
            return;
        }
        // Capture locally so the bindPostProcessPipeline() calls below don't
        // re-widen the nullable property/matrix in PHPStan's view.
        $screenQuad = $this->screenQuad;

        // --- 2. SSAO (half-res) -----------------------------------------------
        $proj = $this->currentProjectionMatrix->toArray(); // column-major
        $proj00 = $proj[0];  // projection[0][0]
        $proj11 = $proj[5];  // projection[1][1]
        // UV.v vs view +Y: the G-buffer is sampled with the quad's pre-flipped
        // v (postprocess.vert), so on the y-down D3D render targets the view +Y
        // axis runs opposite the sampled UV.v. Flip ndc.y accordingly.
        $uvFlipY = $this->conventions()->flipRenderTargetClipY() ? -1.0 : 1.0;
        $tier = $this->settings->ambientOcclusion;

        vio_bind_render_target($this->ctx, $ssao);
        vio_viewport($this->ctx, 0, 0, $halfW, $halfH);
        vio_clear($this->ctx, 1, 1, 1, 1);
        $this->bindPostProcessPipeline('ssao');
        vio_bind_texture($this->ctx, vio_render_target_texture($gbuffer), 0);
        vio_set_uniform($this->ctx, 'u_gbuffer', 0);
        vio_set_uniform($this->ctx, 'u_noise_scale', [$halfW / 4.0, $halfH / 4.0]);
        vio_set_uniform($this->ctx, 'u_proj00', $proj00);
        vio_set_uniform($this->ctx, 'u_proj11', $proj11);
        vio_set_uniform($this->ctx, 'u_uv_flip_y', $uvFlipY);
        vio_set_uniform($this->ctx, 'u_radius', $tier->ssaoRadius());
        vio_set_uniform($this->ctx, 'u_bias', 0.025);
        vio_set_uniform($this->ctx, 'u_intensity', $tier->ssaoIntensity());
        vio_set_uniform($this->ctx, 'u_power', $tier->ssaoPower());
        vio_draw($this->ctx, $screenQuad);
        vio_unbind_render_target($this->ctx);

        // --- 3. Blur (half-res): 4x4 box to remove the rotation noise ----------
        vio_bind_render_target($this->ctx, $blur);
        vio_viewport($this->ctx, 0, 0, $halfW, $halfH);
        vio_clear($this->ctx, 1, 1, 1, 1);
        $this->bindPostProcessPipeline('ssao_blur');
        vio_bind_texture($this->ctx, vio_render_target_texture($ssao), 0);
        vio_set_uniform($this->ctx, 'u_source', 0);
        vio_set_uniform($this->ctx, 'u_texel', [1.0 / $halfW, 1.0 / $halfH]);
        vio_draw($this->ctx, $screenQuad);
        vio_unbind_render_target($this->ctx);

        $this->ssaoActiveThisFrame = true;
    }

    /**
     * Append reflective TRANSPARENT surfaces (water) into the existing G-buffer
     * for the SSR pass. The opaque G-buffer is already populated (and SSAO has
     * read it), so we re-bind WITHOUT clearing — preserving the opaque depth so
     * water depth-tests correctly against it — and draw only water (proc_mode 2
     * = ocean, 11 = pool). Water's depth/normal/reflectivity then exist in the
     * G-buffer where it's the front surface, which is exactly where SSR needs to
     * reflect. SSAO already finished, so it is byte-for-byte unaffected.
     *
     * Depth write must be ON so a later water fragment doesn't lose to nothing,
     * but the opaque depth is preserved (no clear). bindGbufferPipeline already
     * uses depth_test=true; that pipeline writes depth, which is what we want.
     *
     * @param list<object> $commands same command list as the forward pass
     */
    private function appendReflectiveTransparentToGbuffer(
        array $commands,
        VioRenderTarget $gbuffer,
        int $fullW,
        int $fullH,
    ): void {
        if ($this->currentViewMatrix === null || $this->currentProjectionMatrix === null) {
            return;
        }
        // Capture locally: the bindGbufferPipeline() call below would otherwise
        // re-widen the nullable matrix properties in PHPStan's view.
        $viewMatrix = $this->currentViewMatrix;
        $projMatrix = $this->currentProjectionMatrix;

        // Collect water draws first so we can skip the whole pass (and its
        // bind/unbind) when the scene has no reflective transparent surface.
        $waterDraws = [];
        foreach ($commands as $cmd) {
            if ($cmd instanceof DrawMesh) {
                $material = MaterialRegistry::get($cmd->materialId);
                if ($material === null || $material->alpha >= 1.0) {
                    continue;
                }
                if (!$this->isReflectiveTransparent($cmd->materialId)) {
                    continue;
                }
                $waterDraws[] = [$cmd, $material];
            } elseif ($cmd instanceof DrawMeshInstanced) {
                $material = MaterialRegistry::get($cmd->materialId);
                if ($material === null || $material->alpha >= 1.0) {
                    continue;
                }
                if (!$this->isReflectiveTransparent($cmd->materialId)) {
                    continue;
                }
                $waterDraws[] = [$cmd, $material];
            }
        }
        if ($waterDraws === []) {
            return;
        }

        // Re-bind the G-buffer WITHOUT clearing — preserve opaque colour+depth.
        vio_bind_render_target($this->ctx, $gbuffer);
        vio_viewport($this->ctx, 0, 0, $fullW, $fullH);
        $this->bindGbufferPipeline();
        vio_set_uniform($this->ctx, 'u_view', $viewMatrix->toArray());
        vio_set_uniform($this->ctx, 'u_projection', $projMatrix->toArray());
        vio_set_uniform($this->ctx, 'u_rain_wetness', $this->rainWetness);

        foreach ($waterDraws as [$cmd, $material]) {
            if ($cmd instanceof DrawMesh) {
                $this->drawGbufferMesh($cmd->meshId, $material, $cmd->modelMatrix, $cmd->materialId);
            } else {
                $this->drawGbufferMeshInstanced($cmd, $material);
            }
        }
        vio_unbind_render_target($this->ctx);
    }

    /**
     * Whether a (transparent) material is a reflective water surface that SSR
     * should reflect off — proc_mode 2 (ocean) or 11 (pool). Other transparent
     * materials (glass, foliage cards, particles) are deliberately excluded to
     * keep the SSR water case clean and conservative.
     */
    private function isReflectiveTransparent(string $materialId): bool
    {
        $procMode = self::$procModeCache[$materialId] ?? $this->resolveProcMode($materialId);
        return $procMode === 2 || $procMode === 11;
    }

    /**
     * Real screen-space reflections: ray-march the FP16 G-buffer (built by
     * renderSsaoPass) against the HDR scene colour, then composite the reflection
     * back over the scene offscreen target BEFORE bloom/tonemap.
     *
     * Must run AFTER the opaque + transparent scene passes (so $sceneTex holds
     * the rendered HDR colour) and AFTER the G-buffer is built, but BEFORE the
     * offscreen target is presented (bloom extracts from the SSR-composited
     * scene, so reflected highlights bloom). The scene offscreen target is
     * currently BOUND on entry; we unbind it to read its colour, do two passes,
     * and re-bind it as the composite RT (alpha-blending the separate ssr target
     * over it — no read+write of the same resource).
     */
    private function renderSsrPass(): void
    {
        if (!$this->ssrEnabledThisFrame()
            || $this->currentProjectionMatrix === null
            || $this->screenQuad === null
            || $this->offscreenTarget === null) {
            return;
        }

        // Capture nullable properties locally: the intervening method calls below
        // (ensureSsrTarget / bindPostProcessPipeline / …) would otherwise re-widen
        // the properties back to nullable in PHPStan's view.
        $offscreen   = $this->offscreenTarget;
        $screenQuad  = $this->screenQuad;
        $projMatrix  = $this->currentProjectionMatrix;

        $gbuffer = $this->gbufferTarget;
        if ($gbuffer === null) {
            return; // G-buffer wasn't built (backend refused an RT)
        }
        $this->ensureSsrTarget();
        $ssr = $this->ssrTarget;
        if ($ssr === null) {
            return;
        }

        $sceneTex = $offscreen->texture();
        if ($sceneTex === null) {
            return;
        }

        $w = max(1, $this->ssrWidth);
        $h = max(1, $this->ssrHeight);

        $proj   = $projMatrix->toArray();
        $proj00 = $proj[0];
        $proj11 = $proj[5];
        // Same UV.v <-> view +Y reconciliation the SSAO pass uses.
        $uvFlipY = $this->conventions()->flipRenderTargetClipY() ? -1.0 : 1.0;
        $tier = $this->settings->ssr;

        // --- 1. Ray-march into the SSR target ---------------------------------
        // The scene offscreen target is bound on entry; unbind so its colour can
        // be sampled as an SRV (reading + writing the same target is illegal on
        // D3D12). The composite below re-binds it.
        $offscreen->unbind();

        vio_bind_render_target($this->ctx, $ssr);
        vio_viewport($this->ctx, 0, 0, $w, $h);
        // Clear to 0 (rgb=0, a=0): a miss leaves weight 0 → composite no-ops.
        vio_clear($this->ctx, 0, 0, 0, 0);
        $this->bindPostProcessPipeline('ssr', true); // FP16 target → hdr PSO
        vio_bind_texture($this->ctx, vio_render_target_texture($gbuffer), 0);
        vio_set_uniform($this->ctx, 'u_gbuffer', 0);
        vio_bind_texture($this->ctx, $sceneTex, 1);
        vio_set_uniform($this->ctx, 'u_scene', 1);
        vio_set_uniform($this->ctx, 'u_proj00', $proj00);
        vio_set_uniform($this->ctx, 'u_proj11', $proj11);
        vio_set_uniform($this->ctx, 'u_uv_flip_y', $uvFlipY);
        vio_set_uniform($this->ctx, 'u_steps', $tier->rayMarchSteps());
        vio_set_uniform($this->ctx, 'u_refine', $tier->refineSteps());
        vio_set_uniform($this->ctx, 'u_thickness', $tier->rayThickness());
        vio_set_uniform($this->ctx, 'u_max_dist', $tier->maxDistance());
        vio_set_uniform($this->ctx, 'u_strength', $tier->intensity());
        vio_draw($this->ctx, $screenQuad);
        vio_unbind_render_target($this->ctx);

        // --- 2. Composite: alpha-blend the reflection over the scene ----------
        // Re-bind the scene offscreen target and draw the ssr target over it with
        // VIO_BLEND_ALPHA: scene' = ssr.rgb*ssr.a + scene*(1-ssr.a). Reads the
        // SEPARATE ssr target (SRV) while writing the scene target (RTV) — no
        // hazard. Stays in linear HDR space so reflected highlights bloom.
        $offscreen->bindForDraw();
        vio_viewport($this->ctx, 0, 0,
            $offscreen->width(), $offscreen->height());
        $this->bindSsrCompositePipeline();
        vio_bind_texture($this->ctx, vio_render_target_texture($ssr), 0);
        vio_set_uniform($this->ctx, 'u_ssr', 0);
        vio_draw($this->ctx, $screenQuad);
        // Leave the offscreen target bound — render() already finished its draws
        // and presentOffscreenIfActive() will unbind + resolve it.
    }

    /**
     * SSR composite pipeline: fullscreen, depth-test off, ALPHA blend, FP16 PSO
     * (the scene target it draws into is FP16). On D3D12 the PSO RTV format must
     * match the bound HDR target. Cached once.
     */
    private function bindSsrCompositePipeline(): void
    {
        $key = 'postprocess:ssr_composite:hdr:alpha';
        if (!isset($this->pipelineCache[$key])) {
            $pipeline = vio_pipeline($this->ctx, [
                'shader' => $this->shaderCache['ssr_composite'],
                'depth_test' => false,
                'cull_mode' => VIO_CULL_NONE,
                'blend' => VIO_BLEND_ALPHA,
                'hdr' => true,
            ]);
            if ($pipeline === false) {
                return;
            }
            $this->pipelineCache[$key] = $pipeline;
        }
        vio_bind_pipeline($this->ctx, $this->pipelineCache[$key]);
    }

    /**
     * (Re)allocate the full-res FP16 SSR target. FP16 so reflected HDR highlights
     * (>1) survive into the composite + bloom. Rebuilt when the backbuffer size
     * changes — same trigger as the G-buffer / bloom targets.
     */
    private function ensureSsrTarget(): void
    {
        $w = max(1, $this->backbufferWidth);
        $h = max(1, $this->backbufferHeight);
        if ($this->ssrTarget !== null && $this->ssrWidth === $w && $this->ssrHeight === $h) {
            return;
        }
        $this->ssrTarget = vio_render_target($this->ctx, ['width' => $w, 'height' => $h, 'hdr' => true]) ?: null;
        $this->ssrWidth  = $w;
        $this->ssrHeight = $h;
    }

    /**
     * Bind the blurred AO texture (or a 1x1 white fallback) and the SSAO gate
     * uniforms onto the currently bound forward shader. ALWAYS binds the AO
     * sampler — leaving the declared u_ssao_map sampler unbound reads as an empty
     * SRV on D3D12 (the dark-disc failure mode). The GL slot is SSAO_SAMPLER_SLOT
     * (1), which php-vio remaps to the cross-compiler's t-register for u_ssao_map
     * (t1: the 2nd regular sampler after albedo t0; clear of shadows at t4-t7).
     */
    private function uploadSsaoUniforms(): void
    {
        $enabled = $this->ssaoActiveThisFrame && $this->ssaoBlurTarget !== null;

        if ($enabled) {
            $aoTex = vio_render_target_texture($this->ssaoBlurTarget);
        } else {
            $this->ensureWhiteTexture();
            $aoTex = $this->whiteTexture;
        }

        if ($aoTex !== null) {
            vio_bind_texture($this->ctx, $aoTex, self::SSAO_SAMPLER_SLOT);
            vio_set_uniform($this->ctx, 'u_ssao_map', self::SSAO_SAMPLER_SLOT);
        }
        vio_set_uniform($this->ctx, 'u_ssao_enabled', $enabled ? 1 : 0);
        // u_ssao_uv_flip_y is no longer read by mesh3d.frag — the AO map is now
        // sampled directly by normalised gl_FragCoord with NO v flip, because the
        // fullscreen SSAO/blur passes already store it in the same orientation as
        // every other RT (postprocess.vert's pre-flip reconciles NDC vs the
        // top-left texel origin at write time). Flipping again in the forward
        // consume double-counted that and applied AO vertically mirrored (the
        // outline-seam artifact). The upload is kept so the declared uniform still
        // resolves; the value is inert.
        vio_set_uniform(
            $this->ctx,
            'u_ssao_uv_flip_y',
            $this->conventions()->flipRenderTargetClipY() ? -1.0 : 1.0,
        );
    }

    /** Graphics pipeline for the G-buffer pass: gbuffer shader, depth test on. */
    private function bindGbufferPipeline(): void
    {
        $key = 'gbuffer';
        if (!isset($this->pipelineCache[$key])) {
            $pipeline = vio_pipeline($this->ctx, [
                'shader' => $this->shaderCache['gbuffer'],
                'depth_test' => true,
                'cull_mode' => VIO_CULL_NONE,
                'blend' => VIO_BLEND_NONE,
                // The G-buffer target is FP16 (hdr below). On D3D12 the PSO's RTV
                // format must match the bound target, so request hdr output here —
                // otherwise the draw is dropped with "render target format does not
                // match". No-op on backends that derive the format from the target.
                'hdr' => true,
            ]);
            if ($pipeline === false) {
                return;
            }
            $this->pipelineCache[$key] = $pipeline;
        }
        vio_bind_pipeline($this->ctx, $this->pipelineCache[$key]);
    }

    /** Draw one mesh into the G-buffer (mirrors drawMeshCommand's transforms). */
    private function drawGbufferMesh(string $meshId, Material $material, Mat4 $modelMatrix, string $materialId): void
    {
        $mesh = $this->uploadMesh($meshId);
        if ($mesh === null) {
            return;
        }
        vio_set_uniform($this->ctx, 'u_model', $modelMatrix->toArray());
        vio_set_uniform($this->ctx, 'u_use_instancing', 0);
        vio_set_uniform($this->ctx, 'u_normal_matrix', $this->computeNormalMatrix($modelMatrix));

        // Replay just the vertex-animation material state the G-buffer vert reads
        // so animated water/cloth write the SAME deformed position as the forward
        // pass (otherwise AO haloes at those surfaces).
        $this->applyGbufferVertexAnim($material, $materialId);
        $this->bindMeshAabb($meshId);
        vio_draw($this->ctx, $mesh);
    }

    /** Draw one instanced mesh into the G-buffer (mirrors drawMeshInstancedCommand). */
    private function drawGbufferMeshInstanced(DrawMeshInstanced $cmd, Material $material): void
    {
        $instanceCount = $cmd->instanceCount >= 0 ? $cmd->instanceCount : count($cmd->matrices);
        if ($instanceCount <= 0) {
            return;
        }
        $mesh = $this->uploadMesh($cmd->meshId);
        if ($mesh === null) {
            return;
        }
        $this->applyGbufferVertexAnim($material, $cmd->materialId);
        $this->bindMeshAabb($cmd->meshId);
        vio_set_uniform($this->ctx, 'u_use_instancing', 1);
        [$packed, $count] = $this->resolveInstanceData($cmd->meshId, $material, $cmd);
        vio_draw_instanced($this->ctx, $mesh, $packed, $count);
        vio_set_uniform($this->ctx, 'u_use_instancing', 0);
    }

    /**
     * Per-draw vertex-animation toggles that gbuffer.vert.glsl consumes (the
     * water-animation switch and cloth-sway params) PLUS the per-material
     * reflectivity inputs that gbuffer.frag.glsl folds into the G-buffer's blue
     * channel (metallic / roughness / wetness). Mirrors the corresponding writes
     * in applyMaterial() so the G-buffer geometry deforms identically to the
     * forward pass and the SSR reflectivity matches the surface. The frame-global
     * wave / wind / rain values are set once in renderSsaoPass(), not here.
     */
    private function applyGbufferVertexAnim(Material $material, string $materialId): void
    {
        $procMode = self::$procModeCache[$materialId] ?? $this->resolveProcMode($materialId);
        vio_set_uniform($this->ctx, 'u_vertex_anim', $procMode === 2 ? 1 : 0);
        // gbuffer.frag uses u_proc_mode to apply the ocean's shoreline fade to
        // reflectivity (so the invisible inner ocean plane doesn't make the dry
        // beach reflect under SSR). Mirrors the forward path's u_proc_mode.
        vio_set_uniform($this->ctx, 'u_proc_mode', $procMode);
        vio_set_uniform($this->ctx, 'u_cloth', $material->cloth ? 1 : 0);
        vio_set_uniform($this->ctx, 'u_cloth_strength', $material->clothStrength);
        vio_set_uniform($this->ctx, 'u_cloth_frequency', $material->clothFrequency);
        vio_set_uniform($this->ctx, 'u_cloth_phase', $material->clothPhase);
        vio_set_uniform($this->ctx, 'u_cloth_anchor_top', $material->clothAnchorTop ? 1 : 0);

        // SSR reflectivity inputs (gbuffer.frag combines these into channel b).
        // Water (procMode 2 / 11) is the headline reflective surface; give it a
        // strong wetness floor so calm water mirrors even with a high authored
        // roughness, while still letting metals/wet props reflect via material.
        $wetness = $material->wetness;
        if ($procMode === 2 || $procMode === 11) {
            $wetness = max($wetness, 0.85);
        }
        vio_set_uniform($this->ctx, 'u_metallic', $material->metallic);
        vio_set_uniform($this->ctx, 'u_roughness', $material->roughness);
        vio_set_uniform($this->ctx, 'u_wetness', $wetness);
    }

    /**
     * (Re)allocate the SSAO targets to match the current backbuffer. G-buffer is
     * full-res RGBA16F (hdr); SSAO + blur are half-res (the AO is blurred, so
     * half-res is invisible and ~4x cheaper). Rebuilt whenever the backbuffer
     * size changes — same trigger as ensureBloomTargets().
     */
    private function ensureSsaoTargets(): void
    {
        $fw = max(1, $this->backbufferWidth);
        $fh = max(1, $this->backbufferHeight);
        if ($this->gbufferTarget !== null
            && $this->gbufferWidth === $fw && $this->gbufferHeight === $fh) {
            return;
        }
        $hw = max(1, (int) ($fw / 2));
        $hh = max(1, (int) ($fh / 2));
        // G-buffer is full-res RGBA16F (hdr => true): RGB = normalized VIEW-space
        // normal, A = linear VIEW depth at full FP16 precision (see
        // gbuffer.frag.glsl / ssao.frag.glsl). FP16 needs php-vio's hdr render
        // target AND an hdr-output G-buffer pipeline (bindGbufferPipeline) so the
        // D3D12 PSO RTV format matches the bound target. SSAO + blur stay default
        // RGBA8 (LDR) — the AO is a single 0..1 value in R, no FP16 needed.
        $this->gbufferTarget   = vio_render_target($this->ctx, ['width' => $fw, 'height' => $fh, 'hdr' => true]) ?: null;
        $this->ssaoTarget      = vio_render_target($this->ctx, ['width' => $hw, 'height' => $hh]) ?: null;
        $this->ssaoBlurTarget  = vio_render_target($this->ctx, ['width' => $hw, 'height' => $hh]) ?: null;
        $this->gbufferWidth  = $fw;
        $this->gbufferHeight = $fh;
    }

    /**
     * Lazy-create the 1x1 white texture bound to u_ssao_map when SSAO is off.
     * A solid-white AO map means "fully unoccluded" — so even if a fragment
     * sampled it, ao would be unchanged. Its real job is to keep the declared
     * sampler BOUND on D3D12 (an unbound SRV reads garbage / triggers the
     * dark-disc failure mode).
     */
    private function ensureWhiteTexture(): void
    {
        if ($this->whiteTexture !== null) {
            return;
        }
        // Raw 1x1 RGBA texture: vio_texture's raw form takes binary 'data'
        // (w*h*4 bytes), not a pixel array. White = (255,255,255,255).
        $tex = vio_texture($this->ctx, [
            'data' => pack('C*', 255, 255, 255, 255),
            'width' => 1,
            'height' => 1,
        ]);
        if ($tex !== false) {
            $this->whiteTexture = $tex;
        }
    }

    /**
     * Build the shadow light's projection and view matrices separately so the
     * shadow pass can submit them as u_projection / u_view (mirroring the
     * colour pass) rather than folding the combined matrix into u_view with an
     * identity projection. This keeps vio's D3D12 clip-space / depth-range
     * handling — which keys off the projection matrix — on the same code path
     * for both passes. (Math-identical to the combined form on GL.)
     *
     * NOTE: this split was once suspected of causing the cascade-0 "dark disc"
     * on D3D12. It was not — the disc was u_shadow_map sharing cascade 0's
     * texture unit (see uploadShadowUniforms). The split is retained because it
     * is the cleaner form.
     *
     * @return array{0: Mat4, 1: Mat4} [projection, view]
     */
    private function computeLightProjView(Vec3 $sunDirection, ?Vec3 $cameraTarget = null, ?float $orthoSize = null): array
    {
        $len = sqrt($sunDirection->x ** 2 + $sunDirection->y ** 2 + $sunDirection->z ** 2);
        if ($len < 0.001) {
            return [Mat4::identity(), Mat4::identity()];
        }
        $dx = $sunDirection->x / $len;
        $dy = $sunDirection->y / $len;
        $dz = $sunDirection->z / $len;

        $center = $cameraTarget ?? Vec3::zero();
        $s = $orthoSize ?? self::SHADOW_ORTHO_SIZE;

        if ($cameraTarget !== null) {
            $resolution = self::SHADOW_MAP_RESOLUTION;
            $worldUnitsPerTexel = (2.0 * $s) / $resolution;
            $center = new Vec3(
                round($center->x / $worldUnitsPerTexel) * $worldUnitsPerTexel,
                round($center->y / $worldUnitsPerTexel) * $worldUnitsPerTexel,
                round($center->z / $worldUnitsPerTexel) * $worldUnitsPerTexel,
            );
        }

        // Size the light frustum to THIS cascade. The old code put the light
        // a fixed 80 units back and used the same [0.5, 200] depth slab for
        // every cascade — so the small near cascade (s=15, a 30 m box) packed
        // its depth comparison into a 200 m range, collapsing bias-to-world
        // resolution. Backing the light off by the cascade extent + caster
        // headroom and bracketing near/far to the cascade restores a sane
        // bias-to-world ratio without changing the (fixed) per-cascade ortho
        // footprint. (This is a quality improvement, not the dark-disc fix —
        // that was a texture-unit binding bug; see uploadShadowUniforms.)
        $casterHeadroom = max(30.0, $s); // room for tall casters above the box
        $backoff = $s + $casterHeadroom;
        $lightPos = new Vec3(
            $center->x - $dx * $backoff,
            $center->y - $dy * $backoff,
            $center->z - $dz * $backoff,
        );

        $up = abs($dy) > 0.999
            ? new Vec3(0.0, 0.0, 1.0)
            : new Vec3(0.0, 1.0, 0.0);

        $lightView = self::lookAt($lightPos, $center, $up);
        $nearPlane = 0.5;
        $farPlane = $backoff + $s + 5.0; // reach the far/low side of the box
        $lightProj = Mat4::orthographic(-$s, $s, -$s, $s, $nearPlane, $farPlane);

        return [$lightProj, $lightView];
    }

    /**
     * @param list<SetDirectionalLight> $dirLights
     */
    private function uploadShadowUniforms(bool $hasShadowMap, array $dirLights): void
    {
        vio_set_uniform($this->ctx, 'u_has_shadow_map', $hasShadowMap ? 1 : 0);
        vio_set_uniform($this->ctx, 'u_csm_count', count(self::CASCADE_ORTHO_SIZES));

        if (!$hasShadowMap || empty($this->cascadeShadowTargets)) {
            // No real shadow map this frame, but mesh3d.frag.glsl still
            // declares sampler2DShadow uniforms (u_csm_map_0..2, u_shadow_map).
            // macOS OpenGL collapses the fragment stage to a constant grey
            // when those samplers reference a default texture unit that
            // doesn't hold a depth texture - even though the runtime control
            // flow gated by u_has_shadow_map never samples them. Binding a
            // pre-allocated 1x1 depth target keeps the driver happy without
            // affecting the lighting result.
            $this->ensureDummyShadowTarget();
            if ($this->dummyShadowTarget !== null) {
                $dummyTex = vio_render_target_texture($this->dummyShadowTarget);
                foreach ([6, 8, 9] as $unit) {
                    vio_bind_texture($this->ctx, $dummyTex, $unit);
                }
                vio_set_uniform($this->ctx, 'u_csm_map_0',  6);
                vio_set_uniform($this->ctx, 'u_csm_map_1',  8);
                vio_set_uniform($this->ctx, 'u_csm_map_2',  9);
                vio_set_uniform($this->ctx, 'u_shadow_map', 6);
            }
            return;
        }

        // Render-target Y convention for the CSM SAMPLE matrix, owned centrally
        // by BackendConventions (true for D3D11/D3D12, false for OpenGL where a
        // flip would mirror the V lookup and break GL shadows). The matching
        // RENDER matrix in renderShadowPass is left un-flipped; both must agree.
        // This flag is correct as-is: with the cascade SRV binding fixed below,
        // cascades 1/2 (and now 0) sample correctly on D3D12 with flipY=true.
        // (The former "dark disc" was NOT a Y/clip convention bug — it was
        // u_shadow_map sharing cascade 0's texture unit; see the cIdx===0 block.)
        $flipY = $this->conventions()->flipRenderTargetClipY();

        // Texture units chosen to match the OpenGL CSM budget. Length
        // must equal CASCADE_ORTHO_SIZES; if the cascade count is ever
        // raised, extend this array in the same change.
        $cascadeUnits = [6, 8, 9];
        foreach (self::CASCADE_ORTHO_SIZES as $cIdx => $orthoSize) {
            $target = $this->cascadeShadowTargets[$cIdx] ?? null;
            $matrix = $this->cascadeLightSpaceMatrices[$cIdx] ?? null;
            if ($target === null || $matrix === null) {
                continue;
            }
            $tex = vio_render_target_texture($target);
            $unit = $cascadeUnits[$cIdx];
            vio_bind_texture($this->ctx, $tex, $unit);

            $lsm = $matrix->toArray();
            if ($flipY) {
                $lsm[1]  = -$lsm[1];
                $lsm[5]  = -$lsm[5];
                $lsm[9]  = -$lsm[9];
                $lsm[13] = -$lsm[13];
            }

            vio_set_uniform($this->ctx, "u_csm_map_{$cIdx}",    $unit);
            vio_set_uniform($this->ctx, "u_csm_matrix_{$cIdx}", $lsm);
            vio_set_uniform($this->ctx, "u_csm_far_{$cIdx}",    $orthoSize);

            // Cascade 0 also drives the legacy single-map uniforms (u_shadow_map)
            // used by cloud-shadow paths. CSM "dark disc" ROOT CAUSE + FIX:
            // u_shadow_map must NOT share cascade 0's texture unit. mesh3d.frag
            // declares u_shadow_map and u_csm_map_0 as separate samplers, which
            // map to distinct fixed registers on the typed-register backends
            // (D3D11/D3D12/Metal/Vulkan). OpenGL happily lets two samplers alias
            // one texture unit, but on D3D12 binding unit 6 resolves to only ONE
            // register, leaving u_csm_map_0's register unbound -> cascade 0
            // sampled an empty SRV (reads 0) -> every fragment within the
            // cascade-0 radius compared "occluded" -> the camera-following dark
            // disc. (It was never a Y-flip / reverse-Z / clip-convention issue;
            // those only mirrored or shifted the artefact.) Bind the same depth
            // texture to its own distinct unit so both samplers get a register.
            if ($cIdx === 0) {
                $legacyUnit = 7;
                vio_bind_texture($this->ctx, $tex, $legacyUnit);
                vio_set_uniform($this->ctx, 'u_shadow_map', $legacyUnit);
                vio_set_uniform($this->ctx, 'u_light_space_matrix', $lsm);
            }
        }
    }

    /**
     * Lazy-create the 1x1 depth target used as a placeholder for the
     * shadow samplers when no scene shadow map is active. Allocation
     * happens on first frame so contexts that never enter the lit 3D
     * path don't pay for it. Returns silently if vio refuses the
     * depth-only RT - in that case mesh3d's shadow path still works
     * because the engine just won't render unlit objects through it.
     */
    private function ensureDummyShadowTarget(): void
    {
        if ($this->dummyShadowTarget !== null) {
            return;
        }
        $rt = vio_render_target($this->ctx, [
            'width'      => 1,
            'height'     => 1,
            'depth_only' => true,
        ]);
        if ($rt !== false) {
            $this->dummyShadowTarget = $rt;
        }
    }

    private function bindShadowPipeline(): void
    {
        $key = 'shadow:shadow';

        if (!isset($this->pipelineCache[$key])) {
            $shader = $this->shaderCache['shadow'];

            // Use VIO_CULL_NONE, not VIO_CULL_FRONT. Front-face culling is the
            // classic "render back faces into the shadow map" peter-panning
            // trick, but it only works for CLOSED, double-sided geometry. Our
            // procedural GROUND / terrain is a single-sided up-facing surface —
            // front-face culling would discard it entirely so it never writes
            // depth into the shadow map. CULL_NONE makes the ground write its
            // true depth, so its own fragments compare lit; acne is held off by
            // the depth_bias / slope_scaled_depth_bias below plus the shader's
            // NdotL bias.
            //
            // (This was once mis-attributed as the D3D12 "dark disc" fix. It is
            // not — the disc was u_shadow_map sharing cascade 0's texture unit,
            // fixed in uploadShadowUniforms. CULL_NONE remains the correct cull
            // mode for single-sided casters regardless.)
            //
            // REVERT: restore 'cull_mode' => VIO_CULL_FRONT to go back to the
            // back-face-only shadow pass (the prior baseline).
            $pipeline = vio_pipeline($this->ctx, [
                'shader' => $shader,
                'depth_test' => true,
                'cull_mode' => VIO_CULL_NONE,
                'blend' => VIO_BLEND_NONE,
                'depth_bias' => 1.0,
                'slope_scaled_depth_bias' => 1.0,
            ]);

            if ($pipeline === false) {
                return;
            }

            $this->pipelineCache[$key] = $pipeline;
        }

        vio_bind_pipeline($this->ctx, $this->pipelineCache[$key]);
    }

    // ----------------------------------------------------------------
    // Skybox / Cubemap
    // ----------------------------------------------------------------

    private function initSkyboxMesh(): void
    {
        // Huge cube (±500 units). The vertex shader strips the view matrix's
        // translation so the cube stays centred on the camera; the large
        // size ensures the cube is always beyond the near plane regardless
        // of camera position within the scene, and the projected depth is
        // close to the far plane so LEQUAL lets opaque geometry win at
        // every already-rendered pixel.
        // Size chosen so the farthest cube vertex (diagonal corner) is
        // just inside a 500-unit camera far plane: 250 * sqrt(3) ≈ 433.
        $s = 250.0;
        $v = [
            -$s, -$s, -$s,
             $s, -$s, -$s,
             $s,  $s, -$s,
            -$s,  $s, -$s,
            -$s, -$s,  $s,
             $s, -$s,  $s,
             $s,  $s,  $s,
            -$s,  $s,  $s,
        ];

        $indices = [
            3,0,1, 1,2,3, // back  (-Z)
            4,0,3, 3,7,4, // left  (-X)
            1,5,6, 6,2,1, // right (+X)
            4,7,6, 6,5,4, // front (+Z)
            3,2,6, 6,7,3, // top   (+Y)
            0,4,5, 5,1,0, // bottom(-Y)
        ];

        $mesh = vio_mesh($this->ctx, [
            'vertices' => $v,
            'indices' => $indices,
            'layout' => [
                ['location' => 0, 'components' => 3],
            ],
        ]);

        if ($mesh !== false) {
            $this->skyboxMesh = $mesh;
        }
    }

    private function loadCubemap(string $cubemapId): ?VioCubemap
    {
        // Resolve the current source first so cache freshness can be checked
        // by object identity — CubemapRegistry hands out the same instance
        // until someone calls registerProcedural again with new data.
        $source = CubemapRegistry::isProcedural($cubemapId)
            ? CubemapRegistry::getProcedural($cubemapId)
            : CubemapRegistry::get($cubemapId);

        if (isset($this->cubemapCache[$cubemapId])) {
            $cached = $this->cubemapCache[$cubemapId];
            if ($cached['source'] === $source) {
                return $cached['cubemap'];
            }
            // Source was replaced (e.g. DayNightSystem regenerated the sky).
            // Drop the cache entry so we re-upload below; the old VioCubemap
            // is released by PHP's GC once the last reference goes out of scope.
            unset($this->cubemapCache[$cubemapId]);
        }

        if ($source === null) {
            return null;
        }

        $cubemap = false;
        if ($source instanceof CubemapData) {
            $cubemap = vio_cubemap($this->ctx, [
                'pixels' => $source->faces,
                'width' => $source->resolution,
                'height' => $source->resolution,
            ]);
        } elseif ($source instanceof CubemapFaces) {
            $cubemap = vio_cubemap($this->ctx, [
                'faces' => $source->toArray(),
            ]);
        }

        if ($cubemap === false) {
            return null;
        }

        $this->cubemapCache[$cubemapId] = ['cubemap' => $cubemap, 'source' => $source];
        return $cubemap;
    }

    private function renderAtmosphericSky(SetSky $sky): void
    {
        $quad = $this->screenQuad;
        $viewMatrix = $this->currentViewMatrix;
        $projMatrix = $this->currentProjectionMatrix;
        if ($quad === null || $viewMatrix === null || $projMatrix === null) {
            return;
        }

        // Build inverse(projection * view_without_translation). The fragment
        // shader uses this to unproject NDC back into a world-space view
        // direction. Translation is stripped so direction is independent of
        // camera position — the sky only depends on where the camera LOOKS.
        $vm = $viewMatrix->toArray();
        $rotView = new Mat4([
            $vm[0], $vm[1], $vm[2], 0.0,
            $vm[4], $vm[5], $vm[6], 0.0,
            $vm[8], $vm[9], $vm[10], 0.0,
            0.0,    0.0,    0.0,     1.0,
        ]);
        // GL convention: gl_Position = projection * view * worldPos, so
        // the clip→world mapping we need is inverse(projection * rotView).
        $invVP = $projMatrix->multiply($rotView)->inverse()->toArray();

        $camPos = $this->cameraPosition ?? new Vec3(0.0, 0.0, 0.0);
        $sunDir = $sky->sunDirection;

        // The sky is composited as one layered fullscreen pass per element: the
        // gradient writes opaque, then sun/moon/stars ADD light and clouds/haze
        // alpha-blend on top. Each element is skipped entirely when its driving
        // value is zero, so an "off" element costs nothing — and each lives in
        // its own shader (sky_*.frag.glsl), editable/toggleable on its own.

        // 1. Base gradient (opaque) — always.
        $this->bindSkyPipeline('sky_gradient', VIO_BLEND_NONE);
        vio_set_uniform($this->ctx, 'u_sky_inv_vp', $invVP);
        vio_set_uniform($this->ctx, 'u_zenith_color', [$sky->zenithColor->r, $sky->zenithColor->g, $sky->zenithColor->b]);
        vio_set_uniform($this->ctx, 'u_horizon_color', [$sky->horizonColor->r, $sky->horizonColor->g, $sky->horizonColor->b]);
        vio_set_uniform($this->ctx, 'u_ground_color', [$sky->groundColor->r, $sky->groundColor->g, $sky->groundColor->b]);
        vio_draw($this->ctx, $quad);

        // 2. Sun (additive).
        if ($sky->sunIntensity > 0.0) {
            $this->bindSkyPipeline('sky_sun', VIO_BLEND_ADDITIVE);
            vio_set_uniform($this->ctx, 'u_sky_inv_vp', $invVP);
            vio_set_uniform($this->ctx, 'u_sun_direction', [$sunDir->x, $sunDir->y, $sunDir->z]);
            vio_set_uniform($this->ctx, 'u_sun_color', [$sky->sunColor->r, $sky->sunColor->g, $sky->sunColor->b]);
            vio_set_uniform($this->ctx, 'u_sun_intensity', $sky->sunIntensity);
            vio_set_uniform($this->ctx, 'u_sun_size', $sky->sunSize);
            vio_set_uniform($this->ctx, 'u_sun_glow_size', $sky->sunGlowSize);
            vio_set_uniform($this->ctx, 'u_sun_glow_intensity', $sky->sunGlowIntensity);
            vio_draw($this->ctx, $quad);
        }

        // 3. Moon (additive).
        if ($sky->moonIntensity > 0.0) {
            $moonDir = $sky->moonDirection ?? new Vec3(0.0, -1.0, 0.0);
            $this->bindSkyPipeline('sky_moon', VIO_BLEND_ADDITIVE);
            vio_set_uniform($this->ctx, 'u_sky_inv_vp', $invVP);
            vio_set_uniform($this->ctx, 'u_moon_direction', [$moonDir->x, $moonDir->y, $moonDir->z]);
            vio_set_uniform($this->ctx, 'u_moon_color', [$sky->moonColor->r, $sky->moonColor->g, $sky->moonColor->b]);
            vio_set_uniform($this->ctx, 'u_moon_intensity', $sky->moonIntensity);
            vio_set_uniform($this->ctx, 'u_sun_size', $sky->sunSize);
            vio_set_uniform($this->ctx, 'u_sun_glow_size', $sky->sunGlowSize);
            vio_draw($this->ctx, $quad);
        }

        // 4. Stars (additive).
        if ($sky->starBrightness > 0.0) {
            $this->bindSkyPipeline('sky_stars', VIO_BLEND_ADDITIVE);
            vio_set_uniform($this->ctx, 'u_sky_inv_vp', $invVP);
            vio_set_uniform($this->ctx, 'u_star_brightness', $sky->starBrightness);
            vio_draw($this->ctx, $quad);
        }

        // 5. Clouds (alpha).
        if ($sky->cloudCover > 0.0) {
            // Normalise wind direction in the XZ plane so clouds drift in world space.
            $wd = $sky->cloudWindDirection;
            $wl = sqrt($wd->x * $wd->x + $wd->z * $wd->z);
            $wx = $wl > 1e-6 ? $wd->x / $wl : 1.0;
            $wz = $wl > 1e-6 ? $wd->z / $wl : 0.0;

            $this->bindSkyPipeline('sky_clouds', VIO_BLEND_ALPHA);
            vio_set_uniform($this->ctx, 'u_sky_inv_vp', $invVP);
            vio_set_uniform($this->ctx, 'u_camera_pos', [$camPos->x, $camPos->y, $camPos->z]);
            vio_set_uniform($this->ctx, 'u_sun_direction', [$sunDir->x, $sunDir->y, $sunDir->z]);
            vio_set_uniform($this->ctx, 'u_sun_color', [$sky->sunColor->r, $sky->sunColor->g, $sky->sunColor->b]);
            vio_set_uniform($this->ctx, 'u_sun_intensity', $sky->sunIntensity);
            vio_set_uniform($this->ctx, 'u_cloud_cover', $sky->cloudCover);
            vio_set_uniform($this->ctx, 'u_cloud_altitude', $sky->cloudAltitude);
            vio_set_uniform($this->ctx, 'u_cloud_density', $sky->cloudDensity);
            vio_set_uniform($this->ctx, 'u_cloud_wind_speed', $sky->cloudWindSpeed);
            vio_set_uniform($this->ctx, 'u_cloud_wind_dir', [$wx, $wz]);
            vio_set_uniform($this->ctx, 'u_cloud_darkness', $sky->cloudDarkness);
            vio_set_uniform($this->ctx, 'u_time', $sky->time);
            vio_draw($this->ctx, $quad);
        }

        // 6. Horizon haze (alpha).
        if ($sky->fogDensity > 0.0) {
            $this->bindSkyPipeline('sky_haze', VIO_BLEND_ALPHA);
            vio_set_uniform($this->ctx, 'u_sky_inv_vp', $invVP);
            vio_set_uniform($this->ctx, 'u_horizon_color', [$sky->horizonColor->r, $sky->horizonColor->g, $sky->horizonColor->b]);
            vio_set_uniform($this->ctx, 'u_fog_density', $sky->fogDensity);
            vio_draw($this->ctx, $quad);
        }
    }

    /**
     * Bind a sky-element pipeline (cached per shader id + blend). Every sky pass
     * is a fullscreen quad with depth-test off; only the blend mode differs —
     * NONE for the opaque gradient, ADDITIVE for emissive elements (sun/moon/
     * stars), ALPHA for clouds/haze.
     */
    private function bindSkyPipeline(string $shaderId, int $blend): void
    {
        $hdr = $this->sceneTargetIsHdr();
        $key = 'sky:' . $shaderId . ($hdr ? ':hdr' : '');
        if (!isset($this->pipelineCache[$key])) {
            $shader = $this->shaderCache[$shaderId] ?? null;
            if ($shader === null) {
                return;
            }
            $pipeline = vio_pipeline($this->ctx, [
                'shader' => $shader,
                'depth_test' => false,
                'cull_mode' => VIO_CULL_NONE,
                'blend' => $blend,
                // Sky draws into the FP16 scene target on the HDR path — match
                // the PSO RTV format. No-op off D3D12.
                'hdr' => $hdr,
            ]);
            if ($pipeline === false) {
                return;
            }
            $this->pipelineCache[$key] = $pipeline;
        }
        vio_bind_pipeline($this->ctx, $this->pipelineCache[$key]);
        // ALL sky shaders linearise their (display-referred) colour under HDR so
        // the resolve tonemap reproduces the authored look. The additive
        // sun/moon/stars passes inverse-tonemap their additive contribution too,
        // so an isolated disc/glow round-trips to its LDR appearance (no
        // over-bright/over-spread under HDR).
        vio_set_uniform($this->ctx, 'u_linear_output', $hdr ? 1 : 0);
    }

    private function renderSkybox(string $cubemapId): void
    {
        $viewMatrix = $this->currentViewMatrix;
        $projMatrix = $this->currentProjectionMatrix;
        $skyMesh = $this->skyboxMesh;

        if ($skyMesh === null || $viewMatrix === null || $projMatrix === null) {
            return;
        }

        $cubemap = $this->loadCubemap($cubemapId);
        if ($cubemap === null) {
            return;
        }

        // Skybox pipeline: depth test LEQUAL, no cull (inside of cube)
        $this->bindSkyboxPipeline();

        // View matrix without translation (skybox follows camera)
        $vm = $viewMatrix->toArray();
        $rotView = new Mat4([
            $vm[0], $vm[1], $vm[2], $vm[3],
            $vm[4], $vm[5], $vm[6], $vm[7],
            $vm[8], $vm[9], $vm[10], $vm[11],
            0.0,    0.0,    0.0,     1.0,
        ]);

        vio_set_uniform($this->ctx, 'u_view', $rotView->toArray());
        vio_set_uniform($this->ctx, 'u_projection', $projMatrix->toArray());

        vio_bind_cubemap($this->ctx, $cubemap, 0);
        vio_set_uniform($this->ctx, 'u_skybox', 0);

        vio_draw($this->ctx, $skyMesh);
    }

    private function bindSkyboxPipeline(): void
    {
        $hdr = $this->sceneTargetIsHdr();
        $key = 'skybox:skybox' . ($hdr ? ':hdr' : '');

        if (!isset($this->pipelineCache[$key])) {
            $shader = $this->shaderCache['skybox'];

            // Skybox is rendered FIRST (before opaque geometry) with depth
            // test disabled. The cube fills every pixel with cubemap color;
            // opaque geometry then overwrites with its own depth writes.
            // This avoids the classic .xyww far-plane trick which doesn't
            // survive SPIRV-Cross's HLSL depth-convention fixup, and it
            // also avoids far-plane clipping at the cube's diagonal corners.
            $pipeline = vio_pipeline($this->ctx, [
                'shader' => $shader,
                'depth_test' => false,
                'cull_mode' => VIO_CULL_NONE,
                'blend' => VIO_BLEND_NONE,
                // Skybox draws into the FP16 scene target on the HDR path.
                'hdr' => $hdr,
            ]);

            if ($pipeline === false) {
                return;
            }

            $this->pipelineCache[$key] = $pipeline;
        }

        vio_bind_pipeline($this->ctx, $this->pipelineCache[$key]);
    }

    // ----------------------------------------------------------------
    // Mesh management
    // ----------------------------------------------------------------

    private function uploadMesh(string $meshId): ?VioMesh
    {
        $version = MeshRegistry::version($meshId);
        if (isset($this->meshCache[$meshId]) && ($this->meshCacheVersions[$meshId] ?? -1) === $version) {
            return $this->meshCache[$meshId];
        }

        $meshData = MeshRegistry::get($meshId);
        if ($meshData === null) {
            return null;
        }

        $interleaved = $this->interleaveMeshData($meshData);

        $vioMesh = vio_mesh($this->ctx, [
            'vertices' => $interleaved,
            'indices' => $meshData->indices,
            'layout' => [
                ['location' => 0, 'components' => 3],
                ['location' => 1, 'components' => 3],
                ['location' => 2, 'components' => 2],
            ],
        ]);

        if ($vioMesh === false) {
            return null;
        }

        $this->meshCache[$meshId] = $vioMesh;
        $this->meshCacheVersions[$meshId] = $version;
        return $vioMesh;
    }

    /** @return float[] */
    private function interleaveMeshData(MeshData $meshData): array
    {
        $vertexCount = $meshData->vertexCount();
        $interleaved = [];

        for ($i = 0; $i < $vertexCount; $i++) {
            $vi = $i * 3;
            $ui = $i * 2;

            $interleaved[] = $meshData->vertices[$vi] ?? 0.0;
            $interleaved[] = $meshData->vertices[$vi + 1] ?? 0.0;
            $interleaved[] = $meshData->vertices[$vi + 2] ?? 0.0;

            $interleaved[] = $meshData->normals[$vi] ?? 0.0;
            $interleaved[] = $meshData->normals[$vi + 1] ?? 0.0;
            $interleaved[] = $meshData->normals[$vi + 2] ?? 0.0;

            $interleaved[] = $meshData->uvs[$ui] ?? 0.0;
            $interleaved[] = $meshData->uvs[$ui + 1] ?? 0.0;
        }

        return $interleaved;
    }

    // ----------------------------------------------------------------
    // Drawing
    // ----------------------------------------------------------------

    private function drawMeshCommand(string $meshId, Material $material, Mat4 $modelMatrix, string $materialId = ''): void
    {
        $mesh = $this->uploadMesh($meshId);
        if ($mesh === null) {
            return;
        }

        // Per-draw uniforms (vary per transform — always set)
        vio_set_uniform($this->ctx, 'u_model', $modelMatrix->toArray());
        vio_set_uniform($this->ctx, 'u_use_instancing', 0);

        $nm = $this->computeNormalMatrix($modelMatrix);
        vio_set_uniform($this->ctx, 'u_normal_matrix', $nm);

        // Sticky-uniform dedup: skip the ~28 material uniforms / mesh AABB when
        // unchanged from the previous draw (the opaque pass sorts by material+mesh
        // so identical draws cluster). Texture binding is always issued.
        if ($materialId !== $this->lastMaterialId) {
            $this->applyMaterialUniforms($material, $materialId);
            $this->lastMaterialId = $materialId;
        }
        $this->bindMaterialTextures($material);
        if ($meshId !== $this->lastMeshId) {
            $this->bindMeshAabb($meshId);
            $this->lastMeshId = $meshId;
        }

        vio_draw($this->ctx, $mesh);
    }

    private function drawMeshInstancedCommand(DrawMeshInstanced $cmd, Material $material): void
    {
        // Hot-path: read public properties directly instead of via the
        // accessors. The extra method-call dispatch broke the
        // boxes-1000-instanced perf budget on first CI run.
        $instanceCount = $cmd->instanceCount >= 0 ? $cmd->instanceCount : count($cmd->matrices);
        if ($instanceCount <= 0) {
            return;
        }

        $mesh = $this->uploadMesh($cmd->meshId);
        if ($mesh === null) {
            return;
        }

        // Sticky-uniform dedup (shared trackers with drawMeshCommand — same
        // shader cbuffer). Texture binding always issued; u_use_instancing toggled
        // around the draw and reset to 0 so a following non-instanced draw is fine.
        if ($cmd->materialId !== $this->lastMaterialId) {
            $this->applyMaterialUniforms($material, $cmd->materialId);
            $this->lastMaterialId = $cmd->materialId;
        }
        $this->bindMaterialTextures($material);
        if ($cmd->meshId !== $this->lastMeshId) {
            $this->bindMeshAabb($cmd->meshId);
            $this->lastMeshId = $cmd->meshId;
        }
        vio_set_uniform($this->ctx, 'u_use_instancing', 1);

        [$packed, $count] = $this->resolveInstanceData($cmd->meshId, $material, $cmd);
        vio_draw_instanced($this->ctx, $mesh, $packed, $count);

        vio_set_uniform($this->ctx, 'u_use_instancing', 0);
    }

    /**
     * Resolve instance matrix data as a packed binary string (fast path).
     * Honours both DrawMeshInstanced storage modes: when the command
     * carries a flat float[] buffer the per-Mat4 toArray() loop is
     * skipped and pack('f*', ...) consumes the floats directly.
     *
     * @return array{0: string, 1: int}
     */
    private function resolveInstanceData(string $meshId, Material $material, DrawMeshInstanced $cmd): array
    {
        $isStatic = $cmd->isStatic;
        $cacheKey = $meshId . ':' . ($material->shader) . ':' . spl_object_id($material);

        if ($isStatic && isset($this->staticMatrixCache[$cacheKey])) {
            return [$this->staticMatrixCache[$cacheKey], $this->staticInstanceCountCache[$cacheKey]];
        }

        if ($cmd->flatMatrices !== []) {
            $packed = pack('f*', ...$cmd->flatMatrices);
            $count = $cmd->instanceCount >= 0 ? $cmd->instanceCount : count($cmd->matrices);
        } else {
            $floats = [];
            foreach ($cmd->matrices as $matrix) {
                foreach ($matrix->toArray() as $v) {
                    $floats[] = $v;
                }
            }
            $packed = pack('f*', ...$floats);
            $count = count($cmd->matrices);
        }

        if ($isStatic) {
            $this->staticMatrixCache[$cacheKey] = $packed;
            $this->staticInstanceCountCache[$cacheKey] = $count;
        }

        return [$packed, $count];
    }

    /**
     * Push the AABB of the mesh that the next draw will use. Drives
     * the cloth-sway anchor weighting in mesh3d.vert.glsl. Cached
     * once per mesh id; called by every drawMeshCommand /
     * drawMeshInstancedCommand entry.
     */
    private function bindMeshAabb(string $meshId): void
    {
        $aabb = $this->meshAabb($meshId);
        vio_set_uniform($this->ctx, 'u_mesh_local_aabb_min', $aabb['min']);
        vio_set_uniform($this->ctx, 'u_mesh_local_aabb_max', $aabb['max']);
    }

    /**
     * @return array{min: array{0:float,1:float,2:float}, max: array{0:float,1:float,2:float}}
     */
    private function meshAabb(string $meshId): array
    {
        if (isset($this->meshAabbCache[$meshId])) {
            return $this->meshAabbCache[$meshId];
        }
        $mesh = MeshRegistry::get($meshId);
        if ($mesh === null) {
            $aabb = ['min' => [0.0, 0.0, 0.0], 'max' => [0.0, 0.0, 0.0]];
            $this->meshAabbCache[$meshId] = $aabb;
            return $aabb;
        }
        $minX = INF; $minY = INF; $minZ = INF;
        $maxX = -INF; $maxY = -INF; $maxZ = -INF;
        $verts = $mesh->vertices;
        $count = count($verts);
        for ($i = 0; $i < $count; $i += 3) {
            $x = $verts[$i];     $y = $verts[$i + 1]; $z = $verts[$i + 2];
            if ($x < $minX) $minX = $x;
            if ($y < $minY) $minY = $y;
            if ($z < $minZ) $minZ = $z;
            if ($x > $maxX) $maxX = $x;
            if ($y > $maxY) $maxY = $y;
            if ($z > $maxZ) $maxZ = $z;
        }
        $aabb = [
            'min' => [$minX === INF ? 0.0 : $minX, $minY === INF ? 0.0 : $minY, $minZ === INF ? 0.0 : $minZ],
            'max' => [$maxX === -INF ? 0.0 : $maxX, $maxY === -INF ? 0.0 : $maxY, $maxZ === -INF ? 0.0 : $maxZ],
        ];
        $this->meshAabbCache[$meshId] = $aabb;
        return $aabb;
    }

    /**
     * World-space bounding sphere [cx, cy, cz, radius] for a mesh under a model
     * matrix, derived from the cached local AABB. Used for conservative
     * shadow-cascade culling.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function worldMeshSphere(string $meshId, Mat4 $modelMatrix): array
    {
        $aabb = $this->meshAabb($meshId);
        $min = $aabb['min'];
        $max = $aabb['max'];
        $ex = ($max[0] - $min[0]) * 0.5;
        $ey = ($max[1] - $min[1]) * 0.5;
        $ez = ($max[2] - $min[2]) * 0.5;
        $localR = sqrt($ex * $ex + $ey * $ey + $ez * $ez);

        $center = $modelMatrix->transformPoint(new Vec3(
            ($min[0] + $max[0]) * 0.5,
            ($min[1] + $max[1]) * 0.5,
            ($min[2] + $max[2]) * 0.5,
        ));

        // Largest axis scale from the matrix basis columns (column-major).
        $m = $modelMatrix->toArray();
        $s0 = $m[0] * $m[0] + $m[1] * $m[1] + $m[2] * $m[2];
        $s1 = $m[4] * $m[4] + $m[5] * $m[5] + $m[6] * $m[6];
        $s2 = $m[8] * $m[8] + $m[9] * $m[9] + $m[10] * $m[10];
        $maxScale = sqrt(max($s0, $s1, $s2));

        return [$center->x, $center->y, $center->z, $localR * $maxScale];
    }

    /**
     * Whether a material casts shadows (everything except sky / sun / moon /
     * cloud / precipitation). Cached per material id.
     */
    private function castsShadow(string $materialId): bool
    {
        return $this->castsShadowCache[$materialId] ??= !(
            str_starts_with($materialId, 'sky_')
            || str_starts_with($materialId, 'sun_')
            || str_starts_with($materialId, 'moon_')
            || str_starts_with($materialId, 'cloud_')
            || $materialId === 'precipitation'
        );
    }

    /**
     * Upload the per-material scalar/vector uniforms (NOT the texture bind).
     * This is a pure function of ($material, $materialId) — every value derives
     * from the Material or the per-id procMode cache, with no per-draw or
     * frame-varying input (snow/rain/etc. live in uploadFrameUniforms). Because
     * vio cbuffer uniforms are sticky (a uniform keeps its last value until
     * overwritten, and vio_draw snapshots the current cbuffer without clearing
     * it), the draw path skips this call when the materialId is unchanged from
     * the previous draw — see drawMeshCommand. Texture binding is split out into
     * bindMaterialTextures() and always issued (not deduped) for safety.
     */
    private function applyMaterialUniforms(Material $material, string $materialId = ''): void
    {
        vio_set_uniform($this->ctx, 'u_albedo', [$material->albedo->r, $material->albedo->g, $material->albedo->b]);
        vio_set_uniform($this->ctx, 'u_emission', [$material->emission->r, $material->emission->g, $material->emission->b]);
        vio_set_uniform($this->ctx, 'u_roughness', $material->roughness);
        vio_set_uniform($this->ctx, 'u_metallic', $material->metallic);
        vio_set_uniform($this->ctx, 'u_alpha', $material->alpha);
        vio_set_uniform($this->ctx, 'u_clearcoat', $material->clearcoat);
        vio_set_uniform($this->ctx, 'u_clearcoat_roughness', $material->clearcoatRoughness);
        vio_set_uniform($this->ctx, 'u_flakes', $material->flakes);
        vio_set_uniform($this->ctx, 'u_normal_intensity', $material->normalIntensity);
        vio_set_uniform($this->ctx, 'u_use_environment_map', $material->useEnvironmentMap ? 1 : 0);
        vio_set_uniform($this->ctx, 'u_normal_pattern', NormalPattern::codeFor($material->normalPattern));
        vio_set_uniform($this->ctx, 'u_normal_scale', $material->normalScale);
        vio_set_uniform($this->ctx, 'u_surface_pattern', SurfacePattern::codeFor($material->surfacePattern));
        vio_set_uniform($this->ctx, 'u_surface_scale', $material->surfaceScale);
        vio_set_uniform($this->ctx, 'u_surface_intensity', $material->surfaceIntensity);
        vio_set_uniform($this->ctx, 'u_wetness', $material->wetness);

        // Subsurface scattering (skin path). Gated by strength > 0 in the
        // shader so non-skin materials remain visually identical.
        vio_set_uniform($this->ctx, 'u_subsurface_color', [
            $material->subsurfaceColor->r,
            $material->subsurfaceColor->g,
            $material->subsurfaceColor->b,
        ]);
        vio_set_uniform($this->ctx, 'u_subsurface_strength', $material->subsurfaceStrength);

        // Cloth (mirrors OpenGL backend - same uniform names).
        vio_set_uniform($this->ctx, 'u_cloth', $material->cloth ? 1 : 0);
        vio_set_uniform($this->ctx, 'u_cloth_strength', $material->clothStrength);
        vio_set_uniform($this->ctx, 'u_cloth_frequency', $material->clothFrequency);
        vio_set_uniform($this->ctx, 'u_cloth_phase', $material->clothPhase);
        vio_set_uniform($this->ctx, 'u_cloth_anchor_top', $material->clothAnchorTop ? 1 : 0);

        // Procedural material mode
        $procMode = self::$procModeCache[$materialId] ?? $this->resolveProcMode($materialId);
        vio_set_uniform($this->ctx, 'u_proc_mode', $procMode);

        // Water (proc_mode 2): enable GPU vertex wave animation
        vio_set_uniform($this->ctx, 'u_vertex_anim', $procMode === 2 ? 1 : 0);

        if ($procMode === 9) {
            vio_set_uniform($this->ctx, 'u_moon_phase', $material->roughness);
        }

        if ($procMode === 1) {
            vio_set_uniform($this->ctx, 'u_season_tint', [
                $material->albedo->r / 0.77,
                $material->albedo->g / 0.66,
                $material->albedo->b / 0.41,
            ]);
        } else {
            vio_set_uniform($this->ctx, 'u_season_tint', [1.0, 1.0, 1.0]);
        }
    }

    /**
     * Bind the material's albedo texture (if any) and the two texture-state
     * uniforms. ALWAYS issued per draw (never deduped): cbuffer-uniform
     * stickiness is verified, but texture-slot binding stickiness across
     * pipeline binds is not, so re-issuing the bind keeps slot 0 correct
     * regardless of what a prior material bound. Cheap (~1 bind + 2 uniforms).
     */
    private function bindMaterialTextures(Material $material): void
    {
        $hasTexture = false;
        if ($material->albedoTexture !== null && $this->textureManager !== null) {
            $vioTex = $this->resolveTexture($material->albedoTexture);
            if ($vioTex !== null) {
                vio_bind_texture($this->ctx, $vioTex, 0);
                $hasTexture = true;
            }
        }
        vio_set_uniform($this->ctx, 'u_has_albedo_texture', $hasTexture ? 1 : 0);
        vio_set_uniform($this->ctx, 'u_albedo_texture', 0);
    }

    private function resolveProcMode(string $materialId): int
    {
        $prefixRaw = strtok($materialId, '0123456789');
        $prefix = $prefixRaw === false ? $materialId : $prefixRaw;

        $mode = match (true) {
            str_starts_with($prefix, 'sand_terrain') => 1,
            str_starts_with($prefix, 'pool_water') => 11,
            str_starts_with($prefix, 'water_') => 2,
            str_starts_with($prefix, 'rock') => 3,
            str_starts_with($prefix, 'palm_trunk') => 4,
            str_starts_with($prefix, 'palm_branch'),
            str_starts_with($prefix, 'palm_leaves'),
            str_starts_with($prefix, 'palm_leaf'),
            str_starts_with($prefix, 'palm_canopy'),
            str_starts_with($prefix, 'palm_frond') => 5,
            str_starts_with($prefix, 'cloud_') => 6,
            str_starts_with($prefix, 'hut_wood'),
            str_starts_with($prefix, 'hut_door'),
            str_starts_with($prefix, 'hut_table'),
            str_starts_with($prefix, 'hut_chair'),
            str_starts_with($prefix, 'hut_floor'),
            str_starts_with($prefix, 'hut_window') => 7,
            str_starts_with($prefix, 'hut_thatch') => 8,
            str_starts_with($prefix, 'moon_disc') => 9,
            str_starts_with($prefix, 'car_paint') => 10,
            // Ruined / not-yet-rebuilt buildings. The intact box geometry is
            // shaded as weathered, cracked, moss- and soot-stained concrete so a
            // "ruined" variant reads as a ruin without any geometry/collider
            // change. Stays fully LIT (modulates albedo/rough then falls through
            // the normal PBR path). See mesh3d.frag.glsl proc_mode 13.
            // 'district_ruined' has no digits → prefix == full id.
            str_starts_with($prefix, 'district_ruined') => 13,
            // Self-illuminated learning hologram (HologramBoardPrefab's baked-text
            // materials, id 'hologram_text_<topic>_<locale>'). Unlit: shows only
            // the baked texture, immune to sun/ambient/fog so the text never
            // washes out in bright daylight. See mesh3d.frag.glsl proc_mode 12.
            str_starts_with($prefix, 'hologram_text') => 12,
            // Sci-fi holo-console accent surfaces (TerminalPrefab): the glowing
            // screen panel, its accent rim and the input light-bar. Unlit so the
            // accent colour (carried in albedo) glows the same day and night.
            str_starts_with($prefix, 'terminal_screen_lit'),
            str_starts_with($prefix, 'terminal_glow_edge'),
            str_starts_with($prefix, 'terminal_lightbar') => 12,
            default => 0,
        };

        self::$procModeCache[$materialId] = $mode;
        return $mode;
    }

    private function resolveTexture(string $textureId): ?VioTexture
    {
        if (isset($this->textureCache[$textureId])) {
            return $this->textureCache[$textureId];
        }

        if ($this->textureManager === null) {
            return null;
        }

        if (!$this->textureManager->has($textureId)) {
            try {
                $this->textureManager->load($textureId);
            } catch (\RuntimeException) {
                return null;
            }
        }

        $texture = $this->textureManager->get($textureId);
        if ($texture === null) {
            return null;
        }

        // Load via vio directly for 3D use
        $vioTex = vio_texture($this->ctx, ['file' => $texture->path]);
        if ($vioTex === false) {
            return null;
        }

        $this->textureCache[$textureId] = $vioTex;
        return $vioTex;
    }

    // ----------------------------------------------------------------
    // Uniform helpers
    // ----------------------------------------------------------------

    /**
     * @param array{ambientColor: Color, ambientIntensity: float, dirLights: list<SetDirectionalLight>, pointLights: list<AddPointLight>, spotLights: list<AddSpotLight>, fogColor: Color, fogNear: float, fogFar: float, waveEnabled: bool, waveAmplitude: float, waveFrequency: float, wavePhase: float, ftMode: \PHPolygon\Rendering\Quality\FieldtracingMode, ftIntensity: float, ftAoRadius: float} $state
     */
    private function uploadFrameUniforms(array $state): void
    {
        if ($this->currentViewMatrix === null || $this->currentProjectionMatrix === null) {
            if ($this->flashDbgEnabled()) {
                fprintf(STDERR, "[flashdbg] uploadFrameUniforms SKIPPED — no view/projection matrix this frame\n");
            }
            return;
        }
        vio_set_uniform($this->ctx, 'u_view', $this->currentViewMatrix->toArray());
        vio_set_uniform($this->ctx, 'u_projection', $this->currentProjectionMatrix->toArray());
        // Linear output: when the scene target is FP16 (HDR offscreen path) the
        // mesh shader skips its inline ACES+gamma and writes unclamped linear
        // colour; the resolve pass tonemaps. Otherwise it tonemaps inline (LDR).
        vio_set_uniform($this->ctx, 'u_linear_output', $this->sceneTargetIsHdr() ? 1 : 0);

        if ($this->cameraPosition !== null) {
            vio_set_uniform($this->ctx, 'u_camera_pos', [
                $this->cameraPosition->x, $this->cameraPosition->y, $this->cameraPosition->z,
            ]);
        }

        $ac = $state['ambientColor'];
        $ai = $state['ambientIntensity'];
        $piScale = M_PI; // compensate Lambertian /π in Cook-Torrance BRDF
        // Raw color + scaled intensity; the shader multiplies them ONCE
        // (color × intensity). Premultiplying the color here too would apply
        // intensity and π quadratically — ambient dimming (night, storms)
        // would respond as intensity² instead of linearly.
        vio_set_uniform($this->ctx, 'u_ambient_color', [$ac->r, $ac->g, $ac->b]);
        vio_set_uniform($this->ctx, 'u_ambient_intensity', $ai * $piScale);

        $dirCount = min(count($state['dirLights']), 4);

        // Flash-hunt diagnostic (PHPOLYGON_FLASH_DEBUG=1): these per-frame
        // values should only ever drift slowly. A step change in ONE frame is
        // exactly the kind of glitch that reads as a dark/bright flash, so log
        // old -> new whenever something jumps.
        if ($this->flashDbgEnabled()) {
            $d0 = $state['dirLights'][0] ?? null;
            $sig = [
                'dirCount'  => $dirCount,
                'intensity' => $d0->intensity ?? -1.0,
                'dirY'      => $d0?->direction->y ?? 0.0,
                'ambient'   => $ai,
                'cloudCov'  => $this->pendingSky->cloudCover ?? -1.0,
            ];
            $p = $this->flashDbgPrev;
            if ($p !== []) {
                $jumps = [];
                if ($sig['dirCount'] !== $p['dirCount'])                  $jumps[] = sprintf('dirCount %d->%d', $p['dirCount'], $sig['dirCount']);
                if (abs($sig['intensity'] - $p['intensity']) > 0.25)      $jumps[] = sprintf('sunIntensity %.3f->%.3f', $p['intensity'], $sig['intensity']);
                if (abs($sig['dirY'] - $p['dirY']) > 0.05)                $jumps[] = sprintf('sunDirY %.3f->%.3f', $p['dirY'], $sig['dirY']);
                if (abs($sig['ambient'] - $p['ambient']) > 0.05)          $jumps[] = sprintf('ambient %.3f->%.3f', $p['ambient'], $sig['ambient']);
                if (abs($sig['cloudCov'] - $p['cloudCov']) > 0.05)        $jumps[] = sprintf('cloudCover %.3f->%.3f', $p['cloudCov'], $sig['cloudCov']);
                if ($jumps !== []) {
                    fprintf(STDERR, "[flashdbg] %s  frame-uniform jump: %s\n", date('H:i:s'), implode(', ', $jumps));
                }
            }
            $this->flashDbgPrev = $sig;
        }

        vio_set_uniform($this->ctx, 'u_dir_light_count', $dirCount);
        for ($i = 0; $i < $dirCount; $i++) {
            $dl = $state['dirLights'][$i];
            vio_set_uniform($this->ctx, "u_dir_lights[{$i}].direction", [$dl->direction->x, $dl->direction->y, $dl->direction->z]);
            vio_set_uniform($this->ctx, "u_dir_lights[{$i}].color", [$dl->color->r, $dl->color->g, $dl->color->b]);
            vio_set_uniform($this->ctx, "u_dir_lights[{$i}].intensity", $dl->intensity * $piScale);
        }

        // The shader supports 8 point lights, but a scene can register many more
        // (street lamps, props, the cargo-plane interior, …). Upload the 8
        // NEAREST to the camera so close lights — e.g. the plane the player is
        // riding during the intro — always win instead of being dropped by
        // command order.
        $pointLights = $state['pointLights'];
        if (count($pointLights) > 8) {
            $cam = $this->currentViewMatrix->inverse()->getTranslation();
            $cx = $cam->x;
            $cy = $cam->y;
            $cz = $cam->z;
            usort($pointLights, static function (AddPointLight $a, AddPointLight $b) use ($cx, $cy, $cz): int {
                $da = ($a->position->x - $cx) ** 2 + ($a->position->y - $cy) ** 2 + ($a->position->z - $cz) ** 2;
                $db = ($b->position->x - $cx) ** 2 + ($b->position->y - $cy) ** 2 + ($b->position->z - $cz) ** 2;
                return $da <=> $db;
            });
        }
        $ptCount = min(count($pointLights), 8);
        vio_set_uniform($this->ctx, 'u_point_light_count', $ptCount);
        for ($i = 0; $i < $ptCount; $i++) {
            $pl = $pointLights[$i];
            vio_set_uniform($this->ctx, "u_point_lights[{$i}].position", [$pl->position->x, $pl->position->y, $pl->position->z]);
            vio_set_uniform($this->ctx, "u_point_lights[{$i}].color", [$pl->color->r, $pl->color->g, $pl->color->b]);
            vio_set_uniform($this->ctx, "u_point_lights[{$i}].intensity", $pl->intensity * $piScale);
            vio_set_uniform($this->ctx, "u_point_lights[{$i}].radius", $pl->radius);
        }

        $spotCount = min(count($state['spotLights']), 4);
        vio_set_uniform($this->ctx, 'u_spot_light_count', $spotCount);
        for ($i = 0; $i < $spotCount; $i++) {
            $sl = $state['spotLights'][$i];
            vio_set_uniform($this->ctx, "u_spot_lights[{$i}].position", [$sl->position->x, $sl->position->y, $sl->position->z]);
            vio_set_uniform($this->ctx, "u_spot_lights[{$i}].direction", [$sl->direction->x, $sl->direction->y, $sl->direction->z]);
            vio_set_uniform($this->ctx, "u_spot_lights[{$i}].color", [$sl->color->r, $sl->color->g, $sl->color->b]);
            vio_set_uniform($this->ctx, "u_spot_lights[{$i}].intensity", $sl->intensity * $piScale);
            vio_set_uniform($this->ctx, "u_spot_lights[{$i}].range", $sl->range);
            vio_set_uniform($this->ctx, "u_spot_lights[{$i}].angle", $sl->angle);
            vio_set_uniform($this->ctx, "u_spot_lights[{$i}].penumbra", $sl->penumbra);
        }

        $fc = $state['fogColor'];
        vio_set_uniform($this->ctx, 'u_fog_color', [$fc->r, $fc->g, $fc->b]);
        vio_set_uniform($this->ctx, 'u_fog_near', $state['fogNear']);
        vio_set_uniform($this->ctx, 'u_fog_far', $state['fogFar']);

        vio_set_uniform($this->ctx, 'u_time', $this->globalTime);
        vio_set_uniform($this->ctx, 'u_ao_strength', $this->settings->ambientOcclusion->strength());

        // Fieldtracing (SDF GI) — float mode (int-in-UBO is unreliable across
        // SPIRV-Cross targets). Off => 0.0 => strict no-op in the mesh shader.
        vio_set_uniform($this->ctx, 'u_ft_mode', (float) $this->fieldtracingModeCode($state['ftMode']));
        vio_set_uniform($this->ctx, 'u_ft_intensity', $state['ftIntensity']);
        vio_set_uniform($this->ctx, 'u_ft_ao', $state['ftAoRadius']);

        // Baked irradiance probe field (ProbesOnly+ tiers): bind the 3D texture
        // and its world transform so the mesh shader reconstructs directional
        // ambient GI from the SH-L1 coeffs instead of the flat hemisphere. Gated
        // on the tier being enabled AND a field being uploaded; otherwise the
        // shader's u_probe_enabled=0 path falls back to the analytic hemisphere.
        $probeOn = $state['ftMode'] !== Quality\FieldtracingMode::Off
            && $this->probeTexR !== null && $this->probeTexG !== null && $this->probeTexB !== null
            && $this->probeOrigin !== null && $this->probeSize !== null;
        if ($probeOn) {
            vio_bind_texture($this->ctx, $this->probeTexR, self::PROBE_R_SLOT);
            vio_bind_texture($this->ctx, $this->probeTexG, self::PROBE_G_SLOT);
            vio_bind_texture($this->ctx, $this->probeTexB, self::PROBE_B_SLOT);
            vio_set_uniform($this->ctx, 'u_probe_field_r', self::PROBE_R_SLOT);
            vio_set_uniform($this->ctx, 'u_probe_field_g', self::PROBE_G_SLOT);
            vio_set_uniform($this->ctx, 'u_probe_field_b', self::PROBE_B_SLOT);
            vio_set_uniform($this->ctx, 'u_probe_origin', [$this->probeOrigin->x, $this->probeOrigin->y, $this->probeOrigin->z]);
            vio_set_uniform($this->ctx, 'u_probe_size', [$this->probeSize->x, $this->probeSize->y, $this->probeSize->z]);
            vio_set_uniform($this->ctx, 'u_probe_range', $this->probeRange);
        }
        vio_set_uniform($this->ctx, 'u_probe_enabled', $probeOn ? 1.0 : 0.0);

        // Reflection probe cubemap: bind the baked environment so water mirrors it.
        // Cached by source identity in loadCubemap; u_has_environment_map=0 falls
        // back to the sky-tint reflection. (Bind is name-mapped to the sampler's
        // HLSL register by vio, so the logical unit here is arbitrary.)
        $envCube = $this->loadCubemap(self::ENV_CUBEMAP_ID);
        if ($envCube !== null) {
            vio_bind_cubemap($this->ctx, $envCube, self::ENV_CUBEMAP_SLOT);
            vio_set_uniform($this->ctx, 'u_environment_map', self::ENV_CUBEMAP_SLOT);
        }
        vio_set_uniform($this->ctx, 'u_has_environment_map', $envCube !== null ? 1 : 0);

        // Cloud-shadow uniforms: the mesh samples the SAME cloud density field
        // toward the sun (sky_clouds.frag mirror) so the volumetric clouds cast
        // moving shadow patches on the world. cover 0 → cloudShadow() no-ops.
        // The cloudShadows graphics setting gates ONLY this mesh-side path —
        // the sky pass keeps its clouds; they just stop dimming the world.
        $sky = $this->pendingSky;
        if ($sky !== null && $this->settings->cloudShadows) {
            $wd = $sky->cloudWindDirection;
            $wl = sqrt($wd->x * $wd->x + $wd->z * $wd->z);
            vio_set_uniform($this->ctx, 'u_cloud_cover', $sky->cloudCover);
            vio_set_uniform($this->ctx, 'u_cloud_altitude', $sky->cloudAltitude);
            vio_set_uniform($this->ctx, 'u_cloud_density', $sky->cloudDensity);
            vio_set_uniform($this->ctx, 'u_cloud_wind_speed', $sky->cloudWindSpeed);
            vio_set_uniform($this->ctx, 'u_cloud_wind_dir',
                [$wl > 1e-6 ? $wd->x / $wl : 1.0, $wl > 1e-6 ? $wd->z / $wl : 0.0]);
            // Same time base as the sky cloud pass so shadow patches track the clouds.
            vio_set_uniform($this->ctx, 'u_cloud_time', $sky->time);
        } else {
            vio_set_uniform($this->ctx, 'u_cloud_cover', 0.0);
        }

        // Color grading + vignette per-frame.
        $grade = $this->settings->colorGrading->params();
        vio_set_uniform($this->ctx, 'u_grade_lift',        $grade['lift']);
        vio_set_uniform($this->ctx, 'u_grade_gamma',       $grade['gamma']);
        vio_set_uniform($this->ctx, 'u_grade_gain',        $grade['gain']);
        vio_set_uniform($this->ctx, 'u_grade_saturation', $grade['saturation']);
        vio_set_uniform($this->ctx, 'u_vignette_intensity', $this->settings->vignetteIntensity);
        vio_set_uniform($this->ctx, 'u_volumetric_fog', $this->settings->volumetricFog ? 1 : 0);
        vio_set_uniform($this->ctx, 'u_ssr_intensity', $this->settings->ssr->intensity());
        // Hand-off gate: when the real ray-marched SSR pass owns reflections this
        // frame, suppress the forward wetness surrogate so they don't double-apply
        // (the pass composites the reflection into the HDR buffer afterwards).
        vio_set_uniform($this->ctx, 'u_ssr_enabled', $this->ssrEnabledThisFrame() ? 1 : 0);
        vio_set_uniform($this->ctx, 'u_viewport_size',
            [(float)$this->backbufferWidth, (float)$this->backbufferHeight]);

        vio_set_uniform($this->ctx, 'u_sky_color', [0.55, 0.70, 0.85]);
        vio_set_uniform($this->ctx, 'u_horizon_color', [0.85, 0.88, 0.92]);
        vio_set_uniform($this->ctx, 'u_snow_cover', $this->snowCover);
        vio_set_uniform($this->ctx, 'u_rain_wetness', $this->rainWetness);
        vio_set_uniform($this->ctx, 'u_vertex_anim', 0); // enabled per-material in applyMaterial
        vio_set_uniform($this->ctx, 'u_wave_amplitude', $state['waveAmplitude']);
        vio_set_uniform($this->ctx, 'u_wave_frequency', $state['waveFrequency']);
        vio_set_uniform($this->ctx, 'u_wave_phase', $state['wavePhase']);

        // Cloth + wind defaults. SetWind during command dispatch
        // overrides; cloth=0 keeps the per-vertex sway off until a
        // Material with cloth=true binds via applyMaterial.
        vio_set_uniform($this->ctx, 'u_cloth', 0);
        vio_set_uniform($this->ctx, 'u_wind_direction', [
            $this->windDirection[0], $this->windDirection[1], $this->windDirection[2],
        ]);
        vio_set_uniform($this->ctx, 'u_wind_intensity', $this->windIntensity);
        vio_set_uniform($this->ctx, 'u_mesh_local_aabb_min', [0.0, 0.0, 0.0]);
        vio_set_uniform($this->ctx, 'u_mesh_local_aabb_max', [0.0, 0.0, 0.0]);
    }

    private function extractCameraPosition(Mat4 $viewMatrix): Vec3
    {
        $inv = $viewMatrix->inverse();
        $m = $inv->toArray();
        return new Vec3($m[12], $m[13], $m[14]);
    }

    /** @return float[] 9-float flat mat3 */
    private function computeNormalMatrix(Mat4 $model): array
    {
        $inv = $model->inverse();
        $m = $inv->toArray();
        // Column-major storage: columns of transpose(inverse(model))
        // = rows of inverse(model)
        // OpenGL reads as column-major, HLSL row_major reads as rows.
        // Both need the same data order: column 0 of inv, column 1 of inv, column 2 of inv
        return [
            $m[0], $m[1], $m[2],
            $m[4], $m[5], $m[6],
            $m[8], $m[9], $m[10],
        ];
    }

    private static function lookAt(Vec3 $eye, Vec3 $target, Vec3 $up): Mat4
    {
        $f = $target->sub($eye)->normalize();
        $s = $f->cross($up)->normalize();
        $u = $s->cross($f);

        return new Mat4([
            $s->x, $u->x, -$f->x, 0.0,
            $s->y, $u->y, -$f->y, 0.0,
            $s->z, $u->z, -$f->z, 0.0,
            -$s->dot($eye), -$u->dot($eye), $f->dot($eye), 1.0,
        ]);
    }
}
