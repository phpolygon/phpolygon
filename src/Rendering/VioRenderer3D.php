<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\AddPointLight;
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

    /** @var array<string, int> Material prefix → proc_mode cache */
    private static array $procModeCache = [];

    private ?Mat4 $currentViewMatrix = null;
    private ?Mat4 $currentProjectionMatrix = null;
    private ?Vec3 $cameraPosition = null;

    private float $globalTime = 0.0;
    private float $snowCover = 0.0;

    // Post-processing
    private ?VioRenderTarget $hdrTarget = null;
    private ?VioRenderTarget $bloomExtractTarget = null;
    private ?VioRenderTarget $bloomPingTarget = null;
    private ?VioRenderTarget $bloomPongTarget = null;
    private ?VioMesh $screenQuad = null;
    private bool $enableHdr = false;
    private float $bloomIntensity = 0.35;
    private float $bloomThreshold = 1.0;
    private float $exposure = 1.8;

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

    /**
     * True when the current frame is being rendered into the offscreen target
     * rather than directly into the backbuffer. Set by beginFrame(), read by
     * endFrame() to know whether to run the present pass.
     */
    private bool $offscreenActive = false;

    /** Backbuffer resolution captured at frame start (for blit destination). */
    private int $backbufferWidth = 0;
    private int $backbufferHeight = 0;

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

    public function applySettings(GraphicsSettings $settings): void
    {
        $previousShadow = $this->settings->shadowQuality;
        $this->settings = $settings;

        // Shadow-map tier change forces re-init on next frame.
        if ($previousShadow !== $settings->shadowQuality) {
            $this->shadowTarget = null;
        }

        // Bloom toggle is read live from $this->settings during render.
        $this->enableHdr = $this->enableHdr && $settings->bloom;

        // Phase 1.5: render-scale + AA pipeline.
        //
        // Render-scale and FXAA are delivered through a vio_render_target +
        // screen-quad post-process pass (see beginFrame()/endFrame()).
        //
        // MSAA is probed at allocation time. vio's exact key is undocumented
        // for samples > 1, so VioOffscreenTarget falls back to single-sample
        // when the backend rejects the request - meaning render-scale and
        // FXAA stay functional even on backends without MSAA support.
        //
        // The size update is applied immediately so the slider reflects on
        // the next frame; if the backbuffer dimensions are not yet known,
        // beginFrame() picks up the latest settings on its first invocation.
        $this->resizeOffscreenIfNeeded();
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

        $this->offscreenTarget->resize($targetW, $targetH, $samples);
    }

    private function offscreenIsActive(): bool
    {
        // Phase 1.5 offscreen pipeline on Vio/D3D11 renders black after the
        // FXAA blit even though vio_render_target_texture no longer crashes
        // (see the SRV-wrapper cache fix in php-vio's php_vio.c). Disabling
        // the pipeline here keeps the 3D pass writing directly to the
        // swapchain until the present-side FXAA path is debugged.
        // Trade-off: no FXAA, no render-scale, no TAA on Vio backends.
        return false;
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
        vio_viewport($this->ctx, 0, 0, $this->backbufferWidth, $this->backbufferHeight);

        if ($this->settings->antiAliasing === AntiAliasing::Fxaa && $this->fxaaPass !== null) {
            $this->fxaaPass->apply($sceneTex, $target->width(), $target->height(), $quad);
        } else {
            $this->bindPostProcessPipeline('passthrough_blit');
            vio_bind_texture($this->ctx, $sceneTex, 0);
            vio_set_uniform($this->ctx, 'u_source', 0);
            vio_draw($this->ctx, $quad);
        }

        $this->offscreenActive = false;
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

        $this->beginOffscreenIfRequired();
    }

    public function endFrame(): void
    {
        $this->presentOffscreenIfActive();
        vio_draw_3d($this->ctx);
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
        $ambientIntensity = 1.0;
        $dirLights = [];
        $pointLights = [];
        $fogColor = new Color(0.0, 0.0, 0.0);
        $fogNear = 1000.0;
        $fogFar = 2000.0;
        $waveEnabled = false;
        $waveAmplitude = 0.3;
        $waveFrequency = 0.5;
        $wavePhase = 0.0;

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
            'fogColor' => $fogColor,
            'fogNear' => $fogNear,
            'fogFar' => $fogFar,
            'waveEnabled' => $waveEnabled,
            'waveAmplitude' => $waveAmplitude,
            'waveFrequency' => $waveFrequency,
            'wavePhase' => $wavePhase,
        ];

        // --- Shadow pass ---
        $hasShadowMap = $this->renderShadowPass($commandList, $dirLights);

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
        if ($this->pendingSky !== null) {
            $this->renderAtmosphericSky($this->pendingSky);
        }
        // Legacy cubemap skybox still supported; rendered only if no SetSky
        // command was issued this frame.
        if ($this->pendingSky === null
            && $this->pendingSkyboxId !== null) {
            $this->renderSkybox($this->pendingSkyboxId);
        }
        $this->pendingSky = null;
        $this->pendingSkyboxId = null;

        // --- Pass 2: Opaque geometry ---
        $this->bindPipeline('opaque');
        $this->uploadFrameUniforms($frameState);
        $this->uploadShadowUniforms($hasShadowMap, $dirLights);

        foreach ($commands as $cmd) {
            if ($cmd instanceof DrawMesh) {
                $material = MaterialRegistry::get($cmd->materialId);
                if ($material === null || $material->alpha < 1.0) {
                    continue;
                }
                $this->drawMeshCommand($cmd->meshId, $material, $cmd->modelMatrix, $cmd->materialId);
            } elseif ($cmd instanceof DrawMeshInstanced) {
                $material = MaterialRegistry::get($cmd->materialId);
                if ($material === null || $material->alpha < 1.0) {
                    continue;
                }
                $this->drawMeshInstancedCommand($cmd, $material);
            }
        }

        // --- Pass 3: Transparent geometry ---
        $this->bindPipeline('transparent');
        $this->uploadFrameUniforms($frameState);
        $this->uploadShadowUniforms($hasShadowMap, $dirLights);

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
        $this->compileShaderFromFiles('atmosphere', 'atmosphere.vert.glsl', 'atmosphere.frag.glsl');
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

        $this->screenQuad = vio_mesh($this->ctx, [
            'vertices' => [
                -1, -1, 0,  0, 0,
                 1, -1, 0,  1, 0,
                 1,  1, 0,  1, 1,
                -1,  1, 0,  0, 1,
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
        $format = vio_backend_name($this->ctx) === 'opengl'
            ? VIO_SHADER_GLSL_RAW
            : VIO_SHADER_GLSL;

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
        $key = $pass . ':' . $shaderId;

        if (!isset($this->pipelineCache[$key])) {
            $shader = $this->shaderCache[$shaderId] ?? $this->shaderCache['default'];

            $pipeline = vio_pipeline($this->ctx, [
                'shader' => $shader,
                'depth_test' => true,
                'cull_mode' => VIO_CULL_NONE,
                'blend' => $pass === 'transparent' ? VIO_BLEND_ALPHA : VIO_BLEND_NONE,
            ]);

            if ($pipeline === false) {
                return;
            }

            $this->pipelineCache[$key] = $pipeline;
        }

        vio_bind_pipeline($this->ctx, $this->pipelineCache[$key]);
    }

    private function bindPostProcessPipeline(string $shaderId): void
    {
        $key = 'postprocess:' . $shaderId;
        if (!isset($this->pipelineCache[$key])) {
            $pipeline = vio_pipeline($this->ctx, [
                'shader' => $this->shaderCache[$shaderId],
                'depth_test' => false,
                'cull_mode' => VIO_CULL_NONE,
                'blend' => VIO_BLEND_NONE,
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
        $this->cascadeLightSpaceMatrices = [];
        foreach (self::CASCADE_ORTHO_SIZES as $cIdx => $orthoSize) {
            $target = $this->cascadeShadowTargets[$cIdx] ?? null;
            if ($target === null) continue;

            $lightSpaceMatrix = $this->computeLightSpaceMatrix($lightDir, $shadowCenter, $orthoSize);
            $this->cascadeLightSpaceMatrices[$cIdx] = $lightSpaceMatrix;

            vio_bind_render_target($this->ctx, $target);
            vio_viewport($this->ctx, 0, 0, $shadowRes, $shadowRes);
            vio_clear($this->ctx, 1.0, 1.0, 1.0, 1.0);

            $this->bindShadowPipeline();
            vio_set_uniform($this->ctx, 'u_view', $lightSpaceMatrix->toArray());
            vio_set_uniform($this->ctx, 'u_projection', Mat4::identity()->toArray());

            foreach ($commandList->getCommands() as $cmd) {
                if ($cmd instanceof DrawMesh) {
                    $mat = MaterialRegistry::get($cmd->materialId);
                    if ($mat === null || $mat->alpha < 0.9) {
                        continue;
                    }
                    $matId = $cmd->materialId;
                    if (str_starts_with($matId, 'sky_') || str_starts_with($matId, 'sun_')
                        || str_starts_with($matId, 'moon_') || str_starts_with($matId, 'cloud_')
                        || $matId === 'precipitation') {
                        continue;
                    }
                    $mesh = $this->uploadMesh($cmd->meshId);
                    if ($mesh === null) {
                        continue;
                    }
                    vio_set_uniform($this->ctx, 'u_model', $cmd->modelMatrix->toArray());
                    vio_set_uniform($this->ctx, 'u_use_instancing', 0);
                    vio_draw($this->ctx, $mesh);
                } elseif ($cmd instanceof DrawMeshInstanced) {
                    $mat = MaterialRegistry::get($cmd->materialId);
                    if ($mat === null || $mat->alpha < 0.9) {
                        continue;
                    }
                    $matId = $cmd->materialId;
                    if (str_starts_with($matId, 'sky_') || str_starts_with($matId, 'sun_')
                        || str_starts_with($matId, 'moon_') || str_starts_with($matId, 'cloud_')
                        || $matId === 'precipitation') {
                        continue;
                    }
                    $mesh = $this->uploadMesh($cmd->meshId);
                    if ($mesh === null) {
                        continue;
                    }
                    [$flatMatrices, $instanceCount] = $this->resolveInstanceData($cmd->meshId, $mat, $cmd);
                    vio_set_uniform($this->ctx, 'u_use_instancing', 1);
                    vio_draw_instanced($this->ctx, $mesh, $flatMatrices, $instanceCount);
                }
            }
        }

        vio_unbind_render_target($this->ctx);

        // Cascade 0 also fills the legacy single-map slot for cloud-shadow paths.
        return true;
    }

    /**
     * Build the shadow-pass light-space matrix.
     *
     * When a $cameraTarget is supplied the shadow frustum is centred on it
     * and texel-snapped to the shadow-map grid - both prerequisites for
     * stable open-world shadows. Without these the shadow box stays
     * pinned to the world origin and shimmering edges appear as the
     * camera moves continuously.
     */
    private function computeLightSpaceMatrix(Vec3 $sunDirection, ?Vec3 $cameraTarget = null, ?float $orthoSize = null): Mat4
    {
        $len = sqrt($sunDirection->x ** 2 + $sunDirection->y ** 2 + $sunDirection->z ** 2);
        if ($len < 0.001) {
            return Mat4::identity();
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
        // its depth comparison into a 200 m range, making the fragment-shader
        // bias map to a ~1 m world offset. That blanketed cascade 0's ~15 m
        // radius (the disc around the camera) in a constant dark shadow term.
        // Backing the light off by the cascade extent + caster headroom and
        // bracketing near/far to the cascade restores a sane bias-to-world
        // ratio without changing the (fixed) per-cascade ortho footprint.
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

        return $lightProj->multiply($lightView);
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

        $backend = vio_backend_name($this->ctx);
        $flipY = ($backend === 'd3d11' || $backend === 'd3d12');

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

            // Cascade 0 also drives the legacy single-map uniforms so any
            // legacy shader path still works.
            if ($cIdx === 0) {
                vio_set_uniform($this->ctx, 'u_shadow_map', $unit);
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

            $pipeline = vio_pipeline($this->ctx, [
                'shader' => $shader,
                'depth_test' => true,
                'cull_mode' => VIO_CULL_FRONT, // front-face culling eliminates Peter Pan gap
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
        // Mat4::multiply is standard math: $a->multiply($b) returns a * b.
        $vp = $projMatrix->multiply($rotView);
        $invVP = $vp->inverse();

        $this->bindAtmospherePipeline();

        vio_set_uniform($this->ctx, 'u_sky_inv_vp', $invVP->toArray());

        $camPos = $this->cameraPosition ?? new Vec3(0.0, 0.0, 0.0);
        vio_set_uniform($this->ctx, 'u_camera_pos', [$camPos->x, $camPos->y, $camPos->z]);

        $sunDir = $sky->sunDirection;
        vio_set_uniform($this->ctx, 'u_sun_direction', [$sunDir->x, $sunDir->y, $sunDir->z]);
        vio_set_uniform($this->ctx, 'u_sun_color', [$sky->sunColor->r, $sky->sunColor->g, $sky->sunColor->b]);
        vio_set_uniform($this->ctx, 'u_sun_intensity', $sky->sunIntensity);

        vio_set_uniform($this->ctx, 'u_zenith_color', [$sky->zenithColor->r, $sky->zenithColor->g, $sky->zenithColor->b]);
        vio_set_uniform($this->ctx, 'u_horizon_color', [$sky->horizonColor->r, $sky->horizonColor->g, $sky->horizonColor->b]);
        vio_set_uniform($this->ctx, 'u_ground_color', [$sky->groundColor->r, $sky->groundColor->g, $sky->groundColor->b]);

        vio_set_uniform($this->ctx, 'u_sun_size', $sky->sunSize);
        vio_set_uniform($this->ctx, 'u_sun_glow_size', $sky->sunGlowSize);
        vio_set_uniform($this->ctx, 'u_sun_glow_intensity', $sky->sunGlowIntensity);

        $moonDir = $sky->moonDirection ?? new Vec3(0.0, -1.0, 0.0);
        vio_set_uniform($this->ctx, 'u_moon_direction', [$moonDir->x, $moonDir->y, $moonDir->z]);
        vio_set_uniform($this->ctx, 'u_moon_color', [$sky->moonColor->r, $sky->moonColor->g, $sky->moonColor->b]);
        vio_set_uniform($this->ctx, 'u_moon_intensity', $sky->moonIntensity);

        vio_set_uniform($this->ctx, 'u_star_brightness', $sky->starBrightness);

        // Clouds + horizon haze
        vio_set_uniform($this->ctx, 'u_cloud_cover', $sky->cloudCover);
        vio_set_uniform($this->ctx, 'u_cloud_altitude', $sky->cloudAltitude);
        vio_set_uniform($this->ctx, 'u_cloud_density', $sky->cloudDensity);
        vio_set_uniform($this->ctx, 'u_cloud_wind_speed', $sky->cloudWindSpeed);

        // Normalise wind direction in the XZ plane so clouds drift in world space.
        $wd = $sky->cloudWindDirection;
        $wl = sqrt($wd->x * $wd->x + $wd->z * $wd->z);
        $wx = $wl > 1e-6 ? $wd->x / $wl : 1.0;
        $wz = $wl > 1e-6 ? $wd->z / $wl : 0.0;
        vio_set_uniform($this->ctx, 'u_cloud_wind_dir', [$wx, $wz]);

        vio_set_uniform($this->ctx, 'u_fog_density', $sky->fogDensity);
        vio_set_uniform($this->ctx, 'u_time', $sky->time);

        vio_draw($this->ctx, $quad);
    }

    private function bindAtmospherePipeline(): void
    {
        $key = 'atmosphere:atmosphere';
        if (!isset($this->pipelineCache[$key])) {
            $shader = $this->shaderCache['atmosphere'];
            $pipeline = vio_pipeline($this->ctx, [
                'shader' => $shader,
                'depth_test' => false,
                'cull_mode' => VIO_CULL_NONE,
                'blend' => VIO_BLEND_NONE,
            ]);
            if ($pipeline === false) {
                return;
            }
            $this->pipelineCache[$key] = $pipeline;
        }
        vio_bind_pipeline($this->ctx, $this->pipelineCache[$key]);
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
        $key = 'skybox:skybox';

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

        // Per-draw uniforms
        vio_set_uniform($this->ctx, 'u_model', $modelMatrix->toArray());
        vio_set_uniform($this->ctx, 'u_use_instancing', 0);

        $nm = $this->computeNormalMatrix($modelMatrix);
        vio_set_uniform($this->ctx, 'u_normal_matrix', $nm);

        $this->applyMaterial($material, $materialId);
        $this->bindMeshAabb($meshId);

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

        $this->applyMaterial($material, $cmd->materialId);
        $this->bindMeshAabb($cmd->meshId);
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

    private function applyMaterial(Material $material, string $materialId = ''): void
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

        // Texture binding
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
     * @param array{ambientColor: Color, ambientIntensity: float, dirLights: list<SetDirectionalLight>, pointLights: list<AddPointLight>, fogColor: Color, fogNear: float, fogFar: float, waveEnabled: bool, waveAmplitude: float, waveFrequency: float, wavePhase: float} $state
     */
    private function uploadFrameUniforms(array $state): void
    {
        if ($this->currentViewMatrix === null || $this->currentProjectionMatrix === null) {
            return;
        }
        vio_set_uniform($this->ctx, 'u_view', $this->currentViewMatrix->toArray());
        vio_set_uniform($this->ctx, 'u_projection', $this->currentProjectionMatrix->toArray());
        vio_set_uniform($this->ctx, 'u_linear_output', $this->hdrTarget !== null ? 1 : 0);

        if ($this->cameraPosition !== null) {
            vio_set_uniform($this->ctx, 'u_camera_pos', [
                $this->cameraPosition->x, $this->cameraPosition->y, $this->cameraPosition->z,
            ]);
        }

        $ac = $state['ambientColor'];
        $ai = $state['ambientIntensity'];
        $piScale = M_PI; // compensate Lambertian /π in Cook-Torrance BRDF
        vio_set_uniform($this->ctx, 'u_ambient_color', [$ac->r * $ai * $piScale, $ac->g * $ai * $piScale, $ac->b * $ai * $piScale]);
        vio_set_uniform($this->ctx, 'u_ambient_intensity', $ai * $piScale);

        $dirCount = min(count($state['dirLights']), 4);
        vio_set_uniform($this->ctx, 'u_dir_light_count', $dirCount);
        for ($i = 0; $i < $dirCount; $i++) {
            $dl = $state['dirLights'][$i];
            vio_set_uniform($this->ctx, "u_dir_lights[{$i}].direction", [$dl->direction->x, $dl->direction->y, $dl->direction->z]);
            vio_set_uniform($this->ctx, "u_dir_lights[{$i}].color", [$dl->color->r, $dl->color->g, $dl->color->b]);
            vio_set_uniform($this->ctx, "u_dir_lights[{$i}].intensity", $dl->intensity * $piScale);
        }

        $ptCount = min(count($state['pointLights']), 4);
        vio_set_uniform($this->ctx, 'u_point_light_count', $ptCount);
        for ($i = 0; $i < $ptCount; $i++) {
            $pl = $state['pointLights'][$i];
            vio_set_uniform($this->ctx, "u_point_lights[{$i}].position", [$pl->position->x, $pl->position->y, $pl->position->z]);
            vio_set_uniform($this->ctx, "u_point_lights[{$i}].color", [$pl->color->r, $pl->color->g, $pl->color->b]);
            vio_set_uniform($this->ctx, "u_point_lights[{$i}].intensity", $pl->intensity * $piScale);
            vio_set_uniform($this->ctx, "u_point_lights[{$i}].radius", $pl->radius);
        }

        $fc = $state['fogColor'];
        vio_set_uniform($this->ctx, 'u_fog_color', [$fc->r, $fc->g, $fc->b]);
        vio_set_uniform($this->ctx, 'u_fog_near', $state['fogNear']);
        vio_set_uniform($this->ctx, 'u_fog_far', $state['fogFar']);

        vio_set_uniform($this->ctx, 'u_time', $this->globalTime);
        vio_set_uniform($this->ctx, 'u_ao_strength', $this->settings->ambientOcclusion->strength());

        // Color grading + vignette per-frame.
        $grade = $this->settings->colorGrading->params();
        vio_set_uniform($this->ctx, 'u_grade_lift',        $grade['lift']);
        vio_set_uniform($this->ctx, 'u_grade_gamma',       $grade['gamma']);
        vio_set_uniform($this->ctx, 'u_grade_gain',        $grade['gain']);
        vio_set_uniform($this->ctx, 'u_grade_saturation', $grade['saturation']);
        vio_set_uniform($this->ctx, 'u_vignette_intensity', $this->settings->vignetteIntensity);
        vio_set_uniform($this->ctx, 'u_volumetric_fog', $this->settings->volumetricFog ? 1 : 0);
        vio_set_uniform($this->ctx, 'u_ssr_intensity', $this->settings->ssr->intensity());
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
