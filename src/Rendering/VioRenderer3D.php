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
    /** Per-cascade ortho-box half-extents (matches OpenGLRenderer3D). */
    private const CASCADE_ORTHO_SIZES = [15.0, 50.0, 150.0];
    private const SHADOW_MAP_RESOLUTION = 2048;
    private const SHADOW_ORTHO_SIZE = 60.0;

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
        // The HDR/Bloom path owns its own render target and post-process
        // pipeline. When HDR is active we skip the Phase 1.5 offscreen
        // pipeline entirely - the bloom passes already manage scaling and
        // tonemapping. (HDR is currently disabled in production - see the
        // D3D11 note in render() - so this branch is mostly defensive.)
        if ($this->enableHdr) {
            return false;
        }

        if ($this->settings->renderScale !== 1.0) {
            return true;
        }

        return $this->settings->antiAliasing !== AntiAliasing::Off;
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
        $this->compileShader('default', self::DEFAULT_VERT, self::DEFAULT_FRAG);
        $this->compileShader('unlit', self::UNLIT_VERT, self::UNLIT_FRAG);
        $this->compileShader('shadow', self::SHADOW_VERT, self::SHADOW_FRAG);
        $this->compileShader('depth', self::DEPTH_VERT, self::DEPTH_FRAG);
        $this->compileShader('normals', self::NORMALS_VERT, self::NORMALS_FRAG);
        $this->compileShader('skybox', self::SKYBOX_VERT, self::SKYBOX_FRAG);
        $this->compileShader('atmosphere', self::ATMOSPHERE_VERT, self::ATMOSPHERE_FRAG);
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

        $this->compileShader('bloom_extract', self::POSTPROCESS_VERT, self::BLOOM_EXTRACT_FRAG);
        $this->compileShader('bloom_blur', self::POSTPROCESS_VERT, self::BLOOM_BLUR_FRAG);
        $this->compileShader('tonemap', self::POSTPROCESS_VERT, self::TONEMAP_FRAG);
        $this->compileShader('passthrough_blit', self::POSTPROCESS_VERT, self::PASSTHROUGH_BLIT_FRAG);

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

        $lightPos = new Vec3(
            $center->x - $dx * 80.0,
            $center->y - $dy * 80.0,
            $center->z - $dz * 80.0,
        );

        $up = abs($dy) > 0.999
            ? new Vec3(0.0, 0.0, 1.0)
            : new Vec3(0.0, 1.0, 0.0);

        $lightView = self::lookAt($lightPos, $center, $up);
        $lightProj = Mat4::orthographic(-$s, $s, -$s, $s, 0.5, 200.0);

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

    // ----------------------------------------------------------------
    // GLSL 410 shaders
    // ----------------------------------------------------------------

    private const DEFAULT_VERT = <<<'GLSL'
#version 410 core

layout(location = 0) in vec3 a_position;
layout(location = 1) in vec3 a_normal;
layout(location = 2) in vec2 a_uv;
layout(location = 3) in vec4 a_instance_col0;
layout(location = 4) in vec4 a_instance_col1;
layout(location = 5) in vec4 a_instance_col2;
layout(location = 6) in vec4 a_instance_col3;

uniform mat4 u_model;
uniform mat4 u_view;
uniform mat4 u_projection;
uniform mat3 u_normal_matrix;
uniform int  u_use_instancing;

uniform float u_time;
uniform int   u_vertex_anim;
uniform float u_wave_amplitude;
uniform float u_wave_frequency;
uniform float u_wave_phase;

// Procedural cloth (mirrors mesh3d.vert.glsl). See Material::cloth()
// and Command\SetWind for the engine-side surface.
uniform int   u_cloth;
uniform float u_cloth_strength;
uniform float u_cloth_frequency;
uniform float u_cloth_phase;
uniform int   u_cloth_anchor_top;
uniform vec3  u_wind_direction;
uniform float u_wind_intensity;
uniform vec3  u_mesh_local_aabb_min;
uniform vec3  u_mesh_local_aabb_max;

uniform mat4 u_light_space_matrix;

out vec3 v_normal;
out vec3 v_worldPos;
out vec2 v_uv;
out vec4 v_lightSpacePos;
out vec3 v_localPos;
out vec3 v_localNormal;
out vec3 v_objectScale;

void main() {
    mat4 model = u_model;
    if (u_use_instancing == 1) {
        model = mat4(a_instance_col0, a_instance_col1, a_instance_col2, a_instance_col3);
    }
    vec3 pos = a_position;

    if (u_vertex_anim == 1) {
        vec4 worldPosRaw = model * vec4(pos, 1.0);
        float t = u_time + u_wave_phase;
        float f = u_wave_frequency;
        float a = u_wave_amplitude;
        // Long rolling swell (dominant wave direction)
        float wave = sin(worldPosRaw.x * f * 0.15 + worldPosRaw.z * f * 0.08 + t * 0.7) * a * 0.6;
        // Secondary cross-swell
        wave += sin(worldPosRaw.x * f * 0.1 - worldPosRaw.z * f * 0.12 + t * 0.5 + 1.3) * a * 0.3;
        // Short choppy waves on top (smaller amplitude, higher frequency)
        wave += sin(worldPosRaw.x * f * 0.5 + worldPosRaw.z * f * 0.3 + t * 1.4) * a * 0.08;
        wave += sin(worldPosRaw.x * f * 0.7 - worldPosRaw.z * f * 0.6 + t * 1.8 + 2.7) * a * 0.04;
        pos.y += wave;
    }

    // Procedural cloth sway (mirrors mesh3d.vert.glsl)
    if (u_cloth == 1) {
        float aabbHeight = max(u_mesh_local_aabb_max.y - u_mesh_local_aabb_min.y, 1e-4);
        float yNorm = clamp((pos.y - u_mesh_local_aabb_min.y) / aabbHeight, 0.0, 1.0);
        float anchorWeight = u_cloth_anchor_top == 1 ? yNorm : (1.0 - yNorm);
        float swayMask = 1.0 - anchorWeight;
        float ct = u_time * u_cloth_frequency + u_cloth_phase;
        float cwave = sin(ct + pos.x * 2.0) * 0.7 + cos(ct * 1.3 + pos.z * 1.5) * 0.3;
        vec3 windDir = length(u_wind_direction) > 1e-4 ? normalize(u_wind_direction) : vec3(0.0, 0.0, 1.0);
        vec3 sway = windDir * (cwave * u_cloth_strength * u_wind_intensity * swayMask);
        sway.y *= 0.15;
        pos += sway;
    }

    vec4 worldPos = model * vec4(pos, 1.0);
    v_worldPos = worldPos.xyz;
    v_localPos = pos;
    v_localNormal = a_normal;
    v_objectScale = vec3(length(model[0].xyz), length(model[1].xyz), length(model[2].xyz));

    if (u_use_instancing == 1) {
        // Per-instance model: always compute normal matrix from instance transform
        v_normal = mat3(transpose(inverse(model))) * a_normal;
    } else {
        // Per-object: use precomputed normal matrix, fall back to model if zero
        bool isZero = (u_normal_matrix[0] == vec3(0.0) &&
                       u_normal_matrix[1] == vec3(0.0) &&
                       u_normal_matrix[2] == vec3(0.0));
        if (isZero) {
            v_normal = mat3(transpose(inverse(model))) * a_normal;
        } else {
            v_normal = u_normal_matrix * a_normal;
        }
    }

    v_uv = a_uv;
    v_lightSpacePos = u_light_space_matrix * worldPos;
    gl_Position = u_projection * u_view * worldPos;
}
GLSL;

    private const DEFAULT_FRAG = <<<'GLSL'
#version 410 core

in vec3 v_normal;
in vec3 v_worldPos;
in vec2 v_uv;
in vec4 v_lightSpacePos;
in vec3 v_localPos;
in vec3 v_localNormal;
in vec3 v_objectScale;

uniform vec3 u_ambient_color;
uniform float u_ambient_intensity;

struct DirLight {
    vec3 direction;
    vec3 color;
    float intensity;
};
uniform DirLight u_dir_lights[4];
uniform int u_dir_light_count;

#define u_dir_light_direction u_dir_lights[0].direction
#define u_dir_light_color u_dir_lights[0].color
#define u_dir_light_intensity u_dir_lights[0].intensity

// Member order matters: each trailing float packs into the tail of the
// preceding vec3's 16-byte slot, giving a clean 32-byte stride that both
// std140 and HLSL's natural cbuffer packing agree on. The previous
// ordering (vec3 pos, vec3 color, float intensity, float radius) ends up
// ambiguous — SPIRV-Cross rejects it with "cannot be expressed with
// either HLSL packing layout or packoffset".
struct PointLight {
    vec3 position;
    float radius;
    vec3 color;
    float intensity;
};
uniform PointLight u_point_lights[4];
uniform int u_point_light_count;

uniform vec3 u_albedo;
uniform vec3 u_emission;
uniform float u_roughness;
uniform float u_metallic;
uniform float u_alpha;
uniform float u_clearcoat;
uniform float u_clearcoat_roughness;
uniform float u_flakes;
uniform float u_normal_intensity;
uniform int   u_use_environment_map;
uniform int   u_normal_pattern;
uniform float u_normal_scale;
uniform int   u_surface_pattern;
uniform float u_surface_scale;
uniform float u_surface_intensity;
uniform float u_wetness;
uniform float u_ssr_intensity;
uniform int   u_volumetric_fog;
uniform float u_ao_strength;
uniform vec3  u_grade_lift;
uniform vec3  u_grade_gamma;
uniform vec3  u_grade_gain;
uniform float u_grade_saturation;
uniform float u_vignette_intensity;
uniform vec2  u_viewport_size;
uniform float u_snow_cover; // 0.0 = no snow, 1.0 = full blanket
uniform float u_rain_wetness; // 0.0 = dry, 1.0 = rain-soaked
uniform vec3 u_fog_color;
uniform float u_fog_near;
uniform float u_fog_far;
uniform vec3 u_camera_pos;
uniform float u_time;
uniform int u_proc_mode;

uniform vec3 u_sky_color;
uniform vec3 u_horizon_color;

uniform float u_moon_phase;
uniform vec3 u_season_tint;

// HDR pipeline
uniform int u_linear_output;

// Shadow
uniform int u_has_shadow_map;
uniform sampler2DShadow u_shadow_map;

// Cascade Shadow Maps (mirrors mesh3d.frag.glsl).
uniform sampler2DShadow u_csm_map_0;
uniform sampler2DShadow u_csm_map_1;
uniform sampler2DShadow u_csm_map_2;
uniform mat4 u_csm_matrix_0;
uniform mat4 u_csm_matrix_1;
uniform mat4 u_csm_matrix_2;
uniform float u_csm_far_0;
uniform float u_csm_far_1;
uniform float u_csm_far_2;
uniform int u_csm_count;

// Texture
uniform int u_has_albedo_texture;
uniform sampler2D u_albedo_texture;

out vec4 frag_color;

// ================================================================
//  Noise — lightweight
// ================================================================

float hash21(vec2 p) {
    return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453);
}

float hash31(vec3 p) {
    return fract(sin(dot(p, vec3(443.897, 441.423, 437.195))) * 43758.5453);
}

float noise(vec2 p) {
    vec2 i = floor(p);
    vec2 f = fract(p);
    f = f * f * (3.0 - 2.0 * f);
    return mix(mix(hash21(i), hash21(i + vec2(1,0)), f.x),
               mix(hash21(i + vec2(0,1)), hash21(i + vec2(1,1)), f.x), f.y);
}

float fbm2(vec2 p) {
    return noise(p) * 0.5 + noise(p * 2.0) * 0.25 + 0.25;
}

float fbm3(vec2 p) {
    return noise(p) * 0.5 + noise(p * 2.0) * 0.25 + noise(p * 4.0) * 0.125 + 0.125;
}

// ================================================================
//  Shadow
// ================================================================

// Sample a single cascade with PCF 3x3.
float sampleCascade(sampler2DShadow map, mat4 lightSpace, vec3 worldPos, vec3 N) {
    vec4 lsp = lightSpace * vec4(worldPos, 1.0);
    vec3 pc  = lsp.xyz / lsp.w * 0.5 + 0.5;
    if (pc.x < 0.0 || pc.x > 1.0 || pc.y < 0.0 || pc.y > 1.0 || pc.z > 1.0) return 1.0;
    vec3 lightDir = normalize(-u_dir_light_direction);
    float NdotL = max(dot(N, lightDir), 0.0);
    float bias = mix(0.005, 0.001, NdotL);
    float s = 0.0;
    float ts = 1.0 / 2048.0;
    float rd = pc.z - bias;
    for (int x = -1; x <= 1; x++)
        for (int y = -1; y <= 1; y++)
            s += texture(map, vec3(pc.xy + vec2(x,y) * ts, rd));
    return s / 9.0;
}

float calcShadow(vec4 lsp, vec3 N) {
    if (u_has_shadow_map == 0) return 1.0;
    // Pick the smallest CSM cascade still containing the fragment based
    // on distance to the camera (matches the per-cascade ortho extents
    // built in PHP land).
    float dist = length(v_worldPos - u_camera_pos);
    if (u_csm_count >= 2 && dist > u_csm_far_0) {
        if (u_csm_count >= 3 && dist > u_csm_far_1) {
            return sampleCascade(u_csm_map_2, u_csm_matrix_2, v_worldPos, N);
        }
        return sampleCascade(u_csm_map_1, u_csm_matrix_1, v_worldPos, N);
    }
    return sampleCascade(u_csm_map_0, u_csm_matrix_0, v_worldPos, N);
}

// ================================================================
//  PBR helpers
// ================================================================

vec3 fresnelSchlick(float cosTheta, vec3 F0) {
    return F0 + (1.0 - F0) * pow(clamp(1.0 - cosTheta, 0.0, 1.0), 5.0);
}

float distributionGGX(float NdotH, float a2) {
    float denom = NdotH * NdotH * (a2 - 1.0) + 1.0;
    return a2 / (3.14159265 * denom * denom);
}

float geometrySmith(float NdotV, float NdotL, float a2) {
    float k = a2 * 0.5;
    float ggxV = NdotV / (NdotV * (1.0 - k) + k);
    float ggxL = NdotL / (NdotL * (1.0 - k) + k);
    return ggxV * ggxL;
}

vec3 cookTorranceSpecular(vec3 N, vec3 V, vec3 L, float roughness, vec3 F0) {
    vec3 H = normalize(V + L);
    float NdotH = max(dot(N, H), 0.0);
    float NdotV = max(dot(N, V), 0.001);
    float NdotL = max(dot(N, L), 0.0);
    float HdotV = max(dot(H, V), 0.0);

    float a = roughness * roughness;
    float a2 = a * a;

    float D = distributionGGX(NdotH, a2);
    float G = geometrySmith(NdotV, NdotL, a2);
    vec3  F = fresnelSchlick(HdotV, F0);

    return (D * G * F) / max(4.0 * NdotV * NdotL, 0.001);
}

// Curvature-based AO (mirrors mesh3d.frag.glsl). Cheap per-fragment
// surrogate for SSAO until a depth-buffer pass lands.
float curvatureAO(vec3 N, float strength) {
    if (strength <= 0.0) return 1.0;
    vec3 ddxN = dFdx(N);
    vec3 ddyN = dFdy(N);
    float curvature = length(ddxN) + length(ddyN);
    float occlusion = smoothstep(0.0, 0.4, curvature);
    return clamp(1.0 - occlusion * strength, 0.0, 1.0);
}

// ACES filmic tonemap (Narkowicz). Matches the tonemap post-process so
// the visual response stays the same whether the HDR/Bloom path is on
// (mesh writes linear, post does ACES) or off (mesh tonemaps inline).
vec3 toneMapACES(vec3 x) {
    const float a = 2.51;
    const float b = 0.03;
    const float c = 2.43;
    const float d = 0.59;
    const float e = 0.14;
    return clamp((x * (a * x + b)) / (x * (c * x + d) + e), 0.0, 1.0);
}

vec3 volumetricScatter(vec3 worldPos) {
    if (u_volumetric_fog == 0 || u_dir_light_count == 0) return vec3(0.0);
    vec3 rayStart = u_camera_pos;
    vec3 rayEnd   = worldPos;
    vec3 rayDir   = rayEnd - rayStart;
    float rayLen  = length(rayDir);
    if (rayLen < 0.01) return vec3(0.0);
    rayDir /= rayLen;
    float marchLen = min(rayLen, u_fog_far);
    const int STEPS = 8;
    float step = marchLen / float(STEPS);
    vec3 sunDir = normalize(-u_dir_lights[0].direction);
    float cosTheta = dot(rayDir, sunDir);
    float phase = 0.5 + pow(max(cosTheta, 0.0), 6.0) * 4.0;
    vec3 scatter = vec3(0.0);
    float transmittance = 1.0;
    for (int i = 0; i < STEPS; i++) {
        vec3 p = rayStart + rayDir * (step * (float(i) + 0.5));
        float density = exp(-max(p.y, 0.0) * 0.08) * 0.06;
        vec3 inscatter = u_dir_lights[0].color * u_dir_lights[0].intensity * phase * density;
        scatter += inscatter * transmittance * step;
        transmittance *= exp(-density * step);
    }
    return scatter;
}

vec3 applyColorGrading(vec3 color) {
    color = color + u_grade_lift;
    color = pow(max(color, vec3(0.0)), vec3(1.0) / u_grade_gamma);
    color = color * u_grade_gain;
    float luma = dot(color, vec3(0.2126, 0.7152, 0.0722));
    return mix(vec3(luma), color, u_grade_saturation);
}

vec3 applyVignette(vec3 color) {
    if (u_vignette_intensity <= 0.0 || u_viewport_size.x <= 0.0) {
        return color;
    }
    vec2 uv = gl_FragCoord.xy / u_viewport_size;
    vec2 d  = uv - 0.5;
    float r = length(d);
    float v = smoothstep(0.45, 0.85, r);
    return color * (1.0 - v * u_vignette_intensity);
}

vec4 outputColor(vec3 color, float alpha) {
    color = max(color, vec3(0.0));
    if (u_linear_output == 0) {
        color = applyColorGrading(color);
        color = toneMapACES(color);
        color = pow(color, vec3(1.0 / 2.2));
        color = applyVignette(color);
    }
    return vec4(color, alpha);
}

// ================================================================
//  Procedural Sand (optimized)
// ================================================================

vec3 computeSand(vec3 N, vec3 V, vec3 L, out float roughOut) {
    // One unified sand layer. The base colour comes from the material's
    // albedo (u_albedo) and is modulated by per-pixel environmental state:
    //
    //   - shore wetness (from terrain UV.x — low = close to water)
    //   - global rain wetness (u_rain_wetness from the weather system)
    //   - cumulative wetness darkens the sand and smooths roughness
    //   - rain-driven puddles open up in low-lying areas
    //   - snow, footprint decals, sun glint stay on top of this base
    //
    // Zone-based colour bands (dry/mid/damp/dune) are gone — everything
    // comes from the environmental state feeding the shader.
    float zone = v_uv.x;

    vec3 baseColor = u_albedo * u_season_tint;

    // Three noise octaves: broad patches, mid texture, individual grains.
    float n1 = fbm2(v_worldPos.xz * 1.5);
    float n2 = noise(v_worldPos.xz * 6.0);
    float n3 = noise(v_worldPos.xz * 28.0);    // grain-scale high frequency
    float n4 = hash21(floor(v_worldPos.xz * 80.0)); // per-pixel speckle

    vec3 sandColor = baseColor;
    sandColor *= 0.82 + n1 * 0.36;                 // broad light/dark patches
    sandColor *= 0.88 + (n2 - 0.5) * 0.22;         // mid-scale variation
    sandColor *= 0.85 + (n3 - 0.5) * 0.30;         // individual grains
    sandColor *= 0.95 + (n4 - 0.5) * 0.12;         // sharp speckle

    // Darker mineral specks sprinkled on top (quartz/feldspar/mica grains).
    float specks = smoothstep(0.82, 0.88, n4);
    sandColor *= 1.0 - specks * 0.35;

    // Crisp wind-ripples — stronger on dunes, faded near water.
    float ripple = sin(v_worldPos.x * 3.5 + v_worldPos.z * 1.8 + n1 * 2.0) * 0.5 + 0.5;
    ripple = smoothstep(0.3, 0.7, ripple);
    sandColor *= 1.0 - ripple * 0.09 * smoothstep(0.35, 0.9, zone);

    // Shore wetness: world-Y driven so ONLY sand at/below the water
    // surface is permanently damp. Intertidal zone (-0.1..+0.1) fades,
    // above +0.1 is bone dry (until rain). Using world Y means dune tops
    // and elevated back-beach stay dry, and only actual water-touching
    // surfaces look wet — regardless of the mesh's UV zone encoding.
    float shoreWet = 1.0 - smoothstep(-0.1, 0.15, v_worldPos.y);
    float wetness = max(shoreWet, u_rain_wetness);

    // Wet sand is darker + warmer-brown. Keep blue channel low.
    vec3 wetTint = baseColor * vec3(0.40, 0.32, 0.20);
    sandColor = mix(sandColor, wetTint, wetness * 0.7);

    // Puddles: low-frequency noise gated by wetness. Flat reflective patches
    // of water tint over wet sand. Only form where wetness is high enough.
    if (wetness > 0.35) {
        float puddleNoise = fbm2(v_worldPos.xz * 0.35);
        float puddleMask = smoothstep(0.52, 0.68, puddleNoise)
                         * smoothstep(0.35, 0.85, wetness);
        // Reflective puddle colour: picks up ambient + sunlight on surface.
        vec3 puddleColor = u_ambient_color * 0.6
                         + u_dir_lights[0].color * u_dir_lights[0].intensity * 0.25
                         + vec3(0.04, 0.07, 0.09);
        sandColor = mix(sandColor, puddleColor, puddleMask * 0.75);
    }

    // Subsurface scattering from low-angle sun (warm halo effect).
    float scatter = pow(max(dot(V, L), 0.0), 4.0) * 0.08;
    sandColor += vec3(0.15, 0.10, 0.04) * scatter * (1.0 - wetness * 0.5);

    // Roughness: dry sand is rough, wet/puddle is smooth (specular).
    roughOut = mix(0.92, 0.20, wetness);

    return sandColor;
}

// ================================================================
//  Procedural Water (optimized — 2 layers instead of 4)
// ================================================================

vec3 computeWater(vec3 N, vec3 V, vec3 L, out float alphaOut, out float roughOut) {
    vec2 uv1 = v_worldPos.xz * 0.8 + u_time * vec2(0.03, 0.02);
    vec2 uv2 = v_worldPos.xz * 2.5 + u_time * vec2(-0.02, 0.04);

    float eps = 0.08;
    float h1a = fbm2(uv1); float h1b = fbm2(uv1 + vec2(eps,0)); float h1c = fbm2(uv1 + vec2(0,eps));
    float h2a = noise(uv2); float h2b = noise(uv2 + vec2(eps,0)); float h2c = noise(uv2 + vec2(0,eps));

    vec3 waveNormal = vec3(0.0, 1.0, 0.0);
    waveNormal.x += (h1a - h1b) * 1.8 + (h2a - h2b) * 0.5;
    waveNormal.z += (h1a - h1c) * 1.8 + (h2a - h2c) * 0.5;
    waveNormal = normalize(waveNormal);

    N = normalize(N + waveNormal * vec3(1.0, 0.0, 1.0));

    float NdotV = max(dot(N, V), 0.0);
    float fresnel = pow(1.0 - NdotV, 5.0);
    fresnel = mix(0.02, 1.0, fresnel);

    float depth = clamp(max(0.0, -8.0 - v_worldPos.z) / 70.0, 0.0, 1.0);

    vec3 waterColor = mix(vec3(0.15, 0.55, 0.50), vec3(0.02, 0.08, 0.15), depth);

    vec3 R = reflect(-V, N);
    float skyBlend = clamp(R.y * 2.0, 0.0, 1.0);
    vec3 reflectColor = mix(u_horizon_color, u_sky_color, skyBlend);
    reflectColor = mix(reflectColor, u_dir_light_color, pow(max(dot(R, L), 0.0), 256.0) * 2.0);

    vec3 finalColor = mix(waterColor, reflectColor, fresnel);

    float specWater = pow(max(dot(N, normalize(V + L)), 0.0), 512.0);
    finalColor += u_dir_light_color * u_dir_light_intensity * specWater * 2.0;

    // Shore foam — multi-layered, animated, finer grain
    float shoreDepth = clamp(max(0.0, -5.0 - v_worldPos.z) / 6.0, 0.0, 1.0);
    float foamLine = smoothstep(0.15, 0.0, shoreDepth);
    float foamNoise = noise(v_worldPos.xz * 15.0 + u_time * 0.3) * 0.5
                    + noise(v_worldPos.xz * 30.0 - u_time * 0.5) * 0.3
                    + noise(v_worldPos.xz * 60.0 + u_time * 0.8) * 0.2;
    float foam = foamLine * smoothstep(0.25, 0.55, foamNoise);
    // Breaking wave foam band
    float waveFoam = smoothstep(0.08, 0.0, shoreDepth) * smoothstep(0.4, 0.7,
        noise(vec2(v_worldPos.x * 3.0, u_time * 0.6)));
    foam = max(foam, waveFoam);
    finalColor = mix(finalColor, vec3(0.92, 0.96, 1.0), foam * 0.8);

    // Alpha: transparent at shore (sand visible), opaque in deep water
    alphaOut = mix(0.15, 0.95, smoothstep(0.0, 0.4, depth));
    alphaOut = mix(alphaOut, 1.0, foam * 0.6);

    // Fade out at shore edge (where water meets sand) based on world Z
    float shoreEdge = smoothstep(-5.0, -7.0, v_worldPos.z);
    alphaOut *= shoreEdge;

    roughOut = mix(0.02, 0.08, foam);

    return finalColor;
}

// ================================================================
//  Procedural Rock (optimized)
// ================================================================

vec3 computeRock(vec3 N, vec3 worldPos, vec3 baseAlbedo, out float roughOut) {
    vec3 p = worldPos * 2.5;
    float n1 = fbm2(p.xz);

    vec3 rockColor = baseAlbedo * (0.85 + n1 * 0.3);

    float crack = noise(p.xz * 8.0 + vec2(p.y * 2.0));
    rockColor *= 1.0 - smoothstep(0.48, 0.52, crack) * 0.15;

    float strata = sin(worldPos.y * 15.0 + n1 * 3.0) * 0.5 + 0.5;
    rockColor *= 0.95 + smoothstep(0.4, 0.6, strata) * 0.1;

    roughOut = 0.75 + noise(p.xz * 3.0) * 0.2;

    return rockColor;
}

// ================================================================
//  Procedural Palm Trunk (optimized)
// ================================================================

vec3 computePalmTrunk(vec3 worldPos, vec3 baseAlbedo, out float roughOut) {
    // Rings and fiber are locked to the cylinder's local axis via v_uv:
    // uv.x = angle around trunk (0..1), uv.y = height along segment (0..1).
    // This keeps scars perpendicular to the trunk even when the trunk leans
    // or curves, and stops the fiber noise from sliding when the trunk sways.
    float ring = smoothstep(0.3, 0.7, sin(v_uv.y * 6.2831 * 1.2) * 0.5 + 0.5);
    float fiber = noise(vec2(v_uv.x * 20.0, v_uv.y * 4.0));

    vec3 barkColor = mix(baseAlbedo * 0.65, baseAlbedo * 1.2, ring * 0.6 + fiber * 0.4);
    barkColor *= 0.85 + ring * 0.3;

    // Weathering still uses world-space so neighbouring trunks differ.
    float weather = noise(worldPos.xz * 5.0);
    barkColor = mix(barkColor, barkColor * vec3(0.85, 0.9, 0.8), weather * 0.2);

    roughOut = 0.85 + ring * 0.1;
    return barkColor;
}

// ================================================================
//  Procedural Palm Leaf (optimized)
// ================================================================

vec3 computePalmLeaf(vec3 worldPos, vec3 N, vec3 V, vec3 L, vec3 baseAlbedo, out float roughOut) {
    // PalmFrondMesh UVs:
    //   uv.y = distance along the frond (0 at rachis base → 1 at tip)
    //   uv.x = sideways from spine (0.5 on spine, ±0.5 at leaflet tips)
    // Using UV locks the pattern to the leaf geometry even when the frond
    // rotates and sways in the wind.
    float sideways = (v_uv.x - 0.5) * 2.0; // -1..1

    // Veins run outward from the spine (parallel to leaflet length). Dense
    // stripes along uv.x direction.
    float vein = smoothstep(0.0, 0.15, abs(sin(sideways * 18.0)));

    // Base variation — broad patches that follow the leaf surface.
    float n = fbm2(v_uv * 8.0);
    vec3 leafColor = baseAlbedo * (0.8 + n * 0.4);
    leafColor = mix(leafColor * 1.3, leafColor, vein);

    // Age gradient: young green at base, yellow/brown at tips.
    vec3 tipTint = vec3(0.55, 0.45, 0.18);
    float age = smoothstep(0.6, 1.0, v_uv.y);
    leafColor = mix(leafColor, leafColor * tipTint * 1.4, age * 0.35);

    // Edge darkening — strongest near leaflet outer edges (|sideways| ≈ 1).
    float edgeNoise = noise(v_uv * 12.0);
    float edgeMask = smoothstep(0.6, 1.0, abs(sideways));
    leafColor = mix(leafColor, vec3(0.4, 0.35, 0.15), edgeMask * edgeNoise * 0.25);

    // Translucent back-lighting when sun shines through the leaf.
    leafColor += vec3(0.1, 0.2, 0.02) * pow(max(dot(-N, L), 0.0), 2.0) * 0.3;
    leafColor += vec3(0.05, 0.1, 0.02) * pow(max(dot(V, L), 0.0), 3.0) * 0.1;

    roughOut = 0.6 + edgeNoise * 0.15;
    return leafColor;
}

// ================================================================
//  Procedural Wood Planks (optimized)
// ================================================================

vec3 computeWoodPlanks(vec3 N, vec3 worldPos, vec3 baseAlbedo, out float roughOut) {
    // Plank orientation follows the face's local normal so planks rotate with
    // the mesh and adapt to floors/walls/ceilings automatically:
    //   - horizontal face (|N.y| dominant) → planks laid flat, rows stacked in Z
    //   - wall facing X (|N.x| dominant)   → planks stacked vertically, grain along Z
    //   - wall facing Z (|N.z| dominant)   → planks stacked vertically, grain along X
    //
    // Local-space coordinates are scaled back to world distance via v_objectScale
    // so the plank density stays consistent across differently scaled meshes.
    vec3 scaledLocal = v_localPos * v_objectScale;
    vec3 absN = abs(v_localNormal);

    float plankCoord;
    float grainCoord;
    if (absN.y > absN.x && absN.y > absN.z) {
        plankCoord = scaledLocal.z * 6.5;
        grainCoord = scaledLocal.x * 8.0;
    } else if (absN.x >= absN.z) {
        plankCoord = scaledLocal.y * 6.5;
        grainCoord = scaledLocal.z * 8.0;
    } else {
        plankCoord = scaledLocal.y * 6.5;
        grainCoord = scaledLocal.x * 8.0;
    }

    float plankIndex = floor(plankCoord);
    float withinPlank = fract(plankCoord);

    float gap = smoothstep(0.0, 0.03, withinPlank) * smoothstep(1.0, 0.97, withinPlank);
    float plankHash = hash21(vec2(plankIndex * 17.3, plankIndex * 7.1));

    vec3 woodColor = baseAlbedo * (0.8 + plankHash * 0.4);

    float offsetGrain = grainCoord + plankHash * 20.0;
    float grain = sin(offsetGrain + noise(vec2(offsetGrain * 0.5, plankIndex)) * 3.0) * 0.5 + 0.5;
    woodColor *= 0.9 + grain * 0.15;

    woodColor *= gap * 0.85 + 0.15;
    // Broad colour variation in world space — keeps neighbouring panels from looking identical.
    woodColor *= 0.85 + noise(worldPos.xz * 3.0 + worldPos.y * 2.0) * 0.2;

    roughOut = 0.78 + plankHash * 0.15;
    return woodColor;
}

// ================================================================
//  Procedural Thatch (optimized)
// ================================================================

vec3 computeThatch(vec3 N, vec3 worldPos, vec3 baseAlbedo, out float roughOut) {
    // Strand direction is locked to the local mesh: fibres always run along
    // the roof's local X axis (down-slope), regardless of the hut's yaw.
    // Using local-space × object-scale keeps the density consistent when the
    // roof is scaled non-uniformly.
    vec3 scaledLocal = v_localPos * v_objectScale;
    float strandAngle = scaledLocal.x * 12.0 + scaledLocal.z * 6.0 + scaledLocal.y * 4.0;
    float strand1 = sin(strandAngle) * 0.5 + 0.5;

    vec3 strawColor = baseAlbedo * (0.75 + fbm2(scaledLocal.xz * 5.0 + scaledLocal.y * 3.0) * 0.5);
    strawColor += vec3(0.1, 0.08, 0.02) * pow(strand1, 8.0);

    // Weathering varies in world space so adjacent thatch panels look different.
    float age = noise(worldPos.xz * 8.0);
    strawColor = mix(strawColor, strawColor * 0.6, smoothstep(0.7, 0.9, age) * 0.4);

    roughOut = 0.92;
    return strawColor;
}

// ================================================================
//  Procedural Cloud (optimized)
// ================================================================

vec3 computeCloud(vec3 N, vec3 V, vec3 L, vec3 baseAlbedo, out float alphaOut) {
    float NdotL = max(dot(N, L), 0.0);
    vec3 cloudColor = mix(vec3(0.6, 0.65, 0.72), vec3(1.0, 0.98, 0.95), NdotL * 0.7 + 0.3);

    float scatter = pow(max(dot(V, L), 0.0), 3.0);
    cloudColor += vec3(0.3, 0.25, 0.15) * scatter * 0.4;
    cloudColor += vec3(0.5, 0.5, 0.4) * pow(1.0 - max(dot(N, V), 0.0), 3.0) * scatter * 0.6;

    cloudColor *= 0.9 + noise(v_worldPos.xz * 0.3) * 0.2;

    alphaOut = pow(max(dot(N, V), 0.0), 0.8) * 0.85;
    return cloudColor;
}

// ================================================================
//  Procedural Normal Maps (mirrors mesh3d.frag.glsl)
// ================================================================

vec3 np_bricks(vec2 uv) {
    vec2 cell = vec2(0.5, 1.0);
    float rowIndex = floor(uv.y / cell.y);
    float xOffset = mod(rowIndex, 2.0) * 0.5 * cell.x;
    vec2 local = vec2(fract((uv.x + xOffset) / cell.x),
                      fract(uv.y / cell.y));
    float mortarX = 1.0 - (smoothstep(0.0, 0.06, local.x) *
                           smoothstep(1.0, 0.94, local.x));
    float mortarY = 1.0 - (smoothstep(0.0, 0.06, local.y) *
                           smoothstep(1.0, 0.94, local.y));
    float groove = max(mortarX, mortarY);
    vec2 slope = vec2(mortarX, mortarY) *
                 vec2(local.x < 0.5 ? 1.0 : -1.0,
                      local.y < 0.5 ? 1.0 : -1.0);
    return normalize(vec3(slope * 0.6, 1.0 - groove * 0.5));
}

vec3 np_bumps(vec2 uv) {
    float e = 0.05;
    float h  = noise(uv * 8.0);
    float hx = noise(uv * 8.0 + vec2(e, 0.0));
    float hy = noise(uv * 8.0 + vec2(0.0, e));
    vec2 grad = vec2(hx - h, hy - h) / e;
    return normalize(vec3(-grad * 0.4, 1.0));
}

vec3 np_orange_peel(vec2 uv) {
    vec2 p = uv * 60.0;
    float h  = hash21(floor(p));
    float hx = hash21(floor(p) + vec2(1.0, 0.0));
    float hy = hash21(floor(p) + vec2(0.0, 1.0));
    return normalize(vec3((h - hx) * 0.6, (h - hy) * 0.6, 1.0));
}

vec3 np_hammered(vec2 uv) {
    vec2 grid = uv * 6.0;
    vec2 cell = floor(grid);
    vec2 local = fract(grid) - 0.5;
    vec2 jitter = vec2(hash21(cell), hash21(cell + 17.0)) - 0.5;
    vec2 centred = local - jitter * 0.4;
    float r = length(centred);
    float rim = smoothstep(0.45, 0.20, r);
    vec2 slope = -centred * rim * 1.4;
    return normalize(vec3(slope, 1.0));
}

vec3 np_hexagons(vec2 uv) {
    vec2 p = uv * 5.0;
    vec2 a = vec2(p.x + p.y * 0.5, p.y * 0.866);
    vec2 af = fract(a) - 0.5;
    vec2 slope = -af * 1.2;
    float edge = smoothstep(0.45, 0.50, max(abs(af.x), abs(af.y)));
    return normalize(vec3(slope * (1.0 - edge), 1.0 - edge * 0.4));
}

vec3 np_wood_grain(vec2 uv) {
    float grad = cos(uv.y * 80.0 + noise(uv * vec2(20.0, 4.0)) * 6.0) * 80.0;
    float slopeY = grad * 0.005;
    return normalize(vec3(0.0, slopeY, 1.0));
}

vec3 np_scratches(vec2 uv) {
    float rotated = uv.x * 0.97 + uv.y * 0.24;
    float across  = -uv.x * 0.24 + uv.y * 0.97;
    float lane = floor(across * 80.0);
    float laneJitter = hash21(vec2(lane, 0.0));
    float scratch = sin((rotated + laneJitter * 6.28) * 30.0);
    float mask = step(0.6, hash21(vec2(lane, 13.0)));
    return normalize(vec3(scratch * mask * 0.5, 0.0, 1.0));
}

vec3 np_cracked(vec2 uv) {
    vec2 p = uv * 8.0;
    vec2 ip = floor(p);
    vec2 fp = fract(p);
    float d1 = 8.0;
    float d2 = 8.0;
    for (int x = -1; x <= 1; x++) {
        for (int y = -1; y <= 1; y++) {
            vec2 g = vec2(float(x), float(y));
            vec2 jitter = vec2(hash21(ip + g),
                               hash21(ip + g + 51.0));
            float d = length(g + jitter - fp);
            if (d < d1) { d2 = d1; d1 = d; }
            else if (d < d2) { d2 = d; }
        }
    }
    float crack = smoothstep(0.04, 0.0, d2 - d1);
    return normalize(vec3(0.0, 0.0, 1.0) +
                     vec3((fp.x - 0.5) * crack, (fp.y - 0.5) * crack, 0.0));
}

vec3 np_noise_pattern(vec2 uv) {
    float e = 0.04;
    float h  = fbm3(uv * 6.0);
    float hx = fbm3(uv * 6.0 + vec2(e, 0.0));
    float hy = fbm3(uv * 6.0 + vec2(0.0, e));
    vec2 grad = vec2(hx - h, hy - h) / e;
    return normalize(vec3(-grad * 0.5, 1.0));
}

vec3 dispatchProceduralNormal(int code, vec2 uv) {
    if (code == 1) return np_bricks(uv);
    if (code == 2) return np_bumps(uv);
    if (code == 3) return np_orange_peel(uv);
    if (code == 4) return np_hammered(uv);
    if (code == 5) return np_hexagons(uv);
    if (code == 6) return np_wood_grain(uv);
    if (code == 7) return np_scratches(uv);
    if (code == 8) return np_cracked(uv);
    if (code == 9) return np_noise_pattern(uv);
    return vec3(0.0, 0.0, 1.0);
}

// ── Procedural Surface-Wear (mirrors mesh3d.frag.glsl) ────────────────────────

vec3 sp_worn_paint(vec2 uv) {
    float wear = fbm3(uv * 3.0);
    float chip = step(0.55, wear);
    float albedoT = mix(0.50, 0.30, chip);
    float roughD  = mix(0.0,  0.35,  chip);
    float metalD  = mix(0.0,  0.55,  chip);
    return vec3(albedoT, roughD, metalD);
}

vec3 sp_rust(vec2 uv) {
    float spotty = fbm3(uv * 5.0);
    float rust   = smoothstep(0.45, 0.65, spotty);
    float albedoT = mix(0.50, 0.62, rust);
    float roughD  = mix(0.0,  0.45,  rust);
    float metalD  = mix(0.0, -0.50,  rust);
    return vec3(albedoT, roughD, metalD);
}

vec3 sp_brushed_metal(vec2 uv) {
    float lane = sin(uv.y * 600.0);
    return vec3(0.50, lane * 0.10, 0.0);
}

vec3 sp_polished_rings(vec2 uv) {
    vec2 c = uv - 0.5;
    float r = length(c);
    float ring = sin(r * 80.0);
    float matte = smoothstep(0.0, 0.4, ring);
    return vec3(0.50, matte * 0.50 - 0.10, 0.0);
}

vec3 dispatchSurfacePattern(int code, vec2 uv) {
    if (code == 1) return sp_worn_paint(uv);
    if (code == 2) return sp_rust(uv);
    if (code == 3) return sp_brushed_metal(uv);
    if (code == 4) return sp_polished_rings(uv);
    return vec3(0.5, 0.0, 0.0);
}

vec3 perturbNormalProcedural(vec3 N, vec3 worldPos, vec2 uv,
                             int patternCode, float patternScale,
                             float intensity) {
    if (patternCode == 0 || intensity <= 0.0) return N;
    vec3 dpx = dFdx(worldPos);
    vec3 dpy = dFdy(worldPos);
    vec2 duvx = dFdx(uv);
    vec2 duvy = dFdy(uv);
    float det = duvx.x * duvy.y - duvy.x * duvx.y;
    if (abs(det) < 1e-8) return N;
    vec3 T = (dpx * duvy.y - dpy * duvx.y) / det;
    T = normalize(T - N * dot(N, T));
    vec3 B = normalize(cross(N, T));
    mat3 TBN = mat3(T, B, N);

    vec3 nMap = dispatchProceduralNormal(patternCode, uv * patternScale);
    nMap = mix(vec3(0.0, 0.0, 1.0), nMap, clamp(intensity, 0.0, 4.0));
    return normalize(TBN * nMap);
}

// ================================================================
//  Main
// ================================================================

void main() {
    vec3 N = normalize(v_normal);
    // View-facing normal for specular/fresnel (flipped for back faces)
    vec3 Nv = gl_FrontFacing ? N : -N;

    vec3 V = normalize(u_camera_pos - v_worldPos);
    vec3 L = normalize(-u_dir_light_direction);

    float roughness = clamp(u_roughness, 0.04, 1.0);
    float metallic  = u_metallic;
    float alpha = u_alpha;
    vec3 albedo;

    vec3 texAlbedo = u_albedo;
    if (u_has_albedo_texture == 1) {
        texAlbedo *= texture(u_albedo_texture, v_uv).rgb;
    }

    // ---- Material selection ----
    if (u_proc_mode == 2) {
        albedo = computeWater(N, V, L, alpha, roughness);
        float fd = length(v_worldPos - u_camera_pos);
        float ff = clamp((fd - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
        frag_color = outputColor(mix(albedo, u_fog_color, 1.0 - exp(-ff*ff*3.0)), alpha);
        return;
    } else if (u_proc_mode == 1) {
        albedo = computeSand(N, V, L, roughness);
    } else if (u_proc_mode == 3) {
        albedo = computeRock(N, v_worldPos, u_albedo, roughness);
    } else if (u_proc_mode == 4) {
        albedo = computePalmTrunk(v_worldPos, u_albedo, roughness);
    } else if (u_proc_mode == 5) {
        albedo = computePalmLeaf(v_worldPos, N, V, L, u_albedo, roughness);
    } else if (u_proc_mode == 7) {
        albedo = computeWoodPlanks(N, v_worldPos, u_albedo, roughness);
    } else if (u_proc_mode == 8) {
        albedo = computeThatch(N, v_worldPos, u_albedo, roughness);
    } else if (u_proc_mode == 6) {
        albedo = computeCloud(N, V, L, u_albedo, alpha);
        float fd = length(v_worldPos - u_camera_pos);
        float ff = clamp((fd - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
        frag_color = outputColor(mix(albedo, u_fog_color, 1.0 - exp(-ff*ff*3.0)), alpha);
        return;
    } else if (u_proc_mode == 9) {
        vec3 moonN = normalize(N);
        vec3 vUp = abs(V.y) > 0.99 ? vec3(0.0, 0.0, 1.0) : vec3(0.0, 1.0, 0.0);
        float localX = dot(moonN, normalize(cross(V, vUp)));
        float tp = cos(u_moon_phase * 6.28318);
        float illum = smoothstep(tp - 0.12, tp + 0.12, localX);
        float crater = noise(moonN.xz * 4.0 + moonN.y * 2.0);
        vec3 mc = vec3(0.85, 0.87, 0.92) * (1.0 - smoothstep(0.42, 0.55, crater) * 0.25);
        frag_color = outputColor(mc * illum + vec3(0.02, 0.025, 0.04) * (1.0 - illum), 1.0);
        return;
    } else if (u_proc_mode == 10) {
        // Carpaint: metallic flake micro-normal + per-fragment colour wash.
        float nse = noise(v_worldPos.xz * 0.4);
        albedo = texAlbedo * (1.0 + (nse - 0.5) * 0.04);
        if (u_flakes > 0.0) {
            vec3 flakePos = floor(v_worldPos * 220.0);
            float h1 = hash31(flakePos);
            float h2 = hash31(flakePos + vec3(13.0, 7.0, 5.0));
            float h3 = hash31(flakePos + vec3(31.0, 17.0, 11.0));
            vec3 jitter = vec3(h1 - 0.5, h2 - 0.5, h3 - 0.5);
            N = normalize(N + jitter * 0.18 * u_flakes * u_normal_intensity);
        }
    } else {
        float nse = noise(v_worldPos.xz * 0.4);
        float noiseMask = smoothstep(0.3, 0.9, roughness);
        albedo = texAlbedo * (1.0 + (nse - 0.5) * 0.12 * noiseMask);
    }

    // Procedural normal-map pattern (mirrors mesh3d.frag.glsl). Self-shading
    // procedural materials (water, cloud, moon) have early-returned above so
    // this only affects standard, sand, rock, palm, wood, thatch, carpaint.
    N = perturbNormalProcedural(N, v_worldPos, v_uv,
                                u_normal_pattern, u_normal_scale,
                                u_normal_intensity);

    if (u_surface_pattern > 0 && u_surface_intensity > 0.0) {
        vec3 wear = dispatchSurfacePattern(u_surface_pattern, v_uv * u_surface_scale);
        float t = clamp(u_surface_intensity, 0.0, 4.0);
        vec3 tint = mix(vec3(1.0), vec3(wear.x * 2.0), t);
        albedo *= tint;
        roughness = clamp(roughness + wear.y * t, 0.04, 1.0);
        metallic  = clamp(metallic  + wear.z * t, 0.0,  1.0);
    }

    // Per-material wetness (SSR surrogate). Up-facing fragments get a
    // smoother + darker + brighter-IBL pass to read as wet/polished.
    if (u_wetness > 0.0) {
        float upMask = clamp(dot(N, vec3(0.0, 1.0, 0.0)) * 1.4 - 0.2, 0.0, 1.0);
        float w = u_wetness * upMask;
        roughness = mix(roughness, max(roughness * 0.25, 0.04), w);
        albedo    = mix(albedo,    albedo * 0.7,                 w);
    }

    // ---- Snow cover: upward-facing surfaces turn white ----
    if (u_snow_cover > 0.01 && u_proc_mode != 2 && u_proc_mode != 6) {
        float upFacing = max(dot(N, vec3(0.0, 1.0, 0.0)), 0.0);
        // Snow sticks more on flat/upward surfaces, less on steep sides
        float snowMask = smoothstep(0.3, 0.7, upFacing) * u_snow_cover;
        // Add noise for natural patchy edges
        snowMask *= 0.7 + noise(v_worldPos.xz * 2.0) * 0.3;
        snowMask = clamp(snowMask, 0.0, 1.0);
        albedo = mix(albedo, vec3(0.92, 0.93, 0.97), snowMask);
        roughness = mix(roughness, 0.8, snowMask); // snow is matte
    }

    // ---- PBR Lighting (Cook-Torrance GGX) ----
    roughness = clamp(roughness, 0.04, 1.0);
    vec3 F0 = mix(vec3(0.04), albedo, metallic);
    float NdotV = max(dot(N, V), 0.001);
    float shadow = calcShadow(v_lightSpacePos, N);

    float primaryIntensity = u_dir_light_count > 0 ? u_dir_lights[0].intensity : 0.0;
    float ambientShadow = mix(1.0, mix(0.5, 1.0, shadow), clamp(primaryIntensity, 0.0, 1.0));
    vec3 F_ambient = fresnelSchlick(NdotV, F0);
    vec3 kD_ambient = (1.0 - F_ambient) * (1.0 - metallic);
    float ao = curvatureAO(N, u_ao_strength);
    vec3 color = u_ambient_color * u_ambient_intensity * albedo * kD_ambient * ambientShadow * ao;

    for (int dl = 0; dl < u_dir_light_count; dl++) {
        vec3 dL = normalize(-u_dir_lights[dl].direction);
        float dNdotL = max(dot(N, dL), 0.0);
        float dShadow = (dl == 0) ? shadow : 1.0;

        if (dNdotL > 0.0) {
            vec3 spec = cookTorranceSpecular(N, V, dL, roughness, F0);
            vec3 F = fresnelSchlick(max(dot(normalize(V + dL), V), 0.0), F0);
            vec3 kD = (1.0 - F) * (1.0 - metallic);

            vec3 radiance = u_dir_lights[dl].color * u_dir_lights[dl].intensity;
            color += (kD * albedo / 3.14159265 + spec) * radiance * dNdotL * dShadow;
        }
    }

    for (int i = 0; i < u_point_light_count; i++) {
        vec3 Lp = u_point_lights[i].position - v_worldPos;
        float dist = length(Lp);
        Lp /= dist;
        float r = max(u_point_lights[i].radius, 0.001);
        float atten = clamp(1.0 - dist*dist/(r*r), 0.0, 1.0);
        atten *= atten;
        float NdotPL = max(dot(N, Lp), 0.0);
        if (NdotPL > 0.0) {
            vec3 spec = cookTorranceSpecular(N, V, Lp, roughness, F0);
            vec3 F = fresnelSchlick(max(dot(normalize(V + Lp), V), 0.0), F0);
            vec3 kD = (1.0 - F) * (1.0 - metallic);

            vec3 radiance = u_point_lights[i].color * u_point_lights[i].intensity * atten;
            color += (kD * albedo / 3.14159265 + spec) * radiance * NdotPL;
        }
    }

    // ---- Clearcoat lobe (carpaint, dielectric F0 ≈ 0.04) ----
    if (u_clearcoat > 0.0 && u_dir_light_count > 0) {
        float ccRough = clamp(u_clearcoat_roughness, 0.02, 1.0);
        vec3 ccF0 = vec3(0.04);
        vec3 ccL = normalize(-u_dir_lights[0].direction);
        float ccNdotL = max(dot(N, ccL), 0.0);
        if (ccNdotL > 0.0) {
            vec3 ccSpec = cookTorranceSpecular(N, V, ccL, ccRough, ccF0);
            color += ccSpec * u_dir_lights[0].color * u_dir_lights[0].intensity
                   * ccNdotL * shadow * u_clearcoat;
        }
        // Sky-tint pseudo-IBL when no cubemap binding is available in
        // this backend: blend horizon/sky based on the reflection vector
        // and modulate by clearcoat roughness.
        vec3 ccR = reflect(-V, N);
        float skyBlend = clamp(ccR.y * 2.0, 0.0, 1.0);
        vec3 ccEnv = mix(u_horizon_color, u_sky_color, skyBlend);
        vec3 ccFres = fresnelSchlick(NdotV, ccF0);
        color += ccEnv * ccFres * u_clearcoat * (1.0 - ccRough * 0.5) * 0.4;
    }

    color += u_emission;

    float fogDist = length(v_worldPos - u_camera_pos);
    float fogFactor = clamp((fogDist - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
    color = mix(color, u_fog_color, 1.0 - exp(-fogFactor * fogFactor * 3.0));

    color += volumetricScatter(v_worldPos);

    frag_color = outputColor(color, alpha);
}
GLSL;

    private const UNLIT_VERT = <<<'GLSL'
#version 410 core

layout(location = 0) in vec3 a_position;
layout(location = 1) in vec3 a_normal;
layout(location = 2) in vec2 a_uv;

uniform mat4 u_model;
uniform mat4 u_view;
uniform mat4 u_projection;

out vec3 v_worldPos;
out vec2 v_uv;

void main() {
    vec4 worldPos = u_model * vec4(a_position, 1.0);
    v_worldPos = worldPos.xyz;
    v_uv = a_uv;
    gl_Position = u_projection * u_view * worldPos;
}
GLSL;

    private const UNLIT_FRAG = <<<'GLSL'
#version 410 core

in vec3 v_worldPos;
in vec2 v_uv;

uniform vec3 u_albedo;
uniform vec3 u_emission;
uniform float u_alpha;
uniform vec3 u_fog_color;
uniform float u_fog_near;
uniform float u_fog_far;
uniform vec3 u_camera_pos;

uniform int u_has_albedo_texture;
uniform sampler2D u_albedo_texture;

out vec4 frag_color;

void main() {
    vec3 baseAlbedo = u_albedo;
    if (u_has_albedo_texture == 1) {
        vec4 texColor = texture(u_albedo_texture, v_uv);
        baseAlbedo *= texColor.rgb;
    }
    vec3 color = baseAlbedo + u_emission;
    float dist = length(v_worldPos - u_camera_pos);
    float fogFactor = clamp((dist - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
    color = mix(color, u_fog_color, fogFactor);
    frag_color = vec4(color, u_alpha);
}
GLSL;

    private const SHADOW_VERT = <<<'GLSL'
#version 410 core

layout(location = 0) in vec3 a_position;
layout(location = 1) in vec3 a_normal;
layout(location = 2) in vec2 a_uv;
layout(location = 3) in vec4 a_instance_col0;
layout(location = 4) in vec4 a_instance_col1;
layout(location = 5) in vec4 a_instance_col2;
layout(location = 6) in vec4 a_instance_col3;

uniform mat4 u_model;
uniform mat4 u_view;
uniform mat4 u_projection;
uniform int  u_use_instancing;

void main() {
    mat4 model = u_model;
    if (u_use_instancing == 1) {
        model = mat4(a_instance_col0, a_instance_col1, a_instance_col2, a_instance_col3);
    }
    gl_Position = u_projection * u_view * model * vec4(a_position, 1.0);
}
GLSL;

    private const SHADOW_FRAG = <<<'GLSL'
#version 410 core

out vec4 frag_color;

void main() {
    frag_color = vec4(gl_FragCoord.z, gl_FragCoord.z, gl_FragCoord.z, 1.0);
}
GLSL;

    private const DEPTH_VERT = <<<'GLSL'
#version 410 core

layout(location = 0) in vec3 a_position;
layout(location = 1) in vec3 a_normal;
layout(location = 2) in vec2 a_uv;
layout(location = 3) in vec4 a_instance_col0;
layout(location = 4) in vec4 a_instance_col1;
layout(location = 5) in vec4 a_instance_col2;
layout(location = 6) in vec4 a_instance_col3;

uniform mat4 u_model;
uniform mat4 u_view;
uniform mat4 u_projection;
uniform int  u_use_instancing;

void main() {
    mat4 model = u_model;
    if (u_use_instancing == 1) {
        model = mat4(a_instance_col0, a_instance_col1, a_instance_col2, a_instance_col3);
    }
    gl_Position = u_projection * u_view * model * vec4(a_position, 1.0);
}
GLSL;

    private const DEPTH_FRAG = <<<'GLSL'
#version 410 core

uniform float u_fog_near;
uniform float u_fog_far;

out vec4 frag_color;

void main() {
    float z = gl_FragCoord.z;
    float near = max(u_fog_near, 0.1);
    float far = max(u_fog_far, near + 1.0);
    float linearDepth = (2.0 * near * far) / (far + near - z * (far - near));
    float normalized = (linearDepth - near) / (far - near);

    vec3 color = vec3(1.0 - clamp(normalized, 0.0, 1.0));
    frag_color = vec4(color, 1.0);
}
GLSL;

    private const NORMALS_VERT = <<<'GLSL'
#version 410 core

layout(location = 0) in vec3 a_position;
layout(location = 1) in vec3 a_normal;
layout(location = 2) in vec2 a_uv;

uniform mat4 u_model;
uniform mat4 u_view;
uniform mat4 u_projection;
uniform mat3 u_normal_matrix;
uniform int  u_use_instancing;

out vec3 v_normal;

void main() {
    bool isZero = (u_normal_matrix[0] == vec3(0.0) &&
                   u_normal_matrix[1] == vec3(0.0) &&
                   u_normal_matrix[2] == vec3(0.0));
    if (isZero) {
        v_normal = mat3(transpose(inverse(u_model))) * a_normal;
    } else {
        v_normal = u_normal_matrix * a_normal;
    }

    gl_Position = u_projection * u_view * u_model * vec4(a_position, 1.0);
}
GLSL;

    private const NORMALS_FRAG = <<<'GLSL'
#version 410 core

in vec3 v_normal;

out vec4 frag_color;

void main() {
    vec3 color = normalize(v_normal) * 0.5 + 0.5;
    frag_color = vec4(color, 1.0);
}
GLSL;

    // Atmospheric sky — fullscreen quad that reconstructs a world-space
    // view ray per pixel and evaluates a gradient + sun disc directly.
    // No skybox geometry involved. Rendered before opaque with depth test
    // OFF; opaque geometry overwrites whatever it touches.
    private const ATMOSPHERE_VERT = <<<'GLSL'
#version 410 core
layout(location = 0) in vec3 a_position;
layout(location = 1) in vec2 a_uv;
out vec2 v_ndc;
void main() {
    // Full clip-space quad at z = 1 (far plane). Depth test is disabled so
    // the actual depth value doesn't matter, but writing z = 1 keeps the
    // sky behind anything that uses this depth buffer later.
    gl_Position = vec4(a_position.xy, 1.0, 1.0);
    v_ndc = a_position.xy;
}
GLSL;

    private const ATMOSPHERE_FRAG = <<<'GLSL'
#version 410 core
in vec2 v_ndc;

uniform mat4 u_sky_inv_vp;        // inverse(projection * view_without_translation)
uniform vec3 u_camera_pos;        // for cloud-plane ray intersection
uniform vec3 u_sun_direction;     // normalized, toward the sun
uniform vec3 u_sun_color;
uniform float u_sun_intensity;
uniform vec3 u_zenith_color;
uniform vec3 u_horizon_color;
uniform vec3 u_ground_color;
uniform float u_sun_size;         // angular radius of the sun disc (rad)
uniform float u_sun_glow_size;    // angular extent of the glow halo (rad)
uniform float u_sun_glow_intensity;
uniform vec3 u_moon_direction;
uniform vec3 u_moon_color;
uniform float u_moon_intensity;
uniform float u_star_brightness;

// Cloud layer
uniform float u_cloud_cover;      // 0..1 — fraction of sky with clouds
uniform float u_cloud_altitude;   // world-Y of the cloud plane
uniform float u_cloud_density;    // 0..1 — contrast / opacity of clouds
uniform float u_cloud_wind_speed; // world units / sec
uniform vec2  u_cloud_wind_dir;   // normalized XZ wind direction

// Horizon haze / humidity fog
uniform float u_fog_density;      // 0..1

uniform float u_time;

out vec4 frag_color;

float smoothstep01(float e0, float e1, float x) {
    float t = clamp((x - e0) / (e1 - e0), 0.0, 1.0);
    return t * t * (3.0 - 2.0 * t);
}

// Small hash used for twinkly starfield — no texture needed.
float hash31(vec3 p) {
    return fract(sin(dot(p, vec3(443.897, 441.423, 437.195))) * 43758.5453);
}

// 2D value noise for cloud shaping.
float hash21(vec2 p) {
    return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453);
}

float noise2d(vec2 p) {
    vec2 i = floor(p);
    vec2 f = fract(p);
    vec2 u = f * f * (3.0 - 2.0 * f);
    return mix(
        mix(hash21(i),                hash21(i + vec2(1.0, 0.0)), u.x),
        mix(hash21(i + vec2(0.0, 1.0)), hash21(i + vec2(1.0, 1.0)), u.x),
        u.y
    );
}

float fbm2(vec2 p) {
    float total = 0.0;
    float amp = 0.5;
    for (int i = 0; i < 4; i++) {
        total += noise2d(p) * amp;
        p *= 2.07;
        amp *= 0.5;
    }
    return total;
}

void main() {
    // Reconstruct the world-space view direction for this pixel.
    vec4 world = u_sky_inv_vp * vec4(v_ndc, 1.0, 1.0);
    vec3 dir = normalize(world.xyz / world.w);

    float elevation = dir.y;

    // Base gradient: horizon toward zenith above, toward ground below.
    vec3 color;
    if (elevation >= 0.0) {
        float t = smoothstep01(0.0, 1.0, elevation);
        color = mix(u_horizon_color, u_zenith_color, t);
    } else {
        float t = smoothstep01(0.0, -0.3, elevation);
        color = mix(u_horizon_color, u_ground_color, t);
    }

    // Sun disc + glow halo + horizon scatter.
    if (u_sun_intensity > 0.0) {
        float cosA = dot(dir, u_sun_direction);
        float angle = acos(clamp(cosA, -1.0, 1.0));

        // Soft sun disc
        float disc = 1.0 - smoothstep01(u_sun_size * 0.5, u_sun_size, angle);
        color = mix(color, u_sun_color * u_sun_intensity, disc);

        // Glow halo
        if (angle < u_sun_glow_size) {
            float g = 1.0 - angle / u_sun_glow_size;
            g = g * g * u_sun_glow_intensity;
            color += u_sun_color * u_sun_intensity * g;
        }

        // Warm horizon scattering near the sun direction (sunset band).
        if (elevation > -0.05 && elevation < 0.25) {
            float band = 1.0 - abs(elevation - 0.05) / 0.20;
            band = max(0.0, band);
            float s = max(0.0, cosA) * band * 0.35 * u_sun_intensity;
            color += u_sun_color * s;
        }
    }

    // Moon (below-horizon sun opposite): faint disc + soft cool glow.
    if (u_moon_intensity > 0.0) {
        float cosM = dot(dir, u_moon_direction);
        float angle = acos(clamp(cosM, -1.0, 1.0));
        float disc = 1.0 - smoothstep01(u_sun_size * 0.7, u_sun_size * 1.4, angle);
        color = mix(color, u_moon_color * u_moon_intensity, disc);
        if (angle < u_sun_glow_size * 0.6) {
            float g = 1.0 - angle / (u_sun_glow_size * 0.6);
            g = g * g * 0.35 * u_moon_intensity;
            color += u_moon_color * g;
        }
    }

    // Stars — only above the horizon and only when bright enough.
    if (u_star_brightness > 0.0 && elevation > 0.0) {
        vec3 cell = floor(dir * 200.0);
        float n = hash31(cell);
        if (n > 0.9975) {
            float twinkle = (n - 0.9975) * 400.0;
            // Fade stars near horizon (atmospheric extinction).
            float fadeEdge = smoothstep01(0.0, 0.15, elevation);
            color += vec3(twinkle) * u_star_brightness * fadeEdge;
        }
    }

    // Cloud layer — project ray onto a horizontal plane at cloud altitude
    // and sample 2D fBm noise. Clouds are visible only when looking up and
    // the ray actually crosses the plane above the camera.
    if (u_cloud_cover > 0.0 && elevation > 0.001) {
        float t = (u_cloud_altitude - u_camera_pos.y) / elevation;
        if (t > 0.0) {
            vec2 cloudPos = (u_camera_pos.xz + dir.xz * t) * 0.003;
            cloudPos += u_cloud_wind_dir * (u_time * u_cloud_wind_speed * 0.003);
            float n = fbm2(cloudPos);

            // Threshold-based coverage with soft edge. Full coverage => the
            // threshold drops so almost everything becomes cloudy.
            float thresh = 1.0 - u_cloud_cover * 0.95;
            float edge = 0.12;
            float cloudMask = smoothstep01(thresh - edge, thresh + edge, n);

            // Shade clouds: brighter where view ray aligns with sun (forward
            // scattering), slightly darker underside.
            float sunAlign = max(0.0, dot(dir, u_sun_direction));
            vec3 cloudLit = mix(vec3(0.78, 0.80, 0.86), u_sun_color,
                                 0.4 * u_sun_intensity * sunAlign);
            vec3 cloudShadow = vec3(0.50, 0.52, 0.58);
            vec3 cloudColor = mix(cloudShadow, cloudLit,
                                   clamp(u_sun_intensity, 0.0, 1.0));

            // Clouds fade toward the horizon (perspective + atmospheric
            // extinction). Heavy clouds don't fade as fast.
            float perspFade = smoothstep01(0.0, 0.15, elevation);
            float alpha = cloudMask * u_cloud_density * perspFade;
            color = mix(color, cloudColor, alpha);
        }
    }

    // Horizon haze — pushes colour toward the horizon tint when visibility
    // is low. Peaks at the horizon, fades toward zenith and toward ground.
    if (u_fog_density > 0.0) {
        float hazeBand = 1.0 - smoothstep01(0.0, 0.35, abs(elevation));
        color = mix(color, u_horizon_color, hazeBand * u_fog_density);
    }

    frag_color = vec4(color, 1.0);
}
GLSL;

    private const SKYBOX_VERT = <<<'GLSL'
#version 410 core

layout(location = 0) in vec3 a_position;

uniform mat4 u_view;
uniform mat4 u_projection;

out vec3 v_texCoord;

void main() {
    v_texCoord = a_position;
    // Strip translation so the cube follows the camera.
    mat4 rotView = mat4(mat3(u_view));
    gl_Position = u_projection * rotView * vec4(a_position, 1.0);
}
GLSL;

    private const SKYBOX_FRAG = <<<'GLSL'
#version 410 core

in vec3 v_texCoord;

uniform samplerCube u_skybox;

out vec4 frag_color;

void main() {
    frag_color = texture(u_skybox, v_texCoord);
}
GLSL;

    // Fullscreen quad vertex shader — minimal layout (pos + uv only)
    private const POSTPROCESS_VERT = <<<'GLSL'
#version 410 core
layout(location = 0) in vec3 a_position;
layout(location = 1) in vec2 a_uv;
out vec2 v_uv;
void main() {
    v_uv = a_uv;
    gl_Position = vec4(a_position.xy, 0.0, 1.0);
}
GLSL;

    private const TONEMAP_FRAG = <<<'GLSL'
#version 410 core
in vec2 v_uv;
uniform sampler2D u_scene;
uniform sampler2D u_bloom;
uniform float u_bloom_intensity;
uniform float u_exposure;
out vec4 frag_color;

vec3 ACESFilm(vec3 x) {
    float a = 2.51;
    float b = 0.03;
    float c = 2.43;
    float d = 0.59;
    float e = 0.14;
    return clamp((x*(a*x+b))/(x*(c*x+d)+e), 0.0, 1.0);
}

void main() {
    vec3 scene = texture(u_scene, v_uv).rgb;
    vec3 bloom = texture(u_bloom, v_uv).rgb;
    vec3 color = scene + bloom * u_bloom_intensity;
    color *= u_exposure;
    color = ACESFilm(color);
    color = pow(color, vec3(1.0 / 2.2));
    frag_color = vec4(color, 1.0);
}
GLSL;

    private const BLOOM_EXTRACT_FRAG = <<<'GLSL'
#version 410 core
in vec2 v_uv;
uniform sampler2D u_scene;
uniform float u_threshold;
out vec4 frag_color;
void main() {
    vec3 c = texture(u_scene, v_uv).rgb;
    float brightness = dot(c, vec3(0.2126, 0.7152, 0.0722));
    frag_color = vec4(c * smoothstep(u_threshold, u_threshold + 0.5, brightness), 1.0);
}
GLSL;

    private const BLOOM_BLUR_FRAG = <<<'GLSL'
#version 410 core
in vec2 v_uv;
uniform sampler2D u_source;
uniform vec2 u_direction; // (1/w, 0) for horizontal, (0, 1/h) for vertical
out vec4 frag_color;
void main() {
    float weights[5] = float[](0.227027, 0.1945946, 0.1216216, 0.054054, 0.016216);
    vec3 result = texture(u_source, v_uv).rgb * weights[0];
    for (int i = 1; i < 5; i++) {
        vec2 off = u_direction * float(i);
        result += texture(u_source, v_uv + off).rgb * weights[i];
        result += texture(u_source, v_uv - off).rgb * weights[i];
    }
    frag_color = vec4(result, 1.0);
}
GLSL;

    /**
     * Passthrough blit used by the Phase 1.5 offscreen pipeline when AA is off.
     * Samples the offscreen colour image at the swapchain UV, letting the
     * sampler's bilinear filter handle render-scale up/downscaling.
     */
    private const PASSTHROUGH_BLIT_FRAG = <<<'GLSL'
#version 410 core
in vec2 v_uv;
uniform sampler2D u_source;
out vec4 frag_color;
void main() {
    frag_color = vec4(texture(u_source, v_uv).rgb, 1.0);
}
GLSL;
}
