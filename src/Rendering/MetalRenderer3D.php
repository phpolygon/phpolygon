<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use Metal\Buffer;
use Metal\CommandQueue;
use Metal\DepthStencilDescriptor;
use Metal\DepthStencilState;
use Metal\Device;
use Metal\Layer;
use Metal\Library;
use Metal\RenderPassDescriptor;
use Metal\RenderPipelineDescriptor;
use Metal\RenderPipelineState;
use Metal\Texture;
use Metal\TextureDescriptor;
use Metal\VertexDescriptor;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetFog;
use PHPolygon\Rendering\Command\SetSky;
use PHPolygon\Rendering\Command\SetSkybox;
use PHPolygon\Rendering\Command\SetSkyColors;
use PHPolygon\Rendering\Command\SetWind;
use PHPolygon\Rendering\PostProcess\MetalFxaaPass;
use PHPolygon\Rendering\Quality\AntiAliasing;

/**
 * Native Apple Metal 3D renderer.
 * Translates a RenderCommandList into Metal draw calls via ext-metal (php-metal-gpu).
 *
 * Advantages over VulkanRenderer3D (via MoltenVK):
 *  - No Vulkan→Metal translation layer — direct Metal API calls
 *  - No SPIR-V→MSL compilation at pipeline creation time
 *  - Access to MetalFX upscaling, tile-based deferred rendering (future)
 *  - Simpler synchronisation model (Metal manages most frame sync internally)
 *
 * Requires:
 *  - ext-metal (php-metal-gpu) installed
 *  - GLFW window created with GLFW_CLIENT_API = GLFW_NO_API
 *  - macOS 12+ (Monterey) for full Metal 3 support
 *
 * Metal NDC vs OpenGL/Vulkan:
 *  - Y points UP (same as OpenGL — no Y-flip needed, unlike Vulkan)
 *  - Z range: 0..1 (same as Vulkan — Z correction matrix still required)
 */
class MetalRenderer3D implements Renderer3DInterface
{
    private int $width;
    private int $height;

    private Device $device;
    private Layer $layer;
    private CommandQueue $commandQueue;
    private RenderPipelineState $pipeline;
    private DepthStencilState $depthStencilState;

    /**
     * Pipeline-state cache keyed by raster sample count. Metal embeds the
     * sample count in the PSO so MSAA needs a separate pipeline. The PSO
     * for samples == 1 is also stored under key 1 (and aliased to
     * `$this->pipeline` so the legacy single-sample path keeps working).
     *
     * @var array<int, RenderPipelineState>
     */
    private array $pipelineBySampleCount = [];

    /** Pipeline cache for the sky pass, keyed by raster sample count. */
    /** @var array<int, RenderPipelineState> */
    private array $skyPipelineBySampleCount = [];

    /** Phase 1.5 off-screen target (color/depth/resolve textures). */
    private ?MetalOffscreenTarget $offscreenTarget = null;

    /** Lazy FXAA + passthrough-blit pass. */
    private ?MetalFxaaPass $presentPass = null;

    /**
     * Pixel formats reused when allocating the offscreen target so it
     * matches the layer's drawable format (and therefore the present
     * pipeline can sample it without conversion).
     */
    private int $offscreenColorPixelFormat;
    private int $offscreenDepthPixelFormat;

    private const FRAME_UBO_SIZE = 128;  // mat4 view + mat4 projection

    /** Per-frame view/projection buffer (uploaded once per render(), shared across draws). */
    private Buffer $frameUbo;

    /** MSL shader source — compiled at runtime via Device::createLibraryWithSource. */
    private const SHADER_PATH = __DIR__ . '/../../resources/shaders/source/mesh3d.metal';
    private const SKY_SHADER_PATH = __DIR__ . '/../../resources/shaders/source/sky.metal';

    /** Atmospheric sky pipeline (depth test off, no vertex buffer — fullscreen triangle). */
    private ?RenderPipelineState $skyPipeline = null;
    /** Sky pipeline variant rendering into the RGBA16Float cubemap target. */
    private ?RenderPipelineState $skyCubemapPipeline = null;
    private ?DepthStencilState $skyDepthState = null;
    private ?SetSky $pendingSky = null;
    /** Render-target cubemap for IBL. Lazily allocated on first SetSky. */
    private ?MetalCubemapTarget $cubemapTarget = null;
    /** True once the cubemap has been rendered at least once this session. */
    private bool $cubemapReady = false;

    /** Material/proc_mode prefix → proc_mode int cache (mirrors VioRenderer3D::resolveProcMode). */
    /** @var array<string, int> */
    private static array $procModeCache = [];

    /** @var float[] */
    private array $viewMatrix = [];
    /** @var float[] */
    private array $projMatrix = [];
    /** @var float[] [r, g, b, intensity] */
    private array $ambient = [1.0, 1.0, 1.0, 0.1];
    /** @var float[] [dx, dy, dz, intensity, r, g, b] */
    private array $dirLight = [0.0, -1.0, 0.0, 0.0, 1.0, 1.0, 1.0];
    /** @var float[] [r, g, b] */
    private array $albedo = [0.8, 0.8, 0.8];
    /** @var float[] [r, g, b] */
    private array $emission = [0.0, 0.0, 0.0];
    private float $roughness = 0.5;
    private float $metallic  = 0.0;
    private float $alpha     = 1.0;
    private int   $procMode  = 0;
    private float $moonPhase = 0.0;
    /** Carpaint: 0 = no clearcoat, 1 = full clearcoat lobe layered on top of base specular. */
    private float $clearcoat = 0.0;
    /** Independent clearcoat lobe roughness (defaults to a near-mirror finish). */
    private float $clearcoatRoughness = 0.05;
    /** Carpaint: 0 = none, 1 = dense metallic flake speckling. */
    private float $flakes = 0.0;
    /** Multiplier for procedural normal perturbation (1 = engine default). */
    private float $normalIntensity = 1.0;
    /**
     * Carpaint / IBL toggle. When 1, the mesh3d shader samples the
     * environment cubemap (Phase 4) for reflection. When 0 it falls back
     * to the sky/horizon-tinted pseudo-IBL (Phase 1).
     */
    private int $useEnvironmentMap = 1;
    /**
     * Procedural normal-map pattern code (see {@see NormalPattern}).
     * 0 = none. The Metal shader dispatches on this value the same way the
     * GLSL paths do.
     */
    private int $normalPattern = 0;
    /** UV tiling for the procedural normal-map pattern. 1 = one tile per UV unit. */
    private float $normalScale = 1.0;
    /** Procedural surface-wear pattern code (0 = none, see SurfacePattern). */
    private int $surfacePattern = 0;
    /** UV tiling for the surface-wear pattern. */
    private float $surfaceScale = 1.0;
    /** 0 = pattern disabled, 1 = full strength, > 1 = exaggerated. */
    private float $surfaceIntensity = 1.0;
    /** 0 = dry, 1 = soaked. SSR-surrogate amplifies IBL on up-facing surfaces. */
    private float $wetness = 0.0;

    // Cloth state, mirror of OpenGL/Vio backends.
    private bool  $cloth = false;
    private float $clothStrength = 0.05;
    private float $clothFrequency = 1.0;
    private float $clothPhase = 0.0;
    private bool  $clothAnchorTop = true;

    /** @var float[] {0,0,1} default = wind heading +Z */
    private array $windDirection = [0.0, 0.0, 1.0];
    private float $windIntensity = 0.5;

    /** @var float[] mesh-local AABB min, rebound per draw via meshAabb cache */
    private array $meshAabbMin = [0.0, 0.0, 0.0];
    /** @var float[] mesh-local AABB max, rebound per draw via meshAabb cache */
    private array $meshAabbMax = [0.0, 0.0, 0.0];

    /**
     * @var array<string, array{min: array{0:float,1:float,2:float}, max: array{0:float,1:float,2:float}}>
     */
    private array $meshAabbCache = [];
    /** @var float[] [r, g, b, near, far] */
    private array $fog = [0.5, 0.5, 0.5, 50.0, 200.0];
    /** @var float[] [x, y, z] */
    private array $cameraPos = [0.0, 0.0, 0.0];
    /** @var array<int, array{pos: float[], color: float[], intensity: float, radius: float}> */
    private array $pointLights = [];

    private float $clearR = 0.0;
    private float $clearG = 0.0;
    private float $clearB = 0.0;

    /** @var float[] [r, g, b] — sampled from horizon for water reflections */
    private array $skyColor     = [0.55, 0.70, 0.85];
    /** @var float[] [r, g, b] */
    private array $horizonColor = [0.85, 0.88, 0.92];
    /** @var float[] [r, g, b] — global terrain/vegetation tint, default no-op */
    private array $seasonTint   = [1.0, 1.0, 1.0];

    private readonly float $bootTime;
    private float $globalTime = 0.0;

    /** @var array<string, array{vb: Buffer, ib: Buffer, count: int}> */
    private array $meshCache = [];

    /**
     * Live graphics settings. Phase 1 honours fog toggle and view-distance
     * clamp during SetFog handling. Shadow tier and render scale on Metal
     * are tracked here for the AdaptiveQualityController to read but do
     * not yet drive an off-screen FBO (Phase 1.5).
     */
    private GraphicsSettings $settings;

    public function __construct(
        int $width,
        int $height,
        int $nativeWindowHandle,
        ?GraphicsSettings $settings = null,
    ) {
        $this->width    = $width;
        $this->height   = $height;
        $this->bootTime = microtime(true);
        $this->settings = $settings ?? new GraphicsSettings();
        $this->offscreenColorPixelFormat = \Metal\PixelFormatBGRA8Unorm;
        $this->offscreenDepthPixelFormat = \Metal\PixelFormatDepth32Float;
        $this->initMetal($nativeWindowHandle);
    }

    public function applySettings(GraphicsSettings $settings): void
    {
        $this->settings = $settings;

        // Phase 1.5 (Metal): off-screen render target driven by render-scale,
        // MSAA via Texture2DMultisample + setRasterSampleCount, FXAA via a
        // present pass that samples the resolved colour image. The actual
        // resize happens in render() before the encoder is built so we use
        // the freshest drawable dimensions.
    }

    public function getSettings(): GraphicsSettings
    {
        return $this->settings;
    }

    public function beginFrame(): void
    {
        $this->pointLights = [];
    }

    public function endFrame(): void
    {
        // Metal presentation is handled inside render() via commandBuffer->presentDrawable().
        // No separate endFrame work needed (unlike Vulkan's queue->present()).
    }

    public function clear(Color $color): void
    {
        $this->clearR = $color->r;
        $this->clearG = $color->g;
        $this->clearB = $color->b;
    }

    public function setViewport(int $x, int $y, int $width, int $height): void
    {
        $this->width  = $width;
        $this->height = $height;
        $this->layer->setDrawableSize($width, $height);
    }

    public function getWidth(): int  { return $this->width; }
    public function getHeight(): int { return $this->height; }

    public function render(RenderCommandList $commandList): void
    {
        $this->globalTime = microtime(true) - $this->bootTime;

        $identity         = Mat4::identity()->toArray();
        $this->viewMatrix = $identity;
        $this->projMatrix = $identity;
        $this->ambient    = [1.0, 1.0, 1.0, 0.1];
        $this->dirLight   = [0.0, -1.0, 0.0, 0.0, 1.0, 1.0, 1.0];
        $this->albedo     = [0.8, 0.8, 0.8];
        $this->emission   = [0.0, 0.0, 0.0];
        $this->roughness  = 0.5;
        $this->metallic   = 0.0;
        $this->alpha      = 1.0;
        $this->procMode   = 0;
        $this->moonPhase  = 0.0;
        $this->clearcoat  = 0.0;
        $this->clearcoatRoughness = 0.05;
        $this->flakes     = 0.0;
        $this->normalIntensity   = 1.0;
        $this->useEnvironmentMap = 1;
        $this->normalPattern     = 0;
        $this->normalScale       = 1.0;
        $this->surfacePattern    = 0;
        $this->surfaceScale      = 1.0;
        $this->surfaceIntensity  = 1.0;
        $this->wetness           = 0.0;
        $this->fog        = [0.5, 0.5, 0.5, 50.0, 200.0];
        $this->cameraPos  = [0.0, 0.0, 0.0];

        // Cloth + wind reset every frame; SetWind / Material::cloth re-arm them.
        $this->cloth          = false;
        $this->clothStrength  = 0.05;
        $this->clothFrequency = 1.0;
        $this->clothPhase     = 0.0;
        $this->clothAnchorTop = true;
        $this->windDirection  = [0.0, 0.0, 1.0];
        $this->windIntensity  = 0.5;

        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof SetCamera) {
                $this->viewMatrix = $command->viewMatrix->toArray();
                $this->projMatrix = $command->projectionMatrix->toArray();
                $camPos           = $command->viewMatrix->inverse()->getTranslation();
                $this->cameraPos  = [$camPos->x, $camPos->y, $camPos->z];
            } elseif ($command instanceof SetAmbientLight) {
                $this->ambient = [$command->color->r, $command->color->g, $command->color->b, $command->intensity];
            } elseif ($command instanceof SetDirectionalLight) {
                $this->dirLight = [
                    $command->direction->x, $command->direction->y, $command->direction->z,
                    $command->intensity,
                    $command->color->r, $command->color->g, $command->color->b,
                ];
            } elseif ($command instanceof AddPointLight && count($this->pointLights) < 8) {
                $this->pointLights[] = [
                    'pos'       => [$command->position->x, $command->position->y, $command->position->z],
                    'color'     => [$command->color->r, $command->color->g, $command->color->b],
                    'intensity' => $command->intensity,
                    'radius'    => $command->radius,
                ];
            } elseif ($command instanceof SetFog) {
                if ($this->settings->fog) {
                    $clampedFar = min($command->far, $this->settings->viewDistance);
                    $clampedNear = min($command->near, max(0.0, $clampedFar - 1.0));
                    $this->fog = [$command->color->r, $command->color->g, $command->color->b, $clampedNear, $clampedFar];
                } else {
                    $this->fog = [$command->color->r, $command->color->g, $command->color->b, 99998.0, 99999.0];
                }
            } elseif ($command instanceof SetSkyColors) {
                $this->clearR = $command->skyColor->r;
                $this->clearG = $command->skyColor->g;
                $this->clearB = $command->skyColor->b;
                $this->skyColor = [$command->skyColor->r, $command->skyColor->g, $command->skyColor->b];
                $this->horizonColor = [
                    $command->horizonColor->r,
                    $command->horizonColor->g,
                    $command->horizonColor->b,
                ];
            } elseif ($command instanceof SetSky) {
                $this->pendingSky = $command;
            } elseif ($command instanceof SetSkybox) {
                // TODO Phase 7: Skybox pipeline
            } elseif ($command instanceof SetWind) {
                $this->windDirection = [
                    $command->direction->x, $command->direction->y, $command->direction->z,
                ];
                $this->windIntensity = $command->intensity;
            }
        }

        $this->uploadFrameUbo();

        // ── Acquire drawable ───────────────────────────────────────────────
        $drawable     = $this->layer->nextDrawable();
        $drawableTex  = $drawable->getTexture();

        // ── Phase 1.5: decide whether to render off-screen ─────────────────
        $offscreen = $this->ensureOffscreenIfActive();
        $useOffscreen = $offscreen !== null;
        $sceneSamples = $useOffscreen ? $offscreen->samples() : 1;
        $sceneW       = $useOffscreen ? $offscreen->width()   : $this->width;
        $sceneH       = $useOffscreen ? $offscreen->height()  : $this->height;
        $scenePipeline = $this->ensurePipelineForSampleCount($sceneSamples);

        // ── Build the scene render pass ────────────────────────────────────
        if ($useOffscreen) {
            $colorAttachment = $offscreen->colorTexture();
            $depthAttachment = $offscreen->depthTexture();
            $resolveTex      = $offscreen->resolveTexture();
        } else {
            $colorAttachment = $drawableTex;
            $resolveTex      = null;

            // Depth texture - recreated each frame for the legacy direct path
            // (no caching; offscreen path manages its own depth).
            $depthDesc = new TextureDescriptor();
            $depthDesc->setPixelFormat($this->offscreenDepthPixelFormat);
            $depthDesc->setWidth($this->width);
            $depthDesc->setHeight($this->height);
            $depthDesc->setUsage(\Metal\TextureUsageRenderTarget);
            $depthDesc->setStorageMode(\Metal\StorageModePrivate);
            $depthAttachment = $this->device->createTexture($depthDesc);
        }

        if ($colorAttachment === null || $depthAttachment === null) {
            // Allocation failed - nothing to render this frame; skip cleanly.
            return;
        }

        $renderPass = new RenderPassDescriptor();
        $renderPass->setColorAttachmentTexture(0, $colorAttachment);
        $renderPass->setColorAttachmentLoadAction(0, \Metal\LoadActionClear);
        $renderPass->setColorAttachmentClearColor(0, $this->clearR, $this->clearG, $this->clearB, 1.0);
        if ($resolveTex !== null) {
            // MSAA: resolve into single-sample texture at end of pass; the
            // multisample colour image itself is throwaway.
            $renderPass->setColorAttachmentResolveTexture(0, $resolveTex);
            $renderPass->setColorAttachmentStoreAction(0, \Metal\StoreActionMultisampleResolve);
        } else {
            $renderPass->setColorAttachmentStoreAction(0, \Metal\StoreActionStore);
        }
        $renderPass->setDepthAttachmentTexture($depthAttachment);
        $renderPass->setDepthAttachmentLoadAction(\Metal\LoadActionClear);
        $renderPass->setDepthAttachmentStoreAction(\Metal\StoreActionStore);
        $renderPass->setDepthAttachmentClearDepth(1.0);

        // ── Encode draw calls ──────────────────────────────────────────────
        $commandBuffer = $this->commandQueue->createCommandBuffer();

        // ── IBL cubemap update (must happen BEFORE main pass binds it) ──
        // Six render passes + a blit (generateMipmaps), all on the same
        // command buffer as the main pass so Metal serialises them via
        // texture-dependency tracking. Skipped when sky hasn't changed
        // since last frame (hash check inside updateEnvironmentCubemap).
        if ($this->pendingSky !== null) {
            $this->updateEnvironmentCubemap($commandBuffer, $this->pendingSky);
        }

        $encoder       = $commandBuffer->createRenderCommandEncoder($renderPass);

        $encoder->setViewport(0.0, 0.0, (float)$sceneW, (float)$sceneH, 0.0, 1.0);
        $encoder->setScissorRect(0, 0, $sceneW, $sceneH);

        // ── Atmospheric sky pass (before opaque, depth test off, fullscreen) ──
        if ($this->pendingSky !== null) {
            $skyPipeline = $sceneSamples === 1
                ? $this->skyPipeline
                : $this->ensureSkyPipelineForSampleCount($sceneSamples);
            if ($skyPipeline !== null) {
                $this->encodeSkyPass($encoder, $this->pendingSky, $skyPipeline);
            } elseif (getenv('PHPOLYGON_DEBUG_METAL') === '1') {
                fprintf(STDERR, "[MetalRenderer3D] Sky pipeline NULL — sky.metal failed to compile or build for sampleCount={$sceneSamples}\n");
            }
        } elseif (getenv('PHPOLYGON_DEBUG_METAL') === '1') {
            fprintf(STDERR, "[MetalRenderer3D] pendingSky NULL — SetSky not received this frame\n");
        }
        $this->pendingSky = null;

        $encoder->setRenderPipelineState($scenePipeline);
        $encoder->setDepthStencilState($this->depthStencilState);
        // Match OpenGLRenderer3D / VioRenderer3D: culling disabled. Many
        // procedural meshes (TerrainMesh, PalmFrondMesh, RoofBuilder gables)
        // mix winding orders or have geometric normals pointing opposite to
        // their vertex normals; back-face culling makes them disappear.
        $encoder->setCullMode(\Metal\CullModeNone);
        $encoder->setFrontFacingWinding(\Metal\WindingCounterClockwise);

        // FrameUBO is constant for the whole frame — bind once.
        $encoder->setVertexBuffer($this->frameUbo, 0, 1); // slot 1: FrameUBO

        // IBL cubemap binding (fragment texture slot 0). Bound for every
        // mesh in the frame; the shader gates sampling on
        // light.has_environment_map && light.use_environment_map so
        // non-reflective materials skip the texture fetch entirely.
        $cubemap = $this->cubemapTarget?->cubemap();
        $sampler = $this->cubemapTarget?->sampler();
        if ($cubemap !== null && $sampler !== null && $this->cubemapReady) {
            $encoder->setFragmentTexture($cubemap, 0);
            $encoder->setFragmentSamplerState($sampler, 0);
        }

        // LightingUBO changes per material, so it must be uploaded per draw.
        // setFragmentBytes copies the data into the command stream (≤4 KB),
        // giving each draw its own snapshot — using a single shared MTLBuffer
        // and rewriting it would race with in-flight draws and cause every
        // mesh to render with the LAST draw's material colour (the bug we
        // had before this rewrite).
        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof DrawMesh) {
                $this->resolveMaterial($command->materialId);
                $this->bindMeshAabb($command->meshId);
                $uboBytes = $this->buildLightingUboBytes();
                $encoder->setFragmentBytes($uboBytes, 2);
                // Cloth animation runs in the vertex shader and reads
                // from the same struct, so bind it to the vertex stage too.
                $encoder->setVertexBytes($uboBytes, 2);
                $this->drawMeshCommand($encoder, $command->meshId, $command->modelMatrix);
            } elseif ($command instanceof DrawMeshInstanced) {
                $this->resolveMaterial($command->materialId);
                $this->bindMeshAabb($command->meshId);
                $uboBytes = $this->buildLightingUboBytes();
                $encoder->setFragmentBytes($uboBytes, 2);
                // Cloth animation runs in the vertex shader and reads
                // from the same struct, so bind it to the vertex stage too.
                $encoder->setVertexBytes($uboBytes, 2);
                if ($command->flatMatrices !== []) {
                    // Flat path: stream 16 floats per instance straight to
                    // setVertexBytes - no intermediate Mat4 allocation.
                    $count = $command->instanceCount >= 0 ? $command->instanceCount : count($command->matrices);
                    $flat = $command->flatMatrices;
                    for ($i = 0; $i < $count; $i++) {
                        $base = $i * 16;
                        $bytes = pack(
                            'f16',
                            $flat[$base + 0],  $flat[$base + 1],  $flat[$base + 2],  $flat[$base + 3],
                            $flat[$base + 4],  $flat[$base + 5],  $flat[$base + 6],  $flat[$base + 7],
                            $flat[$base + 8],  $flat[$base + 9],  $flat[$base + 10], $flat[$base + 11],
                            $flat[$base + 12], $flat[$base + 13], $flat[$base + 14], $flat[$base + 15],
                        );
                        $this->drawMeshCommandRaw($encoder, $command->meshId, $bytes);
                    }
                } else {
                    foreach ($command->matrices as $matrix) {
                        $this->drawMeshCommand($encoder, $command->meshId, $matrix);
                    }
                }
            }
        }

        $encoder->endEncoding();

        // ── Phase 1.5 present pass: blit / FXAA into the drawable ──────────
        if ($useOffscreen) {
            $presentInput = $offscreen->presentTexture();
            if ($presentInput !== null) {
                $this->encodePresentPass($commandBuffer, $drawableTex, $presentInput);
            }
        }

        $commandBuffer->presentDrawable($drawable);
        $commandBuffer->commit();

        // Wait for the GPU to finish reading the shared buffers before the CPU
        // writes new data in the next frame (prevents StorageModeShared race condition).
        $commandBuffer->waitUntilCompleted();
    }

    // ── Private helpers ────────────────────────────────────────────────────

    /**
     * Update $meshAabbMin/Max with the cached AABB for $meshId. Drives
     * the vertex-stage cloth-sway anchor weighting; computed once on
     * first encounter, reused thereafter.
     */
    private function bindMeshAabb(string $meshId): void
    {
        $aabb = $this->meshAabb($meshId);
        $this->meshAabbMin = $aabb['min'];
        $this->meshAabbMax = $aabb['max'];
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

    private function resolveMaterial(string $materialId): void
    {
        $material = MaterialRegistry::get($materialId);
        if ($material !== null) {
            $this->albedo    = [$material->albedo->r, $material->albedo->g, $material->albedo->b];
            $this->emission  = [$material->emission->r, $material->emission->g, $material->emission->b];
            $this->roughness = $material->roughness;
            $this->metallic  = $material->metallic;
            $this->alpha     = $material->alpha;
            $this->clearcoat          = $material->clearcoat;
            $this->clearcoatRoughness = $material->clearcoatRoughness;
            $this->flakes             = $material->flakes;
            $this->normalIntensity    = $material->normalIntensity;
            $this->useEnvironmentMap  = $material->useEnvironmentMap ? 1 : 0;
            $this->normalPattern      = NormalPattern::codeFor($material->normalPattern);
            $this->normalScale        = $material->normalScale;
            $this->surfacePattern     = SurfacePattern::codeFor($material->surfacePattern);
            $this->surfaceScale       = $material->surfaceScale;
            $this->surfaceIntensity   = $material->surfaceIntensity;
            $this->wetness            = $material->wetness;
            $this->cloth          = $material->cloth;
            $this->clothStrength  = $material->clothStrength;
            $this->clothFrequency = $material->clothFrequency;
            $this->clothPhase     = $material->clothPhase;
            $this->clothAnchorTop = $material->clothAnchorTop;
        } else {
            $this->albedo    = [0.8, 0.8, 0.8];
            $this->emission  = [0.0, 0.0, 0.0];
            $this->roughness = 0.5;
            $this->metallic  = 0.0;
            $this->alpha     = 1.0;
            $this->clearcoat          = 0.0;
            $this->clearcoatRoughness = 0.05;
            $this->flakes             = 0.0;
            $this->normalIntensity    = 1.0;
            $this->useEnvironmentMap  = 1;
            $this->normalPattern      = 0;
            $this->normalScale        = 1.0;
            $this->surfacePattern     = 0;
            $this->surfaceScale       = 1.0;
            $this->surfaceIntensity   = 1.0;
            $this->wetness            = 0.0;
            $this->cloth          = false;
            $this->clothStrength  = 0.05;
            $this->clothFrequency = 1.0;
            $this->clothPhase     = 0.0;
            $this->clothAnchorTop = true;
        }

        $this->procMode = self::$procModeCache[$materialId] ?? $this->resolveProcMode($materialId);

        // Moon material encodes its current phase in the roughness slot
        // (mirrors VioRenderer3D — the dedicated moon shader reads it as
        // u_moon_phase). Reset to 0 for everything else so we never bleed
        // a leftover phase value into other procedural modes.
        $this->moonPhase = $this->procMode === 9 && $material !== null
            ? $material->roughness
            : 0.0;
    }

    private function resolveProcMode(string $materialId): int
    {
        $prefixRaw = strtok($materialId, '0123456789');
        $prefix    = $prefixRaw === false ? $materialId : $prefixRaw;

        $mode = match (true) {
            str_starts_with($prefix, 'sand_terrain')   => 1,
            str_starts_with($prefix, 'water_')         => 2,
            str_starts_with($prefix, 'rock')           => 3,
            str_starts_with($prefix, 'palm_trunk')     => 4,
            str_starts_with($prefix, 'palm_branch'),
            str_starts_with($prefix, 'palm_leaves'),
            str_starts_with($prefix, 'palm_leaf'),
            str_starts_with($prefix, 'palm_canopy'),
            str_starts_with($prefix, 'palm_frond')     => 5,
            str_starts_with($prefix, 'cloud_')         => 6,
            str_starts_with($prefix, 'hut_wood'),
            str_starts_with($prefix, 'hut_door'),
            str_starts_with($prefix, 'hut_table'),
            str_starts_with($prefix, 'hut_chair'),
            str_starts_with($prefix, 'hut_floor'),
            str_starts_with($prefix, 'hut_window')     => 7,
            str_starts_with($prefix, 'hut_thatch')     => 8,
            str_starts_with($prefix, 'moon_disc')      => 9,
            str_starts_with($prefix, 'car_paint')      => 10,
            default                                    => 0,
        };

        self::$procModeCache[$materialId] = $mode;
        return $mode;
    }

    private function drawMeshCommand(\Metal\RenderCommandEncoder $encoder, string $meshId, Mat4 $modelMatrix): void
    {
        $this->drawMeshCommandRaw($encoder, $meshId, pack('f16', ...$modelMatrix->toArray()));
    }

    /**
     * Same as {@see drawMeshCommand()} but accepts a pre-packed 64-byte
     * model-matrix blob (column-major float[16] packed as 'f16'). Used
     * by the flat instance path to skip the Mat4 allocation per
     * particle.
     */
    private function drawMeshCommandRaw(\Metal\RenderCommandEncoder $encoder, string $meshId, string $modelBytes): void
    {
        $meshData = MeshRegistry::get($meshId);
        if ($meshData === null) {
            return;
        }

        if (!isset($this->meshCache[$meshId])) {
            $this->uploadMesh($meshId, $meshData);
        }

        // Push model matrix inline via setVertexBytes (slot 0).
        // Metal copies up to 4 KB directly into the command stream — no buffer allocation.
        // Equivalent to Vulkan pushConstants() but simpler: no pipeline layout declaration needed.
        $encoder->setVertexBytes($modelBytes, 0); // slot 0: model matrix (length implicit from string)

        $encoder->setVertexBuffer($this->meshCache[$meshId]['vb'], 0, 3); // slot 3: vertex data
        $encoder->drawIndexedPrimitives(
            \Metal\PrimitiveTypeTriangle,
            $this->meshCache[$meshId]['count'],
            \Metal\IndexTypeUInt32,
            $this->meshCache[$meshId]['ib'],
            0,
        );
    }

    private function uploadMesh(string $meshId, MeshData $meshData): void
    {
        $vertexCount = $meshData->vertexCount();
        $vertexData  = '';
        for ($i = 0; $i < $vertexCount; $i++) {
            $vertexData .= pack(
                'f8',
                $meshData->vertices[$i * 3],     $meshData->vertices[$i * 3 + 1], $meshData->vertices[$i * 3 + 2],
                $meshData->normals[$i * 3],      $meshData->normals[$i * 3 + 1],  $meshData->normals[$i * 3 + 2],
                $meshData->uvs[$i * 2],          $meshData->uvs[$i * 2 + 1],
            );
        }

        $indexData = '';
        foreach ($meshData->indices as $idx) {
            $indexData .= pack('V', $idx);
        }

        // StorageModeShared: CPU writes once at upload, GPU reads every frame.
        $vb = $this->device->createBuffer(strlen($vertexData), \Metal\StorageModeShared);
        $vb->writeRawContents($vertexData, 0);
        $ib = $this->device->createBuffer(strlen($indexData), \Metal\StorageModeShared);
        $ib->writeRawContents($indexData, 0);

        $this->meshCache[$meshId] = ['vb' => $vb, 'ib' => $ib, 'count' => count($meshData->indices)];
    }

    private function uploadFrameUbo(): void
    {
        // Metal NDC: Y points UP (same as OpenGL) — no Y-flip needed.
        // Z range is 0..1 (same as Vulkan) — Z correction still required.
        $metalClip = new Mat4([
            1.0, 0.0, 0.0, 0.0,
            0.0, 1.0, 0.0, 0.0,  // Y row stays positive — Metal Y-up
            0.0, 0.0, 0.5, 0.0,
            0.0, 0.0, 0.5, 1.0,
        ]);
        $correctedProj = $metalClip->multiply(new Mat4($this->projMatrix));
        $data = pack('f16', ...$this->viewMatrix)
              . pack('f16', ...$correctedProj->toArray());
        $this->frameUbo->writeRawContents($data, 0);
    }

    /**
     * Pack the LightingUBO into a binary string matching the MSL `LightingUBO`
     * struct layout in mesh3d.metal. Caller hands this directly to
     * `RenderCommandEncoder::setFragmentBytes` — Metal copies the contents
     * into the command stream so each draw owns its own snapshot, even
     * though we mutate $this->* between draws.
     *
     * Field order, byte offsets, and packing must match the MSL struct
     * exactly. `packed_float3` is 12 bytes; the trailing scalar fills the
     * remaining 4 bytes of each 16-byte slot.
     */
    private function buildLightingUboBytes(): string
    {
        $data  = pack('f4', $this->ambient[0],   $this->ambient[1],   $this->ambient[2],   $this->ambient[3]);
        $data .= pack('f4', $this->dirLight[0],  $this->dirLight[1],  $this->dirLight[2],  $this->dirLight[3]);
        $data .= pack('f4', $this->dirLight[4],  $this->dirLight[5],  $this->dirLight[6],  0.0);
        $data .= pack('f4', $this->albedo[0],    $this->albedo[1],    $this->albedo[2],    $this->roughness);
        $data .= pack('f4', $this->emission[0],  $this->emission[1],  $this->emission[2],  $this->metallic);
        $data .= pack('f4', $this->fog[0],       $this->fog[1],       $this->fog[2],       $this->fog[3]);
        $data .= pack('f4', $this->cameraPos[0], $this->cameraPos[1], $this->cameraPos[2], $this->fog[4]);
        $plCount = count($this->pointLights);
        $data .= pack('l1f3', $plCount, 0.0, 0.0, 0.0);

        // Procedural-mode environment block (added 2026-04-28).
        $data .= pack('f3f1', $this->skyColor[0],     $this->skyColor[1],     $this->skyColor[2],     $this->globalTime);
        $data .= pack('f3f1', $this->horizonColor[0], $this->horizonColor[1], $this->horizonColor[2], $this->moonPhase);
        $data .= pack('f3l1', $this->seasonTint[0],   $this->seasonTint[1],   $this->seasonTint[2],   $this->procMode);
        // Carpaint / IBL block (16 bytes alpha-slot, 16 bytes carpaint-slot,
        // 16 bytes ibl-slot). Order MUST match the MSL LightingUBO layout.
        $data .= pack('f4',   $this->alpha, 0.0, 0.0, 0.0);
        $data .= pack('f4',   $this->clearcoat, $this->clearcoatRoughness, $this->flakes, $this->normalIntensity);
        // First int: per-material toggle. Second int: per-frame flag set
        // by the renderer when an IBL cubemap is bound to fragment texture
        // slot 0. The shader samples only when both are 1.
        $hasEnv = $this->cubemapReady ? 1 : 0;
        $mipMax = (float)(($this->cubemapTarget?->mipLevels() ?? 1) - 1);
        $data .= pack('l2f2', $this->useEnvironmentMap, $hasEnv, $mipMax, 0.0);
        // Procedural normal-map + AO block (16 bytes: int pattern, float scale,
        // float ao_strength, 1 pad float).
        $aoStrength = $this->settings->ambientOcclusion->strength();
        $data .= pack('l1f3', $this->normalPattern, $this->normalScale, $aoStrength, 0.0);

        // Procedural surface-wear block (16 bytes: int pattern, float scale,
        // float intensity, float wetness).
        $data .= pack('l1f3', $this->surfacePattern, $this->surfaceScale, $this->surfaceIntensity, $this->wetness);

        // Color grading + vignette + viewport size (4 x 16 bytes).
        $grade = $this->settings->colorGrading->params();
        $vw = $this->offscreenTarget?->width()  ?? 0;
        $vh = $this->offscreenTarget?->height() ?? 0;
        if ($vw <= 0) { $vw = 1; }
        if ($vh <= 0) { $vh = 1; }
        $data .= pack('f4', $grade['lift'][0],  $grade['lift'][1],  $grade['lift'][2],  $grade['saturation']);
        $data .= pack('f4', $grade['gamma'][0], $grade['gamma'][1], $grade['gamma'][2], $this->settings->vignetteIntensity);
        // Last float of the gain slot doubles as ssr_intensity (mirror of
        // OpenGL/Vio's u_ssr_intensity, see mesh3d.metal LightingUBO).
        $data .= pack('f4', $grade['gain'][0],  $grade['gain'][1],  $grade['gain'][2],  $this->settings->ssr->intensity());
        $volFog = $this->settings->volumetricFog ? 1 : 0;
        // Layout: float vw, float vh, int volumetric_fog, float pad.
        $data .= pack('f2l1f1', (float)$vw, (float)$vh, $volFog, 0.0);

        // Cloth + wind block (mirrors mesh3d.metal LightingUBO struct).
        $cloth     = $this->cloth ? 1 : 0;
        $anchorTop = $this->clothAnchorTop ? 1 : 0;
        $data .= pack('l1f3', $cloth, $this->clothStrength, $this->clothFrequency, $this->clothPhase);
        $data .= pack('l1f3', $anchorTop, 0.0, 0.0, 0.0);
        $data .= pack('f3f1', $this->windDirection[0], $this->windDirection[1], $this->windDirection[2], $this->windIntensity);
        $data .= pack('f3f1', $this->meshAabbMin[0],   $this->meshAabbMin[1],   $this->meshAabbMin[2],   0.0);
        $data .= pack('f3f1', $this->meshAabbMax[0],   $this->meshAabbMax[1],   $this->meshAabbMax[2],   0.0);

        for ($i = 0; $i < 8; $i++) {
            if ($i < $plCount) {
                $pl    = $this->pointLights[$i];
                $data .= pack('f4', $pl['pos'][0],   $pl['pos'][1],   $pl['pos'][2],   $pl['intensity']);
                $data .= pack('f4', $pl['color'][0], $pl['color'][1], $pl['color'][2], $pl['radius']);
            } else {
                $data .= pack('f8', 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0);
            }
        }
        return $data;
    }

    /**
     * Decide whether the Phase 1.5 offscreen path should run this frame.
     * Returns the offscreen target when active (lazily allocated/resized),
     * or null when the fast path applies (renderScale == 1.0 and AA off).
     */
    private function ensureOffscreenIfActive(): ?MetalOffscreenTarget
    {
        $needsOffscreen = $this->settings->renderScale !== 1.0
            || $this->settings->antiAliasing !== AntiAliasing::Off;
        if (!$needsOffscreen) {
            $this->offscreenTarget?->release();
            return null;
        }

        if ($this->offscreenTarget === null) {
            $this->offscreenTarget = new MetalOffscreenTarget(
                $this->device,
                $this->offscreenColorPixelFormat,
                $this->offscreenDepthPixelFormat,
            );
        }

        $w = max(1, (int)round($this->width  * $this->settings->renderScale));
        $h = max(1, (int)round($this->height * $this->settings->renderScale));
        $samples = max(1, $this->settings->antiAliasing->sampleCount());

        $this->offscreenTarget->resize($w, $h, $samples);

        if ($this->presentPass === null) {
            // The present pass writes into the drawable, which Metal layers
            // always create as $offscreenColorPixelFormat (BGRA8Unorm in our
            // setup). No depth attachment in the present pass.
            $this->presentPass = new MetalFxaaPass(
                $this->device,
                $this->offscreenColorPixelFormat,
            );
        }

        if (!$this->offscreenTarget->isAllocated()) {
            return null;
        }

        return $this->offscreenTarget;
    }

    /**
     * Run the FXAA / passthrough-blit pass into the drawable. Caller has
     * already ended the scene encoder; we open a new one targeting the
     * drawable, encode the present, and end it before the command buffer
     * commits and presents.
     */
    private function encodePresentPass(\Metal\CommandBuffer $commandBuffer, Texture $drawableTex, Texture $sceneTex): void
    {
        if ($this->presentPass === null) {
            return;
        }

        $presentPass = new RenderPassDescriptor();
        $presentPass->setColorAttachmentTexture(0, $drawableTex);
        $presentPass->setColorAttachmentLoadAction(0, \Metal\LoadActionDontCare);
        $presentPass->setColorAttachmentStoreAction(0, \Metal\StoreActionStore);
        // No depth attachment for the present pass - depth is meaningless
        // when blitting a fullscreen triangle and the FXAA pipeline uses
        // depthAlways/no-write.

        $encoder = $commandBuffer->createRenderCommandEncoder($presentPass);

        $bbW = $drawableTex->getWidth();
        $bbH = $drawableTex->getHeight();
        $encoder->setViewport(0.0, 0.0, (float)$bbW, (float)$bbH, 0.0, 1.0);
        $encoder->setScissorRect(0, 0, $bbW, $bbH);

        if ($this->settings->antiAliasing === AntiAliasing::Fxaa) {
            $this->presentPass->applyFxaa($encoder, $sceneTex);
        } else {
            $this->presentPass->applyBlit($encoder, $sceneTex);
        }

        $encoder->endEncoding();
    }

    private function initMetal(int $nativeWindowHandle): void
    {
        if ($nativeWindowHandle === 0) {
            throw new \RuntimeException(
                'MetalRenderer3D: native window handle is 0 — vio_native_window_handle() returned no NSWindow. '
                . 'Ensure the engine runs on macOS with vio + GLFW windowing.'
            );
        }

        $this->device       = \Metal\createSystemDefaultDevice();
        $this->commandQueue = $this->device->createCommandQueue();

        // Attach CAMetalLayer to the GLFW window's NSView. The handle is the
        // raw NSWindow pointer (uintptr_t cast to int), which Metal\Layer's
        // constructor bridges back to an Objective-C NSWindow before
        // installing the CAMetalLayer on its content view.
        $this->layer = new Layer($nativeWindowHandle, $this->device, \Metal\PixelFormatBGRA8Unorm);
        $this->layer->setDrawableSize($this->width, $this->height);

        $this->createPipeline();
        $this->createDepthStencilState();
        $this->createUBOs();
        $this->createSkyPipeline();
    }

    private function createPipeline(): void
    {
        // Compile MSL at runtime via createLibraryWithSource. This avoids the
        // dependency on full Xcode (the `metal` driver shipped with CLT only
        // is missing) and lets us iterate the shader without a build step.
        $mslSource = @file_get_contents(self::SHADER_PATH);
        if ($mslSource === false) {
            throw new \RuntimeException(
                'MetalRenderer3D: failed to read MSL source at ' . self::SHADER_PATH
            );
        }

        $this->meshLibrary = $this->device->createLibraryWithSource($mslSource);

        // Build the single-sample pipeline up front so the legacy direct-to-
        // drawable path keeps working without a per-frame allocation. MSAA
        // pipelines are built on demand by ensurePipelineForSampleCount().
        $this->pipeline = $this->buildScenePipeline(1);
        $this->pipelineBySampleCount[1] = $this->pipeline;
    }

    private ?Library $meshLibrary = null;

    private function buildScenePipeline(int $sampleCount): RenderPipelineState
    {
        if ($this->meshLibrary === null) {
            // Should be impossible - createPipeline() populates this first.
            $msl = (string)file_get_contents(self::SHADER_PATH);
            $this->meshLibrary = $this->device->createLibraryWithSource($msl);
        }

        $vertFn = $this->meshLibrary->getFunction('vertex_mesh3d');
        $fragFn = $this->meshLibrary->getFunction('fragment_mesh3d');

        // Vertex layout: position(float3) + normal(float3) + uv(float2) = 32 bytes
        $vertexDesc = new VertexDescriptor();
        $vertexDesc->setAttribute(0, \Metal\VertexFormatFloat3, 0,  3);
        $vertexDesc->setAttribute(1, \Metal\VertexFormatFloat3, 12, 3);
        $vertexDesc->setAttribute(2, \Metal\VertexFormatFloat2, 24, 3);
        $vertexDesc->setLayout(3, 32);

        $pipelineDesc = new RenderPipelineDescriptor();
        $pipelineDesc->setVertexFunction($vertFn);
        $pipelineDesc->setFragmentFunction($fragFn);
        $pipelineDesc->getColorAttachment(0)->setPixelFormat($this->offscreenColorPixelFormat);
        $pipelineDesc->setDepthAttachmentPixelFormat($this->offscreenDepthPixelFormat);
        $pipelineDesc->setVertexDescriptor($vertexDesc);
        $pipelineDesc->setRasterSampleCount(max(1, $sampleCount));

        return $this->device->createRenderPipelineState($pipelineDesc);
    }

    private function ensurePipelineForSampleCount(int $sampleCount): RenderPipelineState
    {
        $sampleCount = max(1, $sampleCount);
        if (!isset($this->pipelineBySampleCount[$sampleCount])) {
            $this->pipelineBySampleCount[$sampleCount] = $this->buildScenePipeline($sampleCount);
        }
        return $this->pipelineBySampleCount[$sampleCount];
    }

    private function ensureSkyPipelineForSampleCount(int $sampleCount): ?RenderPipelineState
    {
        $sampleCount = max(1, $sampleCount);
        if (isset($this->skyPipelineBySampleCount[$sampleCount])) {
            return $this->skyPipelineBySampleCount[$sampleCount];
        }

        $msl = @file_get_contents(self::SKY_SHADER_PATH);
        if ($msl === false) {
            return null;
        }

        try {
            $library = $this->device->createLibraryWithSource($msl);
            $vertFn  = $library->getFunction('vertex_sky');
            $fragFn  = $library->getFunction('fragment_sky');

            $desc = new RenderPipelineDescriptor();
            $desc->setVertexFunction($vertFn);
            $desc->setFragmentFunction($fragFn);
            $desc->getColorAttachment(0)->setPixelFormat($this->offscreenColorPixelFormat);
            $desc->setDepthAttachmentPixelFormat($this->offscreenDepthPixelFormat);
            $desc->setRasterSampleCount($sampleCount);

            $pso = $this->device->createRenderPipelineState($desc);
            $this->skyPipelineBySampleCount[$sampleCount] = $pso;
            return $pso;
        } catch (\Throwable) {
            return null;
        }
    }

    private function createDepthStencilState(): void
    {
        $desc = new DepthStencilDescriptor();
        $desc->setDepthCompareFunction(\Metal\CompareFunctionLess);
        $desc->setDepthWriteEnabled(true);
        $this->depthStencilState = $this->device->createDepthStencilState($desc);

        // Sky pass uses an "always pass, never write" depth state — atmospheric
        // sky is drawn first as a fullscreen pass, then opaque geometry overwrites
        // wherever it draws.
        $skyDesc = new DepthStencilDescriptor();
        $skyDesc->setDepthCompareFunction(\Metal\CompareFunctionAlways);
        $skyDesc->setDepthWriteEnabled(false);
        $this->skyDepthState = $this->device->createDepthStencilState($skyDesc);
    }

    private function createUBOs(): void
    {
        // FrameUBO is rebound once per render() and read by every draw, so it
        // stays a managed Buffer. LightingUBO is per-draw and goes through
        // setFragmentBytes (see buildLightingUboBytes), so no Buffer is
        // allocated for it.
        $this->frameUbo = $this->device->createBuffer(self::FRAME_UBO_SIZE, \Metal\StorageModeShared);
    }

    private function createSkyPipeline(): void
    {
        // Build the single-sample variant up front; MSAA variants are added
        // lazily by ensureSkyPipelineForSampleCount() the first time a frame
        // requires them. Sky is optional - any failure here leaves the field
        // null and the renderer falls back to clear colour.
        $this->skyPipeline = $this->ensureSkyPipelineForSampleCount(1);

        // Cubemap-target sky pipeline. Same vertex/fragment functions, but
        // outputs into the RGBA16Float environment cubemap instead of the
        // BGRA8Unorm drawable. Failure to build leaves the IBL path
        // disabled - the renderer falls back to sky-tinted pseudo-IBL.
        $this->skyCubemapPipeline = $this->ensureSkyCubemapPipeline();
    }

    private function ensureSkyCubemapPipeline(): ?RenderPipelineState
    {
        if ($this->skyCubemapPipeline !== null) {
            return $this->skyCubemapPipeline;
        }

        $msl = @file_get_contents(self::SKY_SHADER_PATH);
        if ($msl === false) {
            return null;
        }

        try {
            $library = $this->device->createLibraryWithSource($msl);
            $vertFn  = $library->getFunction('vertex_sky');
            $fragFn  = $library->getFunction('fragment_sky');

            $desc = new RenderPipelineDescriptor();
            $desc->setVertexFunction($vertFn);
            $desc->setFragmentFunction($fragFn);
            // Cubemap face texture format — must match MetalCubemapTarget.
            $desc->getColorAttachment(0)->setPixelFormat(\Metal\PixelFormatRGBA16Float);
            $desc->setDepthAttachmentPixelFormat($this->offscreenDepthPixelFormat);
            $desc->setRasterSampleCount(1);

            return $this->device->createRenderPipelineState($desc);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Render the atmospheric sky into the IBL cubemap (one face at a time)
     * and regenerate its mipmap chain for trilinear roughness sampling in
     * the mesh3d shader.
     *
     * Skipped on frames where:
     *   - no SetSky has arrived yet, OR
     *   - the cubemap target failed to allocate (Metal OOM, missing API),
     *   - the cubemap pipeline failed to compile, OR
     *   - the supplied SetSky parameters hash matches the previously
     *     rendered cubemap (sky hasn't moved since last update).
     *
     * Encodes onto the supplied command buffer; six render passes plus a
     * blit pass for generateMipmaps.
     */
    private function updateEnvironmentCubemap(\Metal\CommandBuffer $commandBuffer, SetSky $sky): bool
    {
        $debug = getenv('PHPOLYGON_DEBUG_METAL') === '1';
        $this->cubemapTarget ??= new MetalCubemapTarget($this->device);
        if (!$this->cubemapTarget->ensureAllocated()) {
            if ($debug) fprintf(STDERR, "[MetalRenderer3D] cubemap allocation failed\n");
            return false;
        }
        if ($this->skyCubemapPipeline === null) {
            $this->skyCubemapPipeline = $this->ensureSkyCubemapPipeline();
            if ($this->skyCubemapPipeline === null) {
                if ($debug) fprintf(STDERR, "[MetalRenderer3D] skyCubemapPipeline NULL — IBL disabled\n");
                return false;
            }
        }

        $hash = $this->skyHash($sky);
        if (!$this->cubemapTarget->needsUpdate($hash)) {
            return true; // cubemap is already up-to-date
        }
        if ($debug) fprintf(STDERR, "[MetalRenderer3D] rendering 6 cubemap faces (skyHash={$hash})\n");

        $cubemap      = $this->cubemapTarget->cubemap();
        $depthTexture = $this->cubemapTarget->depthTexture();
        if ($cubemap === null || $depthTexture === null || $this->skyDepthState === null) {
            return false;
        }

        $faceSize     = $this->cubemapTarget->faceSize();
        $faceViews    = MetalCubemapTarget::faceViewMatrices();
        $faceProj     = MetalCubemapTarget::faceProjectionMatrix();

        for ($face = 0; $face < 6; $face++) {
            $renderPass = new RenderPassDescriptor();
            $renderPass->setColorAttachmentTexture(0, $cubemap);
            $renderPass->setColorAttachmentSlice(0, $face);
            $renderPass->setColorAttachmentLevel(0, 0);
            $renderPass->setColorAttachmentLoadAction(0, \Metal\LoadActionClear);
            $renderPass->setColorAttachmentStoreAction(0, \Metal\StoreActionStore);
            $renderPass->setColorAttachmentClearColor(0, 0.0, 0.0, 0.0, 1.0);

            $renderPass->setDepthAttachmentTexture($depthTexture);
            $renderPass->setDepthAttachmentLoadAction(\Metal\LoadActionClear);
            $renderPass->setDepthAttachmentStoreAction(\Metal\StoreActionDontCare);
            $renderPass->setDepthAttachmentClearDepth(1.0);

            $encoder = $commandBuffer->createRenderCommandEncoder($renderPass);
            $encoder->setViewport(0.0, 0.0, (float)$faceSize, (float)$faceSize, 0.0, 1.0);
            $encoder->setScissorRect(0, 0, $faceSize, $faceSize);
            $encoder->setRenderPipelineState($this->skyCubemapPipeline);
            $encoder->setDepthStencilState($this->skyDepthState);
            $encoder->setCullMode(\Metal\CullModeNone);

            // Compute inv(proj * faceView) so fragment_sky can unproject NDC
            // back to a world-space view direction for this face.
            $faceView = new Mat4($faceViews[$face]);
            $vp       = (new Mat4($faceProj))->multiply($faceView);
            $invVp    = $vp->inverse()->toArray();

            $encoder->setFragmentBytes($this->packSkyUbo($sky, $invVp), 0);
            $encoder->drawPrimitives(\Metal\PrimitiveTypeTriangle, 0, 3);
            $encoder->endEncoding();
        }

        // Generate the mip chain so fragment shaders can pick a roughness-
        // appropriate LOD via cubemap.sample(s, R, level(roughness * mipMax)).
        $blit = $commandBuffer->createBlitCommandEncoder();
        $blit->generateMipmaps($cubemap);
        $blit->endEncoding();

        $this->cubemapTarget->markRendered($hash);
        $this->cubemapReady = true;
        if ($debug) fprintf(STDERR, "[MetalRenderer3D] cubemap rendered + mipmaps generated; cubemapReady=true\n");
        return true;
    }

    /**
     * Hash of all SetSky inputs that influence the rendered sky look. Used
     * to skip cubemap re-rendering when nothing has changed (saves six
     * render passes + mipmap gen per stable frame).
     */
    private function skyHash(SetSky $sky): string
    {
        $md = $sky->moonDirection ?? new \PHPolygon\Math\Vec3(0.0, -1.0, 0.0);
        return md5(pack(
            'f*',
            $sky->sunDirection->x, $sky->sunDirection->y, $sky->sunDirection->z, $sky->sunIntensity,
            $sky->sunColor->r, $sky->sunColor->g, $sky->sunColor->b, $sky->sunSize,
            $sky->zenithColor->r, $sky->zenithColor->g, $sky->zenithColor->b, $sky->sunGlowSize,
            $sky->horizonColor->r, $sky->horizonColor->g, $sky->horizonColor->b, $sky->sunGlowIntensity,
            $sky->groundColor->r, $sky->groundColor->g, $sky->groundColor->b, $sky->starBrightness,
            $md->x, $md->y, $md->z, $sky->moonIntensity,
            $sky->moonColor->r, $sky->moonColor->g, $sky->moonColor->b, $sky->cloudCover,
            $sky->cloudAltitude, $sky->cloudDensity, $sky->cloudWindSpeed, $sky->fogDensity,
            $sky->cloudWindDirection->x, $sky->cloudWindDirection->z,
        ));
    }

    /**
     * Pack the SkyUBO struct as defined in sky.metal. Same layout as
     * encodeSkyPass uses, factored out so the cubemap face passes can
     * supply their own face-specific inverse-VP matrix.
     *
     * @param float[] $invVp column-major float[16]
     */
    private function packSkyUbo(SetSky $sky, array $invVp): string
    {
        $sd  = $sky->sunDirection;
        $sc  = $sky->sunColor;
        $zc  = $sky->zenithColor;
        $hc  = $sky->horizonColor;
        $gc  = $sky->groundColor;
        $md  = $sky->moonDirection ?? new \PHPolygon\Math\Vec3(0.0, -1.0, 0.0);
        $mc  = $sky->moonColor;
        $cwd = $sky->cloudWindDirection;
        $cwl = sqrt($cwd->x * $cwd->x + $cwd->z * $cwd->z);
        $wx  = $cwl > 1e-6 ? $cwd->x / $cwl : 1.0;
        $wz  = $cwl > 1e-6 ? $cwd->z / $cwl : 0.0;

        return pack('f16', ...$invVp)
             . pack('f3f',  $this->cameraPos[0], $this->cameraPos[1], $this->cameraPos[2], $sky->time)
             . pack('f3f',  $sd->x, $sd->y, $sd->z, $sky->sunIntensity)
             . pack('f3f',  $sc->r, $sc->g, $sc->b, $sky->sunSize)
             . pack('f3f',  $zc->r, $zc->g, $zc->b, $sky->sunGlowSize)
             . pack('f3f',  $hc->r, $hc->g, $hc->b, $sky->sunGlowIntensity)
             . pack('f3f',  $gc->r, $gc->g, $gc->b, $sky->starBrightness)
             . pack('f3f',  $md->x, $md->y, $md->z, $sky->moonIntensity)
             . pack('f3f',  $mc->r, $mc->g, $mc->b, $sky->cloudCover)
             . pack('ffff', $sky->cloudAltitude, $sky->cloudDensity, $sky->cloudWindSpeed, $sky->fogDensity)
             . pack('ffff', $wx, $wz, 0.0, 0.0);
    }

    /**
     * Encode the atmospheric sky pass. Runs INSIDE the same render encoder as
     * the opaque pass — depth state is "always pass, never write" so the
     * sky is rendered first and opaque geometry overdraws it.
     *
     * The pipeline state is supplied by the caller so MSAA-enabled frames
     * can route to the correct sample-count-cached PSO.
     */
    private function encodeSkyPass(\Metal\RenderCommandEncoder $encoder, SetSky $sky, RenderPipelineState $skyPipeline): void
    {
        if ($this->skyDepthState === null) {
            return;
        }

        // Build inverse(projection * rotation_view) so the fragment shader can
        // unproject NDC back to a world-space view direction. Translation is
        // stripped — sky depends only on look direction, not position.
        $vm = $this->viewMatrix;
        $rotView = new Mat4([
            $vm[0],  $vm[1],  $vm[2],  0.0,
            $vm[4],  $vm[5],  $vm[6],  0.0,
            $vm[8],  $vm[9],  $vm[10], 0.0,
            0.0,     0.0,     0.0,     1.0,
        ]);
        // Match the Z-corrected projection used for opaque draws (uploadFrameUbo).
        $metalClip = new Mat4([
            1.0, 0.0, 0.0, 0.0,
            0.0, 1.0, 0.0, 0.0,
            0.0, 0.0, 0.5, 0.0,
            0.0, 0.0, 0.5, 1.0,
        ]);
        $proj  = $metalClip->multiply(new Mat4($this->projMatrix));
        $vp    = $proj->multiply($rotView);
        $invVp = $vp->inverse();

        $encoder->setRenderPipelineState($skyPipeline);
        $encoder->setDepthStencilState($this->skyDepthState);
        $encoder->setCullMode(\Metal\CullModeNone);

        // Pack SkyUBO matching the MSL struct layout (see sky.metal).
        // float4x4 (64) + 14 × (vec3 + scalar) blocks (16 bytes each) + 8 trailing bytes.
        // Easiest: build via pack() in the same field order as the struct.
        $sd = $sky->sunDirection;
        $sc = $sky->sunColor;
        $zc = $sky->zenithColor;
        $hc = $sky->horizonColor;
        $gc = $sky->groundColor;
        $md = $sky->moonDirection ?? new \PHPolygon\Math\Vec3(0.0, -1.0, 0.0);
        $mc = $sky->moonColor;
        $cwd = $sky->cloudWindDirection;
        $cwl = sqrt($cwd->x * $cwd->x + $cwd->z * $cwd->z);
        $wx  = $cwl > 1e-6 ? $cwd->x / $cwl : 1.0;
        $wz  = $cwl > 1e-6 ? $cwd->z / $cwl : 0.0;

        $bytes = pack('f16', ...$invVp->toArray())
               . pack('f3f',  $this->cameraPos[0], $this->cameraPos[1], $this->cameraPos[2], $sky->time)
               . pack('f3f',  $sd->x, $sd->y, $sd->z, $sky->sunIntensity)
               . pack('f3f',  $sc->r, $sc->g, $sc->b, $sky->sunSize)
               . pack('f3f',  $zc->r, $zc->g, $zc->b, $sky->sunGlowSize)
               . pack('f3f',  $hc->r, $hc->g, $hc->b, $sky->sunGlowIntensity)
               . pack('f3f',  $gc->r, $gc->g, $gc->b, $sky->starBrightness)
               . pack('f3f',  $md->x, $md->y, $md->z, $sky->moonIntensity)
               . pack('f3f',  $mc->r, $mc->g, $mc->b, $sky->cloudCover)
               . pack('ffff', $sky->cloudAltitude, $sky->cloudDensity, $sky->cloudWindSpeed, $sky->fogDensity)
               . pack('ffff', $wx, $wz, 0.0, 0.0);

        $encoder->setFragmentBytes($bytes, 0);
        $encoder->drawPrimitives(\Metal\PrimitiveTypeTriangle, 0, 3);

        if (getenv('PHPOLYGON_DEBUG_METAL') === '1') {
            static $logged = false;
            if (!$logged) {
                fprintf(STDERR, "[MetalRenderer3D] encodeSkyPass: drawPrimitives(Triangle, 0, 3) issued for drawable\n");
                $logged = true;
            }
        }
    }
}
