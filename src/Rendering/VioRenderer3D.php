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
use PHPolygon\Rendering\Command\SetSkybox;
use PHPolygon\Rendering\Command\SetWaveAnimation;
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

    /** @var array<string, VioShader> */
    private array $shaderCache = [];

    /** @var array<string, VioPipeline> */
    private array $pipelineCache = [];

    /** @var array<string, VioTexture> Texture ID -> VioTexture */
    private array $textureCache = [];

    /** @var array<string, float[]> Cache key -> pre-flattened instance matrices */
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
    private $hdrTarget = null;
    private $bloomExtractTarget = null;
    private $bloomPingTarget = null;
    private $bloomPongTarget = null;
    private $screenQuad = null;
    private float $bloomIntensity = 0.35;
    private float $bloomThreshold = 1.0;
    private float $exposure = 2.0;

    // Shadow map
    private ?VioRenderTarget $shadowTarget = null;
    private const SHADOW_MAP_RESOLUTION = 2048;
    private const SHADOW_ORTHO_SIZE = 60.0;

    // Skybox / cubemaps
    private ?VioMesh $skyboxMesh = null;
    /** @var array<string, VioCubemap> */
    private array $cubemapCache = [];
    private ?string $pendingSkyboxId = null;

    private ?VioTextureManager $textureManager = null;

    public function __construct(
        private readonly VioContext $ctx,
        int $width = 1280,
        int $height = 720,
    ) {
        $this->width = $width;
        $this->height = $height;
        $this->initShaders();
        $this->initShadowMap();
        $this->initSkyboxMesh();
        $this->initPostProcess();
    }

    public function setTextureManager(VioTextureManager $textureManager): void
    {
        $this->textureManager = $textureManager;
    }

    public function beginFrame(): void
    {
        $size = vio_framebuffer_size($this->ctx);
        if ($size[0] > 0 && $size[1] > 0) {
            $this->width = $size[0];
            $this->height = $size[1];
        }

        $this->shaderOverride = null;
        $this->globalTime += 1.0 / 60.0;
    }

    public function endFrame(): void
    {
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
                $fogColor = $cmd->color;
                $fogNear = $cmd->near;
                $fogFar = $cmd->far;
            } elseif ($cmd instanceof SetSkybox) {
                $this->pendingSkyboxId = $cmd->cubemapId;
            } elseif ($cmd instanceof SetShader) {
                $this->shaderOverride = $cmd->shaderId;
            } elseif ($cmd instanceof Command\SetSnowCover) {
                $this->snowCover = $cmd->cover;
            } elseif ($cmd instanceof SetWaveAnimation) {
                $waveEnabled = $cmd->enabled;
                $waveAmplitude = $cmd->amplitude;
                $waveFrequency = $cmd->frequency;
                $wavePhase = $cmd->phase;
            }
        }

        if ($this->currentViewMatrix === null || $this->currentProjectionMatrix === null) {
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

        // Render scene to HDR target
        $useHdr = ($this->hdrTarget !== null && $this->hdrTarget !== false);

        vio_viewport($this->ctx, 0, 0, $this->width, $this->height);

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
                $this->drawMeshInstancedCommand($cmd->meshId, $material, $cmd->matrices, $cmd->isStatic, $cmd->materialId);
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
                $this->drawMeshInstancedCommand($cmd->meshId, $material, $cmd->matrices, $cmd->isStatic, $cmd->materialId);
            }
        }

        // --- Pass 4: Skybox (rendered last, at max depth) ---
        if ($this->pendingSkyboxId !== null && $this->currentViewMatrix !== null && $this->currentProjectionMatrix !== null) {
            $this->renderSkybox($this->pendingSkyboxId);
            $this->pendingSkyboxId = null;
        }

        // --- Post-processing: HDR → Bloom → Tonemap → Backbuffer ---
        if ($useHdr) {
            vio_unbind_render_target($this->ctx);
            $sceneTex = vio_render_target_texture($this->hdrTarget);

            $hasBloom = ($this->bloomExtractTarget && $this->bloomPingTarget && $this->bloomPongTarget);
            $bloomTex = null;

            if ($hasBloom) {
                $bw = max(1, (int)($this->width / 2));
                $bh = max(1, (int)($this->height / 2));

                // Extract bright pixels
                vio_bind_render_target($this->ctx, $this->bloomExtractTarget);
                vio_viewport($this->ctx, 0, 0, $bw, $bh);
                vio_clear($this->ctx, 0, 0, 0, 1);
                $this->bindPostProcessPipeline('bloom_extract');
                vio_bind_texture($this->ctx, $sceneTex, 0);
                vio_set_uniform($this->ctx, 'u_scene', 0);
                vio_set_uniform($this->ctx, 'u_threshold', $this->bloomThreshold);
                vio_draw($this->ctx, $this->screenQuad);
                vio_unbind_render_target($this->ctx);

                // Horizontal blur: extract → ping
                $extractTex = vio_render_target_texture($this->bloomExtractTarget);
                vio_bind_render_target($this->ctx, $this->bloomPingTarget);
                vio_viewport($this->ctx, 0, 0, $bw, $bh);
                vio_clear($this->ctx, 0, 0, 0, 1);
                $this->bindPostProcessPipeline('bloom_blur');
                vio_bind_texture($this->ctx, $extractTex, 0);
                vio_set_uniform($this->ctx, 'u_source', 0);
                vio_set_uniform($this->ctx, 'u_direction', [1.0 / $bw, 0.0]);
                vio_draw($this->ctx, $this->screenQuad);
                vio_unbind_render_target($this->ctx);

                // Vertical blur: ping → pong
                $pingTex = vio_render_target_texture($this->bloomPingTarget);
                vio_bind_render_target($this->ctx, $this->bloomPongTarget);
                vio_viewport($this->ctx, 0, 0, $bw, $bh);
                vio_clear($this->ctx, 0, 0, 0, 1);
                $this->bindPostProcessPipeline('bloom_blur');
                vio_bind_texture($this->ctx, $pingTex, 0);
                vio_set_uniform($this->ctx, 'u_source', 0);
                vio_set_uniform($this->ctx, 'u_direction', [0.0, 1.0 / $bh]);
                vio_draw($this->ctx, $this->screenQuad);
                vio_unbind_render_target($this->ctx);

                $bloomTex = vio_render_target_texture($this->bloomPongTarget);
            }

            // Tonemap + composite
            vio_viewport($this->ctx, 0, 0, $this->width, $this->height);
            $this->bindPostProcessPipeline('tonemap');
            vio_bind_texture($this->ctx, $sceneTex, 0);
            vio_set_uniform($this->ctx, 'u_scene', 0);
            if ($bloomTex) {
                vio_bind_texture($this->ctx, $bloomTex, 1);
                vio_set_uniform($this->ctx, 'u_bloom', 1);
                vio_set_uniform($this->ctx, 'u_bloom_intensity', $this->bloomIntensity);
            } else {
                vio_set_uniform($this->ctx, 'u_bloom_intensity', 0.0);
            }
            vio_set_uniform($this->ctx, 'u_exposure', $this->exposure);
            vio_draw($this->ctx, $this->screenQuad);
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
    }

    private function initPostProcess(): void
    {
        $this->hdrTarget = vio_render_target($this->ctx, [
            'width' => $this->width,
            'height' => $this->height,
            'hdr' => true,
        ]);
        if ($this->hdrTarget === false) {
            $this->hdrTarget = null;
            return;
        }

        $bw = max(1, (int)($this->width / 2));
        $bh = max(1, (int)($this->height / 2));
        $this->bloomExtractTarget = vio_render_target($this->ctx, ['width' => $bw, 'height' => $bh]);
        $this->bloomPingTarget = vio_render_target($this->ctx, ['width' => $bw, 'height' => $bh]);
        $this->bloomPongTarget = vio_render_target($this->ctx, ['width' => $bw, 'height' => $bh]);

        $this->screenQuad = vio_mesh($this->ctx, [
            'vertices' => [
                -1, -1, 0,  0, 0,
                 1, -1, 0,  1, 0,
                 1,  1, 0,  1, 1,
                -1, -1, 0,  0, 0,
                 1,  1, 0,  1, 1,
                -1,  1, 0,  0, 1,
            ],
            'layout' => [VIO_FLOAT3, VIO_FLOAT2],
        ]);

        $this->compileShader('bloom_extract', self::POSTPROCESS_VERT, self::BLOOM_EXTRACT_FRAG);
        $this->compileShader('bloom_blur', self::POSTPROCESS_VERT, self::BLOOM_BLUR_FRAG);
        $this->compileShader('tonemap', self::POSTPROCESS_VERT, self::TONEMAP_FRAG);

    }

    private function compileShader(string $id, string $vertSrc, string $fragSrc): void
    {
        $shader = vio_shader($this->ctx, [
            'vertex' => $vertSrc,
            'fragment' => $fragSrc,
            'format' => VIO_SHADER_GLSL_RAW,
        ]);

        if ($shader === false) {
            throw new \RuntimeException("VioRenderer3D: Failed to compile shader '{$id}'");
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
            $this->pipelineCache[$key] = vio_pipeline($this->ctx, [
                'shader' => $this->shaderCache[$shaderId],
                'depth_test' => false,
                'cull_mode' => VIO_CULL_NONE,
                'blend' => VIO_BLEND_NONE,
            ]);
        }
        vio_bind_pipeline($this->ctx, $this->pipelineCache[$key]);
    }

    // ----------------------------------------------------------------
    // Shadow map
    // ----------------------------------------------------------------

    private function initShadowMap(): void
    {
        $target = vio_render_target($this->ctx, [
            'width' => self::SHADOW_MAP_RESOLUTION,
            'height' => self::SHADOW_MAP_RESOLUTION,
            'depth_only' => true,
        ]);

        if ($target !== false) {
            $this->shadowTarget = $target;
        }
    }

    /**
     * Render depth-only shadow pass from the brightest directional light.
     *
     * @param list<SetDirectionalLight> $dirLights
     * @return bool Whether shadow map was rendered
     */
    private function renderShadowPass(RenderCommandList $commandList, array $dirLights): bool
    {
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

        // Compute light-space matrix
        $lightSpaceMatrix = $this->computeLightSpaceMatrix($lightDir);

        // Bind shadow render target
        vio_bind_render_target($this->ctx, $this->shadowTarget);
        vio_viewport($this->ctx, 0, 0, self::SHADOW_MAP_RESOLUTION, self::SHADOW_MAP_RESOLUTION);
        vio_clear($this->ctx, 1.0, 1.0, 1.0, 1.0);

        // Use shadow pipeline
        $this->bindShadowPipeline();
        vio_set_uniform($this->ctx, 'u_view', $lightSpaceMatrix->toArray());
        vio_set_uniform($this->ctx, 'u_projection', Mat4::identity()->toArray());

        // Draw only opaque, non-sky geometry
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
                [$flatMatrices, $instanceCount] = $this->resolveInstanceData($cmd->meshId, $mat, $cmd->matrices, $cmd->isStatic);
                vio_set_uniform($this->ctx, 'u_use_instancing', 1);
                vio_draw_instanced($this->ctx, $mesh, $flatMatrices, $instanceCount);
            }
        }

        vio_unbind_render_target($this->ctx);

        // Store light-space matrix for main pass
        $this->currentLightSpaceMatrix = $lightSpaceMatrix;

        return true;
    }

    private ?Mat4 $currentLightSpaceMatrix = null;

    private function computeLightSpaceMatrix(Vec3 $sunDirection): Mat4
    {
        $len = sqrt($sunDirection->x ** 2 + $sunDirection->y ** 2 + $sunDirection->z ** 2);
        if ($len < 0.001) {
            return Mat4::identity();
        }
        $dx = $sunDirection->x / $len;
        $dy = $sunDirection->y / $len;
        $dz = $sunDirection->z / $len;

        $lightPos = new Vec3(-$dx * 80.0, -$dy * 80.0, -$dz * 80.0);
        $target = Vec3::zero();

        $up = abs($dy) > 0.9
            ? new Vec3(0.0, 0.0, 1.0)
            : new Vec3(0.0, 1.0, 0.0);

        $lightView = self::lookAt($lightPos, $target, $up);
        $s = self::SHADOW_ORTHO_SIZE;
        $lightProj = Mat4::orthographic(-$s, $s, -$s, $s, 0.5, 200.0);

        return $lightProj->multiply($lightView);
    }

    /**
     * @param list<SetDirectionalLight> $dirLights
     */
    private function uploadShadowUniforms(bool $hasShadowMap, array $dirLights): void
    {
        vio_set_uniform($this->ctx, 'u_has_shadow_map', $hasShadowMap ? 1 : 0);

        if ($hasShadowMap && $this->shadowTarget !== null && $this->currentLightSpaceMatrix !== null) {
            $shadowTex = vio_render_target_texture($this->shadowTarget);
            vio_bind_texture($this->ctx, $shadowTex, 6);
            vio_set_uniform($this->ctx, 'u_shadow_map', 6);

            $lsm = $this->currentLightSpaceMatrix->toArray();

            // D3D11/D3D12: render target Y is flipped vs OpenGL.
            // Negate the Y row of the light-space matrix so shadow map UVs match.
            $backend = vio_backend_name($this->ctx);
            if ($backend === 'd3d11' || $backend === 'd3d12') {
                $lsm[1]  = -$lsm[1];   // col0.y
                $lsm[5]  = -$lsm[5];   // col1.y
                $lsm[9]  = -$lsm[9];   // col2.y
                $lsm[13] = -$lsm[13];  // col3.y
            }

            vio_set_uniform($this->ctx, 'u_light_space_matrix', $lsm);
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
        /** @var float[] $v Unit cube vertices (36 triangles, position only) */
        $v = [
            -1.0,  1.0, -1.0,  -1.0, -1.0, -1.0,   1.0, -1.0, -1.0,   1.0, -1.0, -1.0,   1.0,  1.0, -1.0,  -1.0,  1.0, -1.0,
            -1.0, -1.0,  1.0,  -1.0, -1.0, -1.0,  -1.0,  1.0, -1.0,  -1.0,  1.0, -1.0,  -1.0,  1.0,  1.0,  -1.0, -1.0,  1.0,
             1.0, -1.0, -1.0,   1.0, -1.0,  1.0,   1.0,  1.0,  1.0,   1.0,  1.0,  1.0,   1.0,  1.0, -1.0,   1.0, -1.0, -1.0,
            -1.0, -1.0,  1.0,  -1.0,  1.0,  1.0,   1.0,  1.0,  1.0,   1.0,  1.0,  1.0,   1.0, -1.0,  1.0,  -1.0, -1.0,  1.0,
            -1.0,  1.0, -1.0,   1.0,  1.0, -1.0,   1.0,  1.0,  1.0,   1.0,  1.0,  1.0,  -1.0,  1.0,  1.0,  -1.0,  1.0, -1.0,
            -1.0, -1.0, -1.0,  -1.0, -1.0,  1.0,   1.0, -1.0, -1.0,   1.0, -1.0, -1.0,  -1.0, -1.0,  1.0,   1.0, -1.0,  1.0,
        ];

        $mesh = vio_mesh($this->ctx, [
            'vertices' => $v,
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
        if (isset($this->cubemapCache[$cubemapId])) {
            return $this->cubemapCache[$cubemapId];
        }

        $cubemap = false;

        if (CubemapRegistry::isProcedural($cubemapId)) {
            $data = CubemapRegistry::getProcedural($cubemapId);
            if ($data !== null) {
                $cubemap = vio_cubemap($this->ctx, [
                    'pixels' => $data->faces,
                    'width' => $data->resolution,
                    'height' => $data->resolution,
                ]);
            }
        } else {
            $faces = CubemapRegistry::get($cubemapId);
            if ($faces !== null) {
                $cubemap = vio_cubemap($this->ctx, [
                    'faces' => $faces->toArray(),
                ]);
            }
        }

        if ($cubemap === false) {
            return null;
        }

        $this->cubemapCache[$cubemapId] = $cubemap;
        return $cubemap;
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

            $pipeline = vio_pipeline($this->ctx, [
                'shader' => $shader,
                'depth_test' => true,
                'depth_func' => VIO_DEPTH_LEQUAL,
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
        if (isset($this->meshCache[$meshId])) {
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

        vio_draw($this->ctx, $mesh);
    }

    /**
     * @param Mat4[] $matrices
     */
    private function drawMeshInstancedCommand(string $meshId, Material $material, array $matrices, bool $isStatic = false, string $materialId = ''): void
    {
        if (empty($matrices)) {
            return;
        }

        $mesh = $this->uploadMesh($meshId);
        if ($mesh === null) {
            return;
        }

        $this->applyMaterial($material, $materialId);
        vio_set_uniform($this->ctx, 'u_use_instancing', 1);

        [$flatMatrices, $instanceCount] = $this->resolveInstanceData($meshId, $material, $matrices, $isStatic);
        vio_draw_instanced($this->ctx, $mesh, $flatMatrices, $instanceCount);

        vio_set_uniform($this->ctx, 'u_use_instancing', 0);
    }

    /**
     * Resolve instance matrix data as a packed binary string (fast path).
     * Static instances are cached as packed strings for zero-copy reuse.
     *
     * @param Mat4[] $matrices
     * @return array{string, int}
     */
    private function resolveInstanceData(string $meshId, Material $material, array $matrices, bool $isStatic): array
    {
        $cacheKey = $meshId . ':' . ($material->shader) . ':' . spl_object_id($material);

        if ($isStatic && isset($this->staticMatrixCache[$cacheKey])) {
            return [$this->staticMatrixCache[$cacheKey], $this->staticInstanceCountCache[$cacheKey]];
        }

        // Pack all matrices into a binary float string (zero-copy to C)
        $floats = [];
        foreach ($matrices as $matrix) {
            foreach ($matrix->toArray() as $v) {
                $floats[] = $v;
            }
        }
        $packed = pack('f*', ...$floats);
        $count = count($matrices);

        if ($isStatic) {
            $this->staticMatrixCache[$cacheKey] = $packed;
            $this->staticInstanceCountCache[$cacheKey] = $count;
        }

        return [$packed, $count];
    }

    private function applyMaterial(Material $material, string $materialId = ''): void
    {
        vio_set_uniform($this->ctx, 'u_albedo', [$material->albedo->r, $material->albedo->g, $material->albedo->b]);
        vio_set_uniform($this->ctx, 'u_emission', [$material->emission->r, $material->emission->g, $material->emission->b]);
        vio_set_uniform($this->ctx, 'u_roughness', $material->roughness);
        vio_set_uniform($this->ctx, 'u_metallic', $material->metallic);
        vio_set_uniform($this->ctx, 'u_alpha', $material->alpha);

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
        vio_set_uniform($this->ctx, 'u_linear_output', ($this->hdrTarget !== null && $this->hdrTarget !== false) ? 1 : 0);

        if ($this->cameraPosition !== null) {
            vio_set_uniform($this->ctx, 'u_camera_pos', [
                $this->cameraPosition->x, $this->cameraPosition->y, $this->cameraPosition->z,
            ]);
        }

        $ac = $state['ambientColor'];
        $ai = $state['ambientIntensity'];
        vio_set_uniform($this->ctx, 'u_ambient_color', [$ac->r * $ai, $ac->g * $ai, $ac->b * $ai]);
        vio_set_uniform($this->ctx, 'u_ambient_intensity', $ai);

        $dirCount = min(count($state['dirLights']), 4);
        vio_set_uniform($this->ctx, 'u_dir_light_count', $dirCount);
        for ($i = 0; $i < $dirCount; $i++) {
            $dl = $state['dirLights'][$i];
            vio_set_uniform($this->ctx, "u_dir_lights[{$i}].direction", [$dl->direction->x, $dl->direction->y, $dl->direction->z]);
            vio_set_uniform($this->ctx, "u_dir_lights[{$i}].color", [$dl->color->r, $dl->color->g, $dl->color->b]);
            vio_set_uniform($this->ctx, "u_dir_lights[{$i}].intensity", $dl->intensity);
        }

        $ptCount = min(count($state['pointLights']), 4);
        vio_set_uniform($this->ctx, 'u_point_light_count', $ptCount);
        for ($i = 0; $i < $ptCount; $i++) {
            $pl = $state['pointLights'][$i];
            vio_set_uniform($this->ctx, "u_point_lights[{$i}].position", [$pl->position->x, $pl->position->y, $pl->position->z]);
            vio_set_uniform($this->ctx, "u_point_lights[{$i}].color", [$pl->color->r, $pl->color->g, $pl->color->b]);
            vio_set_uniform($this->ctx, "u_point_lights[{$i}].intensity", $pl->intensity);
            vio_set_uniform($this->ctx, "u_point_lights[{$i}].radius", $pl->radius);
        }

        $fc = $state['fogColor'];
        vio_set_uniform($this->ctx, 'u_fog_color', [$fc->r, $fc->g, $fc->b]);
        vio_set_uniform($this->ctx, 'u_fog_near', $state['fogNear']);
        vio_set_uniform($this->ctx, 'u_fog_far', $state['fogFar']);

        vio_set_uniform($this->ctx, 'u_time', $this->globalTime);
        vio_set_uniform($this->ctx, 'u_sky_color', [0.55, 0.70, 0.85]);
        vio_set_uniform($this->ctx, 'u_horizon_color', [0.85, 0.88, 0.92]);
        vio_set_uniform($this->ctx, 'u_snow_cover', $this->snowCover);
        vio_set_uniform($this->ctx, 'u_vertex_anim', 0); // enabled per-material in applyMaterial
        vio_set_uniform($this->ctx, 'u_wave_amplitude', $state['waveAmplitude']);
        vio_set_uniform($this->ctx, 'u_wave_frequency', $state['waveFrequency']);
        vio_set_uniform($this->ctx, 'u_wave_phase', $state['wavePhase']);
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

uniform mat4 u_light_space_matrix;

out vec3 v_normal;
out vec3 v_worldPos;
out vec2 v_uv;
out vec4 v_lightSpacePos;

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

    vec4 worldPos = model * vec4(pos, 1.0);
    v_worldPos = worldPos.xyz;

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

struct PointLight {
    vec3 position;
    vec3 color;
    float intensity;
    float radius;
};
uniform PointLight u_point_lights[4];
uniform int u_point_light_count;

uniform vec3 u_albedo;
uniform vec3 u_emission;
uniform float u_roughness;
uniform float u_metallic;
uniform float u_alpha;
uniform float u_snow_cover; // 0.0 = no snow, 1.0 = full blanket
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

float calcShadow(vec4 lsp, vec3 N) {
    vec3 pc = lsp.xyz / lsp.w * 0.5 + 0.5;
    if (pc.x < 0.0 || pc.x > 1.0 || pc.y < 0.0 || pc.y > 1.0 || pc.z > 1.0) return 1.0;
    if (u_has_shadow_map == 0) return 1.0;
    vec3 lightDir = normalize(-u_dir_light_direction);
    float NdotL = max(dot(N, lightDir), 0.0);
    float bias = mix(0.005, 0.001, NdotL);
    float s = 0.0;
    float ts = 1.0 / 2048.0;
    float rd = pc.z - bias;
    for (int x = -1; x <= 1; x++)
        for (int y = -1; y <= 1; y++)
            s += texture(u_shadow_map, vec3(pc.xy + vec2(x,y) * ts, rd));
    return s / 9.0;
}

// ================================================================
//  PBR helpers
// ================================================================

vec3 fresnelSchlick(float cosTheta, vec3 F0) {
    return F0 + (1.0 - F0) * pow(clamp(1.0 - cosTheta, 0.0, 1.0), 5.0);
}

vec4 outputColor(vec3 color, float alpha) {
    color = max(color, vec3(0.0));
    if (u_linear_output == 0) {
        color = pow(color, vec3(1.0 / 2.2));
    }
    return vec4(color, alpha);
}

// ================================================================
//  Procedural Sand (optimized)
// ================================================================

vec3 computeSand(vec3 N, vec3 V, vec3 L, out float roughOut) {
    float zone = v_uv.x;
    float variant = v_uv.y;

    const vec3 damp[4] = vec3[](vec3(0.478,0.369,0.165), vec3(0.408,0.306,0.125), vec3(0.541,0.408,0.188), vec3(0.290,0.220,0.094));
    const vec3 mid[4]  = vec3[](vec3(0.722,0.565,0.314), vec3(0.627,0.471,0.220), vec3(0.784,0.596,0.345), vec3(0.420,0.333,0.157));
    const vec3 dry[4]  = vec3[](vec3(0.831,0.722,0.478), vec3(0.769,0.643,0.384), vec3(0.878,0.769,0.549), vec3(0.545,0.451,0.251));
    const vec3 dune[4] = vec3[](vec3(0.863,0.753,0.502), vec3(0.910,0.800,0.565), vec3(0.816,0.706,0.439), vec3(0.604,0.502,0.282));

    vec3 colors[4];
    if (zone < 0.125)      colors = damp;
    else if (zone < 0.375) colors = mid;
    else if (zone < 0.625) colors = dry;
    else                   colors = dune;

    float vi = variant * 3.0;
    int idx = int(floor(vi));
    vec3 baseColor = mix(colors[clamp(idx,0,3)], colors[clamp(idx+1,0,3)], fract(vi));
    baseColor *= u_season_tint;

    float n1 = fbm2(v_worldPos.xz * 1.5);
    float n2 = noise(v_worldPos.xz * 6.0);

    vec3 sandColor = baseColor;
    sandColor *= 0.82 + n1 * 0.36;
    sandColor *= 0.92 + (n2 - 0.5) * 0.16;

    float ripple = sin(v_worldPos.x * 3.0 + v_worldPos.z * 1.5 + n1 * 2.0) * 0.5 + 0.5;
    ripple = smoothstep(0.3, 0.7, ripple);
    sandColor *= 1.0 - ripple * 0.06 * smoothstep(0.3, 0.8, zone);

    float scatter = pow(max(dot(V, L), 0.0), 4.0) * 0.08;
    sandColor += vec3(0.15, 0.10, 0.04) * scatter;

    roughOut = mix(0.45, 0.95, smoothstep(0.0, 0.3, zone));
    if (zone < 0.15) sandColor *= 1.04;

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
    float ring = smoothstep(0.3, 0.7, sin(worldPos.y * 12.0) * 0.5 + 0.5);
    float fiber = noise(vec2(worldPos.x * 20.0 + worldPos.z * 20.0, worldPos.y * 3.0));

    vec3 barkColor = mix(baseAlbedo * 0.65, baseAlbedo * 1.2, ring * 0.6 + fiber * 0.4);
    barkColor *= 0.85 + ring * 0.3;

    float weather = noise(worldPos.xz * 5.0);
    barkColor = mix(barkColor, barkColor * vec3(0.85, 0.9, 0.8), weather * 0.2);

    roughOut = 0.85 + ring * 0.1;
    return barkColor;
}

// ================================================================
//  Procedural Palm Leaf (optimized)
// ================================================================

vec3 computePalmLeaf(vec3 worldPos, vec3 N, vec3 V, vec3 L, vec3 baseAlbedo, out float roughOut) {
    float vein = smoothstep(0.0, 0.15, abs(sin(worldPos.x * 30.0 + worldPos.z * 30.0)));
    float n = fbm2(worldPos.xz * 8.0);
    vec3 leafColor = baseAlbedo * (0.8 + n * 0.4);
    leafColor = mix(leafColor * 1.3, leafColor, vein);

    float edgeNoise = noise(worldPos.xz * 12.0);
    leafColor = mix(leafColor, vec3(0.4, 0.35, 0.15), edgeNoise * 0.15);

    leafColor += vec3(0.1, 0.2, 0.02) * pow(max(dot(-N, L), 0.0), 2.0) * 0.3;
    leafColor += vec3(0.05, 0.1, 0.02) * pow(max(dot(V, L), 0.0), 3.0) * 0.1;

    roughOut = 0.6 + edgeNoise * 0.15;
    return leafColor;
}

// ================================================================
//  Procedural Wood Planks (optimized)
// ================================================================

vec3 computeWoodPlanks(vec3 N, vec3 worldPos, vec3 baseAlbedo, out float roughOut) {
    float plankY = worldPos.y * 6.5;
    float plankIndex = floor(plankY);
    float withinPlank = fract(plankY);

    float gap = smoothstep(0.0, 0.03, withinPlank) * smoothstep(1.0, 0.97, withinPlank);
    float plankHash = hash21(vec2(plankIndex * 17.3, plankIndex * 7.1));

    vec3 woodColor = baseAlbedo * (0.8 + plankHash * 0.4);

    float grainCoord = worldPos.x * 8.0 + worldPos.z * 8.0 + plankHash * 20.0;
    float grain = sin(grainCoord + noise(vec2(grainCoord * 0.5, plankIndex)) * 3.0) * 0.5 + 0.5;
    woodColor *= 0.9 + grain * 0.15;

    woodColor *= gap * 0.85 + 0.15;
    woodColor *= 0.85 + noise(worldPos.xz * 3.0 + worldPos.y * 2.0) * 0.2;

    roughOut = 0.78 + plankHash * 0.15;
    return woodColor;
}

// ================================================================
//  Procedural Thatch (optimized)
// ================================================================

vec3 computeThatch(vec3 N, vec3 worldPos, vec3 baseAlbedo, out float roughOut) {
    float strandAngle = worldPos.x * 12.0 + worldPos.z * 6.0 + worldPos.y * 4.0;
    float strand1 = sin(strandAngle) * 0.5 + 0.5;

    vec3 strawColor = baseAlbedo * (0.75 + fbm2(worldPos.xz * 5.0 + worldPos.y * 3.0) * 0.5);
    strawColor += vec3(0.1, 0.08, 0.02) * pow(strand1, 8.0);

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
//  Main
// ================================================================

void main() {
    vec3 N = normalize(v_normal);
    // View-facing normal for specular/fresnel (flipped for back faces)
    vec3 Nv = gl_FrontFacing ? N : -N;

    vec3 V = normalize(u_camera_pos - v_worldPos);
    vec3 L = normalize(-u_dir_light_direction);

    float roughness = clamp(u_roughness, 0.04, 1.0);
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
    } else {
        float nse = noise(v_worldPos.xz * 0.4);
        float noiseMask = smoothstep(0.3, 0.9, roughness);
        albedo = texAlbedo * (1.0 + (nse - 0.5) * 0.12 * noiseMask);
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

    // ---- PBR Lighting ----
    float shininess = exp2(10.0 * (1.0 - roughness) + 1.0);
    vec3 F0 = mix(vec3(0.04), albedo, u_metallic);
    float shadow = calcShadow(v_lightSpacePos, N);

    float primaryIntensity = u_dir_light_count > 0 ? u_dir_lights[0].intensity : 0.0;
    float ambientShadow = mix(1.0, mix(0.5, 1.0, shadow), clamp(primaryIntensity, 0.0, 1.0));
    vec3 color = u_ambient_color * u_ambient_intensity * albedo * (1.0 - u_metallic * 0.9) * ambientShadow;

    for (int dl = 0; dl < u_dir_light_count; dl++) {
        vec3 dL = normalize(-u_dir_lights[dl].direction);
        vec3 dH = normalize(V + dL);
        float rawNdotL = dot(N, dL);
        float dNdotL = max(rawNdotL, 0.0);
        float halfLambert = rawNdotL * 0.5 + 0.5;
        halfLambert *= halfLambert;
        float diffNdotL = mix(dNdotL, halfLambert, 0.4);
        float dShadow = (dl == 0) ? shadow : 1.0;

        color += albedo * u_dir_lights[dl].color * u_dir_lights[dl].intensity * diffNdotL * dShadow * (1.0 - u_metallic);
        if (dNdotL > 0.0) {
            float spec = pow(max(dot(Nv, dH), 0.0), shininess) * (shininess + 2.0) / 8.0;
            color += fresnelSchlick(max(dot(dH, V), 0.0), F0) * u_dir_lights[dl].color * u_dir_lights[dl].intensity * spec * dNdotL * dShadow;
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
            vec3 lc = u_point_lights[i].color * u_point_lights[i].intensity * atten;
            color += albedo * lc * NdotPL * (1.0 - u_metallic);
            vec3 Hp = normalize(V + Lp);
            color += fresnelSchlick(max(dot(Hp, V), 0.0), F0) * lc * pow(max(dot(Nv, Hp), 0.0), shininess) * (shininess + 2.0) / 8.0 * NdotPL;
        }
    }

    color += u_emission;

    float fogDist = length(v_worldPos - u_camera_pos);
    float fogFactor = clamp((fogDist - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
    color = mix(color, u_fog_color, 1.0 - exp(-fogFactor * fogFactor * 3.0));

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

    private const SKYBOX_VERT = <<<'GLSL'
#version 410 core

layout(location = 0) in vec3 a_position;

uniform mat4 u_view;
uniform mat4 u_projection;

out vec3 v_texCoord;

void main() {
    v_texCoord = a_position;
    mat4 rotView = mat4(mat3(u_view));
    vec4 pos = u_projection * rotView * vec4(a_position, 1.0);
    gl_Position = pos.xyww;
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
}
