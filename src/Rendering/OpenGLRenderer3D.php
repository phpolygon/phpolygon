<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use GL\Buffer\FloatBuffer;
use GL\Buffer\IntBuffer;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\AddSpotLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetFog;
use PHPolygon\Rendering\Command\SetShader;
use PHPolygon\Rendering\Command\SetSkybox;
use PHPolygon\Rendering\Command\SetWaveAnimation;
use PHPolygon\Rendering\Command\SetWind;
use PHPolygon\Rendering\PostProcess\OpenGLFxaaPass;
use PHPolygon\Rendering\PostProcess\OpenGLSsrPass;
use PHPolygon\Rendering\PostProcess\OpenGLTaaPass;
use PHPolygon\Rendering\Quality\ScreenSpaceReflections;
use PHPolygon\Rendering\Quality\TaaJitter;
use PHPolygon\Rendering\Quality\AntiAliasing;

/**
 * OpenGL 4.1 3D renderer. Translates a RenderCommandList into GL draw calls.
 * Supports GPU instancing via glDrawElementsInstanced for DrawMeshInstanced commands.
 */
class OpenGLRenderer3D implements Renderer3DInterface
{
    private const GL_ERROR_NAMES = [
        0x0500 => 'GL_INVALID_ENUM',
        0x0501 => 'GL_INVALID_VALUE',
        0x0502 => 'GL_INVALID_OPERATION',
        0x0503 => 'GL_STACK_OVERFLOW',
        0x0504 => 'GL_STACK_UNDERFLOW',
        0x0505 => 'GL_OUT_OF_MEMORY',
        0x0506 => 'GL_INVALID_FRAMEBUFFER_OPERATION',
    ];

    private int $width;
    private int $height;

    /** @var array<string, int> Mesh VAO cache */
    private array $vaoCache = [];

    /** @var array<string, int> Mesh index count cache */
    private array $indexCountCache = [];

    /** @var array<string, int> Expanded vertex count for instanced draws (non-indexed) */
    private array $expandedVertexCount = [];

    /** @var array<string, int> VAO for instanced draws (expanded, non-indexed) */
    private array $instancedVaoCache = [];

    /** @var array<string, int> Instance VBO per mesh (shared, for instanced rendering) */
    private array $instanceVboCache = [];

    /** @var array<string, FloatBuffer> Cached serialized matrices per mesh:material (static only) */
    private array $staticFloatBufferCache = [];

    /** @var array<string, int> Cached instance count per mesh:material (static only) */
    private array $staticInstanceCountCache = [];


    /** @var array<string, int> Compiled GL program per shader ID */
    private array $shaderProgramCache = [];

    /** Currently active GL program handle (for uniform calls) */
    private int $activeProgram = 0;

    /** @var array<int, array<string, int>> Cached uniform locations per program handle */
    private array $uniformLocationCache = [];

    /** Frame-level shader override from SetShader command (null = use material's shader) */
    private ?string $shaderOverride = null;

    private int $skyboxVao = 0;

    /** @var array<string, int> GL cubemap texture IDs */
    private array $cubemapCache = [];

    private int $dirLightCount = 0;
    /** @var list<array{dir: float[], color: float[], intensity: float}> */
    private array $dirLights = [];
    private int $pointLightCount = 0;

    /** @var array<int, array{pos: float[], color: float[], intensity: float, radius: float}> */
    private array $pointLights = [];

    private int $spotLightCount = 0;

    /** @var array<int, array{pos: float[], dir: float[], color: float[], intensity: float, range: float, angle: float, penumbra: float}> */
    private array $spotLights = [];

    private ?string $pendingSkyboxId = null;

    private ?Mat4 $currentViewMatrix = null;
    private ?Mat4 $currentProjectionMatrix = null;
    /** Cached world-space camera position, computed once per SetCamera dispatch. */
    private ?\PHPolygon\Math\Vec3 $currentCameraPos = null;


    /** Global time for shader animations (seconds since start) */
    private float $globalTime = 0.0;
    private int $dummyCubemap = 0;
    private int $dummyDepthTex = 0;
    private int $dummyCloudTex = 0;
    private ?ShadowMapRenderer $shadowMap = null;
    private ?CloudShadowRenderer $cloudShadow = null;

    /**
     * Off-screen target backing the Phase 1.5 render-scale + MSAA pipeline.
     * Allocated lazily on the first frame after the target size is known.
     * Stays null while the engine is running at native resolution with no AA
     * - that path skips the off-screen blit entirely (zero overhead).
     */
    private ?OpenGLOffscreenTarget $offscreenTarget = null;

    /** Lazy FXAA post-process pass. Null when AntiAliasing != Fxaa. */
    private ?OpenGLFxaaPass $fxaaPass = null;
    private ?OpenGLSsrPass $ssrPass = null;
    private ?OpenGLTaaPass $taaPass = null;
    /** Frame counter driving TAA jitter and history reset thresholds. */
    private int $frameIndex = 0;

    /**
     * True when the current frame is being rendered into the offscreen target
     * rather than directly into the backbuffer. Set by beginFrame()/render(),
     * read by endFrame() to know whether to resolve+blit.
     */
    private bool $offscreenActive = false;

    /** Backbuffer resolution captured at frame start (for blit destination). */
    private int $backbufferWidth = 0;
    private int $backbufferHeight = 0;

    /**
     * Live graphics settings driving shadow-map size, view-distance clamp,
     * cloud-shadow toggle, fog toggle, anisotropy and shader-quality choice.
     * applySettings() may be called at any time after construction.
     */
    private GraphicsSettings $settings;

    public function __construct(int $width = 1280, int $height = 720, ?GraphicsSettings $settings = null)
    {
        $this->width  = $width;
        $this->height = $height;
        $this->settings = $settings ?? new GraphicsSettings();

        // Register all built-in shaders (games can override by registering before construction)
        $builtins = [
            'default' => ['mesh3d.vert.glsl', 'mesh3d.frag.glsl'],
            'unlit'   => ['unlit.vert.glsl', 'unlit.frag.glsl'],
            'normals' => ['normals.vert.glsl', 'normals.frag.glsl'],
            'depth'   => ['depth.vert.glsl', 'depth.frag.glsl'],
            'shadow'  => ['shadow.vert.glsl', 'shadow.frag.glsl'],
            'skybox'  => ['skybox.vert.glsl', 'skybox.frag.glsl'],
        ];
        $shaderDir = __DIR__ . '/../../resources/shaders/source/';
        foreach ($builtins as $id => [$vert, $frag]) {
            if (!ShaderRegistry::has($id)) {
                ShaderRegistry::register($id, new ShaderDefinition($shaderDir . $vert, $shaderDir . $frag));
            }
        }

        $this->initShaders();
        $this->initSkybox();
    }

    public function beginFrame(): void
    {
        glEnable(GL_DEPTH_TEST);
        glDepthFunc(GL_LESS);
        glEnable(GL_MULTISAMPLE);
        glDisable(GL_CULL_FACE);
        glFrontFace(GL_CCW);
        $this->pointLightCount = 0;
        $this->pointLights     = [];
        $this->spotLightCount  = 0;
        $this->spotLights      = [];

        // Capture the backbuffer size on entry; setViewport() may have already
        // updated $width/$height to the framebuffer dimensions for this frame.
        $this->backbufferWidth  = $this->width;
        $this->backbufferHeight = $this->height;

        $this->beginOffscreenIfRequired();
    }

    public function endFrame(): void
    {
        if (!$this->offscreenActive || $this->offscreenTarget === null) {
            return;
        }

        $target = $this->offscreenTarget;
        $target->resolve();

        $taaActive = $this->settings->antiAliasing === AntiAliasing::Taa
            && $this->taaPass !== null;

        $ssrActive = !$taaActive
            && $this->settings->ssr !== ScreenSpaceReflections::Off
            && $this->ssrPass !== null
            && $this->currentViewMatrix !== null
            && $this->currentProjectionMatrix !== null;

        if ($taaActive) {
            // TAA composite reads the resolved current frame, blends with
            // history, writes the composite to the backbuffer AND copies
            // it into history for the next frame.
            glBindFramebuffer(0x8D40 /* GL_FRAMEBUFFER */, 0);
            glViewport(0, 0, $this->backbufferWidth, $this->backbufferHeight);
            glDisable(GL_DEPTH_TEST);
            $this->taaPass->apply(
                $target->colorTextureId(),
                $target->width(),
                $target->height(),
                $this->backbufferWidth,
                $this->backbufferHeight,
            );
            glEnable(GL_DEPTH_TEST);
        } elseif ($ssrActive) {
            // SSR composites scene + raymarched reflections directly to the
            // backbuffer. FXAA is mutually exclusive with SSR for now -
            // running both would require a temp RT to break the read/write
            // cycle (the SSR pass cannot sample the same texture it writes
            // to). Bridge target = follow-up.
            $vp = $this->currentProjectionMatrix->multiply($this->currentViewMatrix);
            // Reuse the camera position cached during SetCamera dispatch
            // - avoids a second 4x4 inverse per frame.
            $cameraPos = $this->currentCameraPos ?? $this->currentViewMatrix->inverse()->getTranslation();
            glBindFramebuffer(0x8D40 /* GL_FRAMEBUFFER */, 0);
            glViewport(0, 0, $this->backbufferWidth, $this->backbufferHeight);
            glDisable(GL_DEPTH_TEST);
            $this->ssrPass->apply(
                $target->colorTextureId(),
                $target->depthTextureId(),
                $vp,
                [$cameraPos->x, $cameraPos->y, $cameraPos->z],
                $target->width(),
                $target->height(),
                $this->settings->ssr->intensity(),
            );
            glEnable(GL_DEPTH_TEST);
        } elseif ($this->settings->antiAliasing === AntiAliasing::Fxaa && $this->fxaaPass !== null) {
            // FXAA reads the resolved color texture and writes directly to the
            // backbuffer at backbuffer resolution. The pass also performs the
            // up/down-sample implicitly via the sampler's bilinear filter.
            glBindFramebuffer(0x8D40 /* GL_FRAMEBUFFER */, 0);
            glViewport(0, 0, $this->backbufferWidth, $this->backbufferHeight);
            glDisable(GL_DEPTH_TEST);
            $this->fxaaPass->apply($target->colorTextureId(), $target->width(), $target->height());
            glEnable(GL_DEPTH_TEST);
        } else {
            $target->presentToBackbuffer($this->backbufferWidth, $this->backbufferHeight);
        }

        $this->offscreenActive = false;
        $this->frameIndex++;
    }

    public function clear(Color $color): void
    {
        glClearColor($color->r, $color->g, $color->b, $color->a);
        glClear(GL_COLOR_BUFFER_BIT | GL_DEPTH_BUFFER_BIT);
    }

    public function setViewport(int $x, int $y, int $width, int $height): void
    {
        $this->width  = $width;
        $this->height = $height;
        glViewport($x, $y, $width, $height);
    }

    public function getWidth(): int { return $this->width; }
    public function getHeight(): int { return $this->height; }

    public function render(RenderCommandList $commandList): void
    {
        // Ensure 3D GL state — beginFrame() is not reliably called by the engine
        glEnable(GL_DEPTH_TEST);
        glDepthFunc(GL_LESS);
        glEnable(GL_MULTISAMPLE);
        glDisable(GL_CULL_FACE);
        glFrontFace(GL_CCW);

        // If beginFrame() was skipped (legacy entry path), make sure the
        // offscreen target is set up before we issue glClear into whichever
        // framebuffer happens to be bound.
        if (!$this->offscreenActive && $this->offscreenIsActive()) {
            $this->backbufferWidth  = $this->backbufferWidth  > 0 ? $this->backbufferWidth  : $this->width;
            $this->backbufferHeight = $this->backbufferHeight > 0 ? $this->backbufferHeight : $this->height;
            $this->beginOffscreenIfRequired();
        }

        glClear(GL_DEPTH_BUFFER_BIT);
        $this->pointLightCount = 0;
        $this->pointLights     = [];
        $this->spotLightCount  = 0;
        $this->spotLights      = [];
        $this->shaderOverride = null;

        $defaultProgram = $this->shaderProgramCache['default'];
        $this->useShaderProgram($defaultProgram);

        // Defaults
        $this->setUniformVec3('u_ambient_color', [1.0, 1.0, 1.0]);
        $this->setUniformFloat('u_ambient_intensity', 0.1);
        $this->dirLightCount = 0;
        $this->dirLights = [];
        $this->setUniformInt('u_dir_light_count', 0);
        $this->setUniformFloat('u_fog_near', 50.0);
        $this->setUniformFloat('u_fog_far', 200.0);
        $this->setUniformVec3('u_fog_color', [0.5, 0.5, 0.5]);
        $this->setUniformVec3('u_albedo', [0.8, 0.8, 0.8]);
        $this->setUniformFloat('u_clearcoat', 0.0);
        $this->setUniformFloat('u_clearcoat_roughness', 0.05);
        $this->setUniformFloat('u_flakes', 0.0);
        $this->setUniformFloat('u_normal_intensity', 1.0);
        $this->setUniformInt('u_use_environment_map', 1);
        $this->setUniformInt('u_normal_pattern', 0);
        $this->setUniformFloat('u_normal_scale', 1.0);
        $this->setUniformInt('u_surface_pattern', 0);
        $this->setUniformFloat('u_surface_scale', 1.0);
        $this->setUniformFloat('u_surface_intensity', 1.0);
        $this->setUniformFloat('u_wetness', 0.0);

        // CSM defaults (1 cascade, identity matrices) so the shader never
        // samples uninitialised uniforms before renderShadowMap() runs.
        $this->setUniformInt('u_csm_count', 1);
        $this->setUniformInt('u_csm_map_0', 6);
        $this->setUniformInt('u_csm_map_1', 6);
        $this->setUniformInt('u_csm_map_2', 6);
        $this->setUniformFloat('u_csm_far_0', 60.0);
        $this->setUniformFloat('u_csm_far_1', 120.0);
        $this->setUniformFloat('u_csm_far_2', 200.0);
        $identityMat = \PHPolygon\Math\Mat4::identity();
        $this->setUniformMat4('u_csm_matrix_0', $identityMat);
        $this->setUniformMat4('u_csm_matrix_1', $identityMat);
        $this->setUniformMat4('u_csm_matrix_2', $identityMat);
        $this->setUniformFloat('u_ao_strength', $this->settings->ambientOcclusion->strength());

        // Fieldtracing (SDF GI) default from settings (gated). A SetFieldtracing
        // command later in the list overrides it. Off => 0.0 => no-op in shader.
        $ftMode = $this->gateFieldtracing($this->settings->fieldtracing);
        $this->setUniformFloat('u_ft_mode', (float) $this->fieldtracingModeCode($ftMode));
        $this->setUniformFloat('u_ft_intensity', 1.0);
        $this->setUniformFloat('u_ft_ao', 1.5);
        // The screen-space SDF trace pass is D3D-only (like G-buffer SSAO); on
        // OpenGL it never runs, so the mesh shader's SDF AO/shadow stays neutral.
        $this->setUniformFloat('u_sdf_ao_enabled', 0.0);

        // Color grading + vignette (set every beginFrame so a settings
        // change between frames takes effect on the very next draw).
        $grade = $this->settings->colorGrading->params();
        $this->setUniformVec3('u_grade_lift', $grade['lift']);
        $this->setUniformVec3('u_grade_gamma', $grade['gamma']);
        $this->setUniformVec3('u_grade_gain', $grade['gain']);
        $this->setUniformFloat('u_grade_saturation', $grade['saturation']);
        $this->setUniformFloat('u_vignette_intensity', $this->settings->vignetteIntensity);
        $this->setUniformInt('u_volumetric_fog', $this->settings->volumetricFog ? 1 : 0);
        $this->setUniformFloat('u_ssr_intensity', $this->settings->ssr->intensity());
        $vw = $this->backbufferWidth  > 0 ? $this->backbufferWidth  : $this->width;
        $vh = $this->backbufferHeight > 0 ? $this->backbufferHeight : $this->height;
        $this->setUniformVec2('u_viewport_size', [(float)$vw, (float)$vh]);

        // Instancing off by default
        $this->setUniformInt('u_use_instancing', 0);

        // Re-bind dummy textures (created during shader init) and reset flags
        glActiveTexture(GL_TEXTURE5);
        glBindTexture(GL_TEXTURE_CUBE_MAP, $this->dummyCubemap);
        $this->setUniformInt('u_has_environment_map', 0);
        $this->setUniformVec3('u_sky_color', [0.55, 0.70, 0.85]);
        $this->setUniformVec3('u_horizon_color', [0.53, 0.68, 0.80]);

        glActiveTexture(GL_TEXTURE6);
        glBindTexture(GL_TEXTURE_2D, $this->dummyDepthTex);
        $this->setUniformInt('u_has_shadow_map', 0);

        glActiveTexture(GL_TEXTURE7);
        glBindTexture(GL_TEXTURE_2D, $this->dummyCloudTex);
        $this->setUniformInt('u_has_cloud_shadow', 0);

        glActiveTexture(GL_TEXTURE0);
        $this->setUniformFloat('u_moon_phase', 0.5);
        $this->setUniformVec3('u_season_tint', [1.0, 1.0, 1.0]); // no tint by default

        // Time + wave animation defaults
        $this->globalTime += 0.016; // ~60fps increment
        $this->setUniformFloat('u_time', $this->globalTime);
        $this->setUniformInt('u_vertex_anim', 0);
        $this->setUniformFloat('u_wave_amplitude', 0.0);
        $this->setUniformFloat('u_wave_frequency', 0.0);
        $this->setUniformFloat('u_wave_phase', 0.0);

        // Cloth defaults: disabled until a Material with cloth=true binds,
        // wind defaults to "calm air" so cloth-enabled materials still
        // animate subtly when the game pushes no SetWind command.
        $this->windDirection = [0.0, 0.0, 1.0];
        $this->windIntensity = 0.5;
        $this->setUniformVec3('u_wind_direction', $this->windDirection);
        $this->setUniformFloat('u_wind_intensity', $this->windIntensity);
        $this->setUniformInt('u_cloth', 0);
        $this->setUniformVec3('u_mesh_local_aabb_min', [0.0, 0.0, 0.0]);
        $this->setUniformVec3('u_mesh_local_aabb_max', [0.0, 0.0, 0.0]);

        $this->checkGLError('render() setup');

        // Pass 1: collect non-draw commands
        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof SetCamera) {
                $this->currentViewMatrix = $command->viewMatrix;
                $this->currentProjectionMatrix = $command->projectionMatrix;
                $this->setUniformMat4('u_view', $command->viewMatrix);
                $this->setUniformMat4('u_projection', $this->jitteredProjection($command->projectionMatrix));

                $cameraPos = $command->viewMatrix->inverse()->getTranslation();
                $this->currentCameraPos = $cameraPos;
                $this->setUniformVec3('u_camera_pos', [$cameraPos->x, $cameraPos->y, $cameraPos->z]);

            } elseif ($command instanceof SetAmbientLight) {
                $this->setUniformVec3('u_ambient_color', [$command->color->r, $command->color->g, $command->color->b]);
                $this->setUniformFloat('u_ambient_intensity', $command->intensity);

            } elseif ($command instanceof SetDirectionalLight && $this->dirLightCount < 16) {
                $i = $this->dirLightCount;
                $this->dirLights[$i] = [
                    'dir' => [$command->direction->x, $command->direction->y, $command->direction->z],
                    'color' => [$command->color->r, $command->color->g, $command->color->b],
                    'intensity' => $command->intensity,
                ];
                $this->dirLightCount++;

            } elseif ($command instanceof AddPointLight && $this->pointLightCount < 8) {
                $this->pointLights[$this->pointLightCount] = [
                    'pos'       => [$command->position->x, $command->position->y, $command->position->z],
                    'color'     => [$command->color->r, $command->color->g, $command->color->b],
                    'intensity' => $command->intensity,
                    'radius'    => $command->radius,
                ];
                $this->pointLightCount++;

            } elseif ($command instanceof AddSpotLight && $this->spotLightCount < 8) {
                $this->spotLights[$this->spotLightCount] = [
                    'pos'       => [$command->position->x, $command->position->y, $command->position->z],
                    'dir'       => [$command->direction->x, $command->direction->y, $command->direction->z],
                    'color'     => [$command->color->r, $command->color->g, $command->color->b],
                    'intensity' => $command->intensity,
                    'range'     => $command->range,
                    'angle'     => $command->angle,
                    'penumbra'  => $command->penumbra,
                ];
                $this->spotLightCount++;

            } elseif ($command instanceof SetFog) {
                if ($this->settings->fog) {
                    $this->setUniformVec3('u_fog_color', [$command->color->r, $command->color->g, $command->color->b]);
                    $clampedFar = min($command->far, $this->settings->viewDistance);
                    $clampedNear = min($command->near, $clampedFar - 1.0);
                    $this->setUniformFloat('u_fog_near', $clampedNear);
                    $this->setUniformFloat('u_fog_far', $clampedFar);
                } else {
                    // Push fog beyond any reasonable view distance to neutralise it.
                    $this->setUniformFloat('u_fog_near', 99998.0);
                    $this->setUniformFloat('u_fog_far', 99999.0);
                }

            } elseif ($command instanceof SetSkybox) {
                $this->pendingSkyboxId = $command->cubemapId;

            } elseif ($command instanceof SetWind) {
                $this->windDirection = [
                    $command->direction->x,
                    $command->direction->y,
                    $command->direction->z,
                ];
                $this->windIntensity = $command->intensity;
                $this->setUniformVec3('u_wind_direction', $this->windDirection);
                $this->setUniformFloat('u_wind_intensity', $this->windIntensity);

            } elseif ($command instanceof Command\SetSkyColors) {
                $this->setUniformVec3('u_sky_color', [$command->skyColor->r, $command->skyColor->g, $command->skyColor->b]);
                $this->setUniformVec3('u_horizon_color', [$command->horizonColor->r, $command->horizonColor->g, $command->horizonColor->b]);

            } elseif ($command instanceof Command\SetEnvironmentMap) {
                glActiveTexture(GL_TEXTURE5);
                glBindTexture(GL_TEXTURE_CUBE_MAP, $command->textureId);
                $this->setUniformInt('u_environment_map', 5);
                $this->setUniformInt('u_has_environment_map', 1);

            } elseif ($command instanceof Command\SetFieldtracing) {
                // Per-frame override of the settings-derived tier, capability-gated.
                $ftMode = $this->gateFieldtracing($command->mode);
                $this->setUniformFloat('u_ft_mode', (float) $this->fieldtracingModeCode($ftMode));
                $this->setUniformFloat('u_ft_intensity', $command->intensity);
                $this->setUniformFloat('u_ft_ao', $command->aoRadius);
            }
        }

        // Upload directional lights
        $this->setUniformInt('u_dir_light_count', $this->dirLightCount);
        for ($i = 0; $i < $this->dirLightCount; $i++) {
            $dl = $this->dirLights[$i];
            $this->setUniformVec3("u_dir_lights[{$i}].direction", $dl['dir']);
            $this->setUniformVec3("u_dir_lights[{$i}].color", $dl['color']);
            $this->setUniformFloat("u_dir_lights[{$i}].intensity", $dl['intensity']);
        }

        // Upload point lights
        $this->setUniformInt('u_point_light_count', $this->pointLightCount);
        for ($i = 0; $i < $this->pointLightCount; $i++) {
            $pl = $this->pointLights[$i];
            $this->setUniformVec3("u_point_lights[{$i}].position", $pl['pos']);
            $this->setUniformVec3("u_point_lights[{$i}].color", $pl['color']);
            $this->setUniformFloat("u_point_lights[{$i}].intensity", $pl['intensity']);
            $this->setUniformFloat("u_point_lights[{$i}].radius", $pl['radius']);
        }

        // Upload spot lights
        $this->setUniformInt('u_spot_light_count', $this->spotLightCount);
        for ($i = 0; $i < $this->spotLightCount; $i++) {
            $sl = $this->spotLights[$i];
            $this->setUniformVec3("u_spot_lights[{$i}].position", $sl['pos']);
            $this->setUniformVec3("u_spot_lights[{$i}].direction", $sl['dir']);
            $this->setUniformVec3("u_spot_lights[{$i}].color", $sl['color']);
            $this->setUniformFloat("u_spot_lights[{$i}].intensity", $sl['intensity']);
            $this->setUniformFloat("u_spot_lights[{$i}].range", $sl['range']);
            $this->setUniformFloat("u_spot_lights[{$i}].angle", $sl['angle']);
            $this->setUniformFloat("u_spot_lights[{$i}].penumbra", $sl['penumbra']);
        }

        // Shadow map pass: render scene depth from sun's perspective
        $this->renderShadowMap($commandList);

        // Restore main viewport + framebuffer. When the offscreen pipeline is
        // active we have to re-bind the offscreen draw target *and* match its
        // (potentially scaled) viewport. Otherwise the shadow pass has left us
        // bound to the shadow FBO and at the shadow-map viewport.
        if ($this->offscreenActive && $this->offscreenTarget !== null) {
            $this->offscreenTarget->bindForDraw();
        } else {
            glBindFramebuffer(0x8D40 /* GL_FRAMEBUFFER */, 0);
            glViewport(0, 0, $this->width, $this->height);
        }
        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof SetCamera) {
                $this->setUniformMat4('u_view', $command->viewMatrix);
                // Use already Y-flipped projection stored in pass 1
                if ($this->currentProjectionMatrix !== null) {
                    $this->setUniformMat4('u_projection', $this->jitteredProjection($this->currentProjectionMatrix));
                }
                $cameraPos = $command->viewMatrix->inverse()->getTranslation();
                $this->setUniformVec3('u_camera_pos', [$cameraPos->x, $cameraPos->y, $cameraPos->z]);
                break;
            }
        }

        // Re-upload directional lights (shadow pass zeroed them)
        $this->setUniformInt('u_dir_light_count', $this->dirLightCount);
        for ($i = 0; $i < $this->dirLightCount; $i++) {
            $dl = $this->dirLights[$i];
            $this->setUniformVec3("u_dir_lights[{$i}].direction", $dl['dir']);
            $this->setUniformVec3("u_dir_lights[{$i}].color", $dl['color']);
            $this->setUniformFloat("u_dir_lights[{$i}].intensity", $dl['intensity']);
        }

        // Pass 2a: opaque
        glDepthMask(true);
        glDisable(GL_BLEND);
        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof SetShader) {
                $this->shaderOverride = $command->shaderId;
            } elseif ($command instanceof SetWaveAnimation) {
                $this->setUniformInt('u_vertex_anim', $command->enabled ? 1 : 0);
                $this->setUniformFloat('u_wave_amplitude', $command->amplitude);
                $this->setUniformFloat('u_wave_frequency', $command->frequency);
                $this->setUniformFloat('u_wave_phase', $command->phase);
            } elseif ($command instanceof DrawMesh) {
                $mat = MaterialRegistry::get($command->materialId);
                if ($mat === null || $mat->alpha >= 1.0) {
                    $this->activateShaderForMaterial($mat);
                    $this->drawMeshCommand($command->meshId, $command->materialId, $command->modelMatrix);
                }
            } elseif ($command instanceof DrawMeshInstanced) {
                $mat = MaterialRegistry::get($command->materialId);
                if ($mat === null || $mat->alpha >= 1.0) {
                    $this->activateShaderForMaterial($mat);
                    $this->drawMeshInstancedCommand($command);
                }
            }
        }

        // Pass 2b: transparent
        glDepthMask(false);
        glEnable(GL_BLEND);
        glBlendFunc(GL_SRC_ALPHA, GL_ONE_MINUS_SRC_ALPHA);
        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof SetShader) {
                $this->shaderOverride = $command->shaderId;
            } elseif ($command instanceof SetWaveAnimation) {
                $this->setUniformInt('u_vertex_anim', $command->enabled ? 1 : 0);
                $this->setUniformFloat('u_wave_amplitude', $command->amplitude);
                $this->setUniformFloat('u_wave_frequency', $command->frequency);
                $this->setUniformFloat('u_wave_phase', $command->phase);
            } elseif ($command instanceof DrawMesh) {
                $mat = MaterialRegistry::get($command->materialId);
                if ($mat !== null && $mat->alpha < 1.0) {
                    $this->activateShaderForMaterial($mat);
                    $this->drawMeshCommand($command->meshId, $command->materialId, $command->modelMatrix);
                }
            } elseif ($command instanceof DrawMeshInstanced) {
                $mat = MaterialRegistry::get($command->materialId);
                if ($mat !== null && $mat->alpha < 1.0) {
                    $this->activateShaderForMaterial($mat);
                    $this->drawMeshInstancedCommand($command);
                }
            }
        }
        glDepthMask(true);
        glDisable(GL_BLEND);

        // Pass 3: skybox
        if ($this->pendingSkyboxId !== null && $this->currentViewMatrix !== null && $this->currentProjectionMatrix !== null) {
            $this->renderSkybox($this->pendingSkyboxId);
            $this->pendingSkyboxId = null;
        }

    }

    /**
     * Draw a single mesh with a model matrix uniform (non-instanced path).
     */
    private function drawMeshCommand(string $meshId, string $materialId, Mat4 $modelMatrix): void
    {
        $meshData = MeshRegistry::get($meshId);
        if ($meshData === null) {
            return;
        }

        if (!isset($this->vaoCache[$meshId])) {
            $this->uploadMesh($meshId, $meshData);
        }

        // Ensure instancing is off
        $this->setUniformInt('u_use_instancing', 0);

        $this->setUniformMat4('u_model', $modelMatrix);
        $this->applyMaterial($materialId);
        $this->bindMeshAabb($meshId);

        glBindVertexArray($this->vaoCache[$meshId]);
        glDrawElements(GL_TRIANGLES, $this->indexCountCache[$meshId], GL_UNSIGNED_INT, 0);
        $this->checkGLError("drawMeshCommand({$meshId}, {$materialId})");
        glBindVertexArray(0);
    }

    /**
     * Draw multiple instances of the same mesh in a single GPU call.
     * Uses the original shared-VAO architecture (one VAO + one VBO per meshId).
     *
     * When $isStatic is true, the PHP-side matrix serialization is cached —
     * the pre-built FloatBuffer is reused, skipping the expensive foreach loop.
     * glBufferData is still called each frame (cheap GPU upload) but the PHP
     * overhead of iterating 260k+ floats is eliminated.
     */
    private function drawMeshInstancedCommand(DrawMeshInstanced $command): void
    {
        // Hot path: read the public properties directly rather than via
        // effectiveInstanceCount() / hasFlatMatrices(). For instanced
        // building districts at 1000+ instances, the extra method-call
        // dispatch was a measurable regression vs. the legacy direct
        // count($matrices) - the boxes-1000-instanced benchmark broke
        // its 15% guard band on the first run.
        $instanceCount = $command->instanceCount >= 0
            ? $command->instanceCount
            : count($command->matrices);
        if ($instanceCount <= 0) {
            return;
        }
        $isFlat = $command->flatMatrices !== [];

        $meshId    = $command->meshId;
        $materialId = $command->materialId;
        $isStatic  = $command->isStatic;

        $meshData = MeshRegistry::get($meshId);
        if ($meshData === null) {
            return;
        }

        if (!isset($this->instancedVaoCache[$meshId])) {
            $this->uploadInstancedMesh($meshId, $meshData);
        }

        $cacheKey = $meshId . ':' . $materialId;

        if ($isStatic && isset($this->staticFloatBufferCache[$cacheKey])) {
            $buffer = $this->staticFloatBufferCache[$cacheKey];
            $instanceCount = $this->staticInstanceCountCache[$cacheKey];
        } else {
            if ($isFlat) {
                // Flat path: caller already produced a column-major
                // float[] of length instanceCount * 16. Skip the
                // per-instance toArray() flatten loop entirely.
                $buffer = new FloatBuffer($command->flatMatrices);
            } else {
                $floats = [];
                foreach ($command->matrices as $matrix) {
                    foreach ($matrix->toArray() as $v) {
                        $floats[] = $v;
                    }
                }
                $buffer = new FloatBuffer($floats);
            }

            if ($isStatic) {
                $this->staticFloatBufferCache[$cacheKey] = $buffer;
                $this->staticInstanceCountCache[$cacheKey] = $instanceCount;
            }
        }

        glBindVertexArray($this->instancedVaoCache[$meshId]);

        // Create or reuse shared instance VBO
        if (!isset($this->instanceVboCache[$meshId])) {
            $vbo = 0;
            glGenBuffers(1, $vbo);
            if (!is_int($vbo) || $vbo === 0) {
                glBindVertexArray(0);
                return;
            }
            $this->instanceVboCache[$meshId] = $vbo;

            glBindBuffer(GL_ARRAY_BUFFER, $vbo);
            $mat4Stride = 16 * 4;
            for ($col = 0; $col < 4; $col++) {
                $loc = 3 + $col;
                glVertexAttribPointer($loc, 4, GL_FLOAT, false, $mat4Stride, $col * 4 * 4);
                glEnableVertexAttribArray($loc);
                glVertexAttribDivisor($loc, 1);
            }
        }

        // Upload instance data (cheap GPU operation — FloatBuffer already built)
        glBindBuffer(GL_ARRAY_BUFFER, $this->instanceVboCache[$meshId]);
        glBufferData(GL_ARRAY_BUFFER, $buffer, GL_DYNAMIC_DRAW);

        // Enable instancing in shader
        $this->setUniformInt('u_use_instancing', 1);

        $this->applyMaterial($materialId);
        $this->bindMeshAabb($meshId);

        glDrawArraysInstanced(
            GL_TRIANGLES,
            0,
            $this->expandedVertexCount[$meshId],
            $instanceCount,
        );
        $this->checkGLError("drawMeshInstanced({$meshId}, {$materialId}, n={$instanceCount})");

        $this->setUniformInt('u_use_instancing', 0);

        glBindVertexArray(0);
    }

    /**
     * Upload an expanded (non-indexed) mesh VAO for instanced rendering.
     * glDrawArraysInstanced requires non-indexed vertices.
     */
    private function uploadInstancedMesh(string $meshId, MeshData $meshData): void
    {
        $vao = 0;
        glGenVertexArrays(1, $vao);
        if ($vao === 0) {
            throw new \RuntimeException('glGenVertexArrays failed (instanced)');
        }
        glBindVertexArray($vao);

        // Expand indexed mesh to non-indexed: duplicate vertices per triangle
        $expanded = [];
        $indexCount = count($meshData->indices);
        for ($i = 0; $i < $indexCount; $i++) {
            $idx = $meshData->indices[$i];
            $expanded[] = $meshData->vertices[$idx * 3];
            $expanded[] = $meshData->vertices[$idx * 3 + 1];
            $expanded[] = $meshData->vertices[$idx * 3 + 2];
            $expanded[] = $meshData->normals[$idx * 3];
            $expanded[] = $meshData->normals[$idx * 3 + 1];
            $expanded[] = $meshData->normals[$idx * 3 + 2];
            $expanded[] = $meshData->uvs[$idx * 2];
            $expanded[] = $meshData->uvs[$idx * 2 + 1];
        }

        $vbo = 0;
        glGenBuffers(1, $vbo);
        if (!is_int($vbo) || $vbo === 0) {
            throw new \RuntimeException('glGenBuffers failed (instanced VBO)');
        }
        glBindBuffer(GL_ARRAY_BUFFER, $vbo);
        glBufferData(GL_ARRAY_BUFFER, new FloatBuffer($expanded), GL_STATIC_DRAW);

        $stride = 8 * 4;
        glVertexAttribPointer(0, 3, GL_FLOAT, false, $stride, 0);
        glEnableVertexAttribArray(0);
        glVertexAttribPointer(1, 3, GL_FLOAT, false, $stride, 3 * 4);
        glEnableVertexAttribArray(1);
        glVertexAttribPointer(2, 2, GL_FLOAT, false, $stride, 6 * 4);
        glEnableVertexAttribArray(2);

        $this->checkGLError("uploadInstancedMesh({$meshId})");

        glBindVertexArray(0);

        $this->instancedVaoCache[$meshId] = $vao;
        $this->expandedVertexCount[$meshId] = $indexCount;
    }

    /** @var array<string, int> Material prefix → proc_mode cache */
    private static array $procModeCache = [];

    /**
     * @var array<string, array{min: array{0:float,1:float,2:float}, max: array{0:float,1:float,2:float}}>
     *      Mesh-id → local-space AABB cache. Computed on first access in
     *      meshAabb(). Drives the cloth-sway anchor weighting; not used
     *      for culling.
     */
    private array $meshAabbCache = [];

    /** Current frame's wind state, fed by SetWind commands. */
    /** @var array{0:float, 1:float, 2:float} */
    private array $windDirection = [0.0, 0.0, 1.0];
    private float $windIntensity = 0.5;

    /**
     * Activate the correct shader program for a draw call.
     * Priority: SetShader override > Material's shader > default.
     * Re-uploads per-frame uniforms when switching programs.
     */
    private function activateShaderForMaterial(?Material $material): void
    {
        $shaderId = $this->shaderOverride ?? ($material !== null ? $material->shader : 'default');
        $program = $this->resolveShaderProgram($shaderId);

        if ($this->activeProgram !== $program) {
            $this->useShaderProgram($program);
            $this->reuploadFrameUniforms();
        }
    }

    /**
     * Re-upload per-frame uniforms (camera, lights, fog, time) after a shader switch.
     * Custom shaders only receive uniforms they declare — missing uniforms are silently ignored.
     */
    private function reuploadFrameUniforms(): void
    {
        if ($this->currentViewMatrix !== null) {
            $this->setUniformMat4('u_view', $this->currentViewMatrix);
        }
        if ($this->currentProjectionMatrix !== null) {
            // Skybox can stay un-jittered - the texture is constant across
            // frames so jittering its sample positions adds noise without
            // helping the temporal blend converge. Use the base matrix.
            $this->setUniformMat4('u_projection', $this->currentProjectionMatrix);
        }
        if ($this->currentViewMatrix !== null) {
            $cameraPos = $this->currentViewMatrix->inverse()->getTranslation();
            $this->setUniformVec3('u_camera_pos', [$cameraPos->x, $cameraPos->y, $cameraPos->z]);
        }

        $this->setUniformFloat('u_time', $this->globalTime);

        // Lights
        $this->setUniformInt('u_dir_light_count', $this->dirLightCount);
        for ($i = 0; $i < $this->dirLightCount; $i++) {
            $dl = $this->dirLights[$i];
            $this->setUniformVec3("u_dir_lights[{$i}].direction", $dl['dir']);
            $this->setUniformVec3("u_dir_lights[{$i}].color", $dl['color']);
            $this->setUniformFloat("u_dir_lights[{$i}].intensity", $dl['intensity']);
        }
        $this->setUniformInt('u_point_light_count', $this->pointLightCount);
        for ($i = 0; $i < $this->pointLightCount; $i++) {
            $pl = $this->pointLights[$i];
            $this->setUniformVec3("u_point_lights[{$i}].position", $pl['pos']);
            $this->setUniformVec3("u_point_lights[{$i}].color", $pl['color']);
            $this->setUniformFloat("u_point_lights[{$i}].intensity", $pl['intensity']);
            $this->setUniformFloat("u_point_lights[{$i}].radius", $pl['radius']);
        }
        $this->setUniformInt('u_spot_light_count', $this->spotLightCount);
        for ($i = 0; $i < $this->spotLightCount; $i++) {
            $sl = $this->spotLights[$i];
            $this->setUniformVec3("u_spot_lights[{$i}].position", $sl['pos']);
            $this->setUniformVec3("u_spot_lights[{$i}].direction", $sl['dir']);
            $this->setUniformVec3("u_spot_lights[{$i}].color", $sl['color']);
            $this->setUniformFloat("u_spot_lights[{$i}].intensity", $sl['intensity']);
            $this->setUniformFloat("u_spot_lights[{$i}].range", $sl['range']);
            $this->setUniformFloat("u_spot_lights[{$i}].angle", $sl['angle']);
            $this->setUniformFloat("u_spot_lights[{$i}].penumbra", $sl['penumbra']);
        }
    }

    private function applyMaterial(string $materialId): void
    {
        // Procedural material mode — cached lookup by prefix
        $procMode = self::$procModeCache[$materialId] ?? $this->resolveProcMode($materialId);
        $this->setUniformInt('u_proc_mode', $procMode);

        // Moon phase: encoded in roughness of moon_disc material by DayNightSystem
        if ($procMode === 9) {
            $mat = MaterialRegistry::get($materialId);
            $this->setUniformFloat('u_moon_phase', $mat !== null ? $mat->roughness : 0.5);
        }

        // Sand terrain: pass albedo as season tint (shader multiplies with hardcoded base colors)
        if ($procMode === 1) {
            $mat = MaterialRegistry::get($materialId);
            if ($mat !== null) {
                // Normalize: default sand color is ~(0.77, 0.66, 0.41). Tint = actual / default.
                $this->setUniformVec3('u_season_tint', [
                    $mat->albedo->r / 0.77,
                    $mat->albedo->g / 0.66,
                    $mat->albedo->b / 0.41,
                ]);
            }
        } else {
            $this->setUniformVec3('u_season_tint', [1.0, 1.0, 1.0]);
        }

        $material = MaterialRegistry::get($materialId);
        if ($material !== null) {
            $this->setUniformVec3('u_albedo', [$material->albedo->r, $material->albedo->g, $material->albedo->b]);
            $this->setUniformVec3('u_emission', [$material->emission->r, $material->emission->g, $material->emission->b]);
            $this->setUniformFloat('u_roughness', $material->roughness);
            $this->setUniformFloat('u_metallic', $material->metallic);
            $this->setUniformFloat('u_alpha', $material->alpha);
            $this->setUniformFloat('u_clearcoat', $material->clearcoat);
            $this->setUniformFloat('u_clearcoat_roughness', $material->clearcoatRoughness);
            $this->setUniformFloat('u_flakes', $material->flakes);
            $this->setUniformFloat('u_normal_intensity', $material->normalIntensity);
            $this->setUniformInt('u_use_environment_map', $material->useEnvironmentMap ? 1 : 0);
            $this->setUniformInt('u_normal_pattern', NormalPattern::codeFor($material->normalPattern));
            $this->setUniformFloat('u_normal_scale', $material->normalScale);
            $this->setUniformInt('u_surface_pattern', SurfacePattern::codeFor($material->surfacePattern));
            $this->setUniformFloat('u_surface_scale', $material->surfaceScale);
            $this->setUniformFloat('u_surface_intensity', $material->surfaceIntensity);
            $this->setUniformFloat('u_wetness', $material->wetness);
            $this->setUniformInt('u_cloth', $material->cloth ? 1 : 0);
            $this->setUniformFloat('u_cloth_strength', $material->clothStrength);
            $this->setUniformFloat('u_cloth_frequency', $material->clothFrequency);
            $this->setUniformFloat('u_cloth_phase', $material->clothPhase);
            $this->setUniformInt('u_cloth_anchor_top', $material->clothAnchorTop ? 1 : 0);
        } else {
            $this->setUniformVec3('u_albedo', [0.8, 0.8, 0.8]);
            $this->setUniformVec3('u_emission', [0.0, 0.0, 0.0]);
            $this->setUniformFloat('u_roughness', 0.5);
            $this->setUniformFloat('u_metallic', 0.0);
            $this->setUniformFloat('u_alpha', 1.0);
            $this->setUniformFloat('u_clearcoat', 0.0);
            $this->setUniformFloat('u_clearcoat_roughness', 0.05);
            $this->setUniformFloat('u_flakes', 0.0);
            $this->setUniformFloat('u_normal_intensity', 1.0);
            $this->setUniformInt('u_use_environment_map', 1);
            $this->setUniformInt('u_normal_pattern', 0);
            $this->setUniformFloat('u_normal_scale', 1.0);
            $this->setUniformInt('u_surface_pattern', 0);
            $this->setUniformFloat('u_surface_scale', 1.0);
            $this->setUniformFloat('u_surface_intensity', 1.0);
            $this->setUniformFloat('u_wetness', 0.0);
            $this->setUniformInt('u_cloth', 0);
            $this->setUniformFloat('u_cloth_strength', 0.05);
            $this->setUniformFloat('u_cloth_frequency', 1.0);
            $this->setUniformFloat('u_cloth_phase', 0.0);
            $this->setUniformInt('u_cloth_anchor_top', 1);
        }

        // Mesh-local AABB drives the cloth anchor weighting. Computed
        // and cached the first time the mesh is uploaded; this keeps
        // the binding cheap on every material apply (no PHP-side
        // recomputation per draw).
    }

    /**
     * Push the AABB of the mesh that the next draw will use. Called
     * by the per-draw site after applyMaterial().
     */
    private function bindMeshAabb(string $meshId): void
    {
        $aabb = $this->meshAabb($meshId);
        $this->setUniformVec3('u_mesh_local_aabb_min', $aabb['min']);
        $this->setUniformVec3('u_mesh_local_aabb_max', $aabb['max']);
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
     * Resolve proc_mode from material ID prefix. Result is cached for performance.
     */
    private function resolveProcMode(string $materialId): int
    {
        // Extract prefix (first segment before '_' + number, or full ID)
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
            // Ruined / not-yet-rebuilt CodeCity district buildings — weathered,
            // cracked, moss/soot-stained concrete shading on the intact box
            // geometry. Stays LIT. See mesh3d.frag.glsl proc_mode 13. Kept in
            // lock-step with VioRenderer3D. 'district_ruined' has no digits →
            // prefix == full id.
            str_starts_with($prefix, 'district_ruined') => 13,
            // Self-illuminated learning hologram (HologramBoardPrefab baked-text
            // materials). Unlit. NOTE: the OpenGL mesh3d shader has no albedo
            // texture sampler, so this emits flat u_albedo (no baked text); the
            // baked-text hologram is a vio/D3D12-path feature. See proc_mode 12.
            str_starts_with($prefix, 'hologram_text') => 12,
            // Sci-fi holo-console accent surfaces (TerminalPrefab): screen panel,
            // accent rim and input light-bar. Unlit — accent colour lives in
            // albedo so it glows the same day and night (flat u_albedo here).
            str_starts_with($prefix, 'terminal_screen_lit'),
            str_starts_with($prefix, 'terminal_glow_edge'),
            str_starts_with($prefix, 'terminal_lightbar') => 12,
            default => 0,
        };

        self::$procModeCache[$materialId] = $mode;
        return $mode;
    }

    private function uploadMesh(string $meshId, MeshData $meshData): void
    {
        $vao = 0;
        glGenVertexArrays(1, $vao);
        if ($vao === 0) {
            throw new \RuntimeException('glGenVertexArrays failed');
        }
        glBindVertexArray($vao);

        // Interleaved VBO: position(3) + normal(3) + uv(2) = 8 floats per vertex
        $vertexCount = $meshData->vertexCount();
        $interleaved = [];
        for ($i = 0; $i < $vertexCount; $i++) {
            $interleaved[] = (float) $meshData->vertices[$i * 3];
            $interleaved[] = (float) $meshData->vertices[$i * 3 + 1];
            $interleaved[] = (float) $meshData->vertices[$i * 3 + 2];
            $interleaved[] = (float) $meshData->normals[$i * 3];
            $interleaved[] = (float) $meshData->normals[$i * 3 + 1];
            $interleaved[] = (float) $meshData->normals[$i * 3 + 2];
            $interleaved[] = (float) $meshData->uvs[$i * 2];
            $interleaved[] = (float) $meshData->uvs[$i * 2 + 1];
        }

        $vbo = 0;
        glGenBuffers(1, $vbo);
        if (!is_int($vbo) || $vbo === 0) {
            throw new \RuntimeException('glGenBuffers failed (VBO)');
        }
        glBindBuffer(GL_ARRAY_BUFFER, $vbo);
        glBufferData(GL_ARRAY_BUFFER, new FloatBuffer($interleaved), GL_STATIC_DRAW);

        $stride = 8 * 4;
        glVertexAttribPointer(0, 3, GL_FLOAT, false, $stride, 0);
        glEnableVertexAttribArray(0);
        glVertexAttribPointer(1, 3, GL_FLOAT, false, $stride, 3 * 4);
        glEnableVertexAttribArray(1);
        glVertexAttribPointer(2, 2, GL_FLOAT, false, $stride, 6 * 4);
        glEnableVertexAttribArray(2);

        // EBO
        $ebo = 0;
        glGenBuffers(1, $ebo);
        if (!is_int($ebo) || $ebo === 0) {
            throw new \RuntimeException('glGenBuffers failed (EBO)');
        }
        glBindBuffer(GL_ELEMENT_ARRAY_BUFFER, $ebo);
        glBufferData(GL_ELEMENT_ARRAY_BUFFER, new IntBuffer($meshData->indices), GL_STATIC_DRAW);

        $this->checkGLError("uploadMesh({$meshId})");

        glBindVertexArray(0);

        $this->vaoCache[$meshId]        = $vao;
        $this->indexCountCache[$meshId] = count($meshData->indices);
    }

    /**
     * Compile and link a shader program from a ShaderDefinition.
     * Returns the GL program handle. Result is cached in shaderProgramCache.
     */
    private function compileShader(string $shaderId, ShaderDefinition $definition): int
    {
        $vertSource = $this->loadShaderSource($definition->vertexPath);
        $fragSource = $this->loadShaderSource($definition->fragmentPath);

        $vert = glCreateShader(GL_VERTEX_SHADER);
        glShaderSource($vert, $vertSource);
        glCompileShader($vert);

        $vertStatus = 0;
        glGetShaderiv($vert, GL_COMPILE_STATUS, $vertStatus);
        if (!$vertStatus) {
            $log = glGetShaderInfoLog($vert, 4096);
            throw new \RuntimeException("Vertex shader compile error ({$shaderId}):\n{$log}");
        }

        $frag = glCreateShader(GL_FRAGMENT_SHADER);
        glShaderSource($frag, $fragSource);
        glCompileShader($frag);

        $fragStatus = 0;
        glGetShaderiv($frag, GL_COMPILE_STATUS, $fragStatus);
        if (!$fragStatus) {
            $log = glGetShaderInfoLog($frag, 4096);
            throw new \RuntimeException("Fragment shader compile error ({$shaderId}):\n{$log}");
        }

        $program = glCreateProgram();
        glAttachShader($program, $vert);
        glAttachShader($program, $frag);
        glLinkProgram($program);

        $linkStatus = 0;
        glGetProgramiv($program, GL_LINK_STATUS, $linkStatus);
        if (!$linkStatus) {
            $log = glGetProgramInfoLog($program, 4096);
            throw new \RuntimeException("Shader program link error ({$shaderId}):\n{$log}");
        }

        glDeleteShader($vert);
        glDeleteShader($frag);

        $this->shaderProgramCache[$shaderId] = $program;
        return $program;
    }

    /**
     * Resolve a shader ID to a compiled GL program. Compiles on first use.
     * Falls back to 'default' if the requested shader is not registered.
     */
    private function resolveShaderProgram(string $shaderId): int
    {
        if (isset($this->shaderProgramCache[$shaderId])) {
            return $this->shaderProgramCache[$shaderId];
        }

        $definition = ShaderRegistry::get($shaderId);
        if ($definition === null) {
            // Fall back to default
            return $this->shaderProgramCache['default'];
        }

        return $this->compileShader($shaderId, $definition);
    }

    /**
     * Switch the active GL program if it differs from the current one.
     */
    private function useShaderProgram(int $program): void
    {
        if ($this->activeProgram !== $program) {
            glUseProgram($program);
            $this->activeProgram = $program;
        }
    }

    private function initShaders(): void
    {
        $defaultDef = ShaderRegistry::get('default');
        if ($defaultDef === null) {
            throw new \RuntimeException('Default shader not registered in ShaderRegistry');
        }

        $program = $this->compileShader('default', $defaultDef);
        $this->activeProgram = $program;

        // Create dummy textures and bind samplers immediately after linking.
        // This prevents "unloadable texture" warnings on the first frame.
        glUseProgram($program);

        // Dummy cubemap (1×1, for u_environment_map on unit 5)
        $dcm = 0;
        glGenTextures(1, $dcm);
        $this->dummyCubemap = $dcm;
        glBindTexture(GL_TEXTURE_CUBE_MAP, $this->dummyCubemap);
        for ($face = 0; $face < 6; $face++) {
            glTexImage2D(GL_TEXTURE_CUBE_MAP_POSITIVE_X + $face, 0, GL_RGB, 1, 1, 0, GL_RGB, GL_UNSIGNED_BYTE, null);
        }
        glTexParameteri(GL_TEXTURE_CUBE_MAP, GL_TEXTURE_MIN_FILTER, GL_LINEAR);
        glTexParameteri(GL_TEXTURE_CUBE_MAP, GL_TEXTURE_MAG_FILTER, GL_LINEAR);
        glActiveTexture(GL_TEXTURE5);
        glBindTexture(GL_TEXTURE_CUBE_MAP, $this->dummyCubemap);
        $this->setUniformInt('u_environment_map', 5);

        // Dummy depth texture (1×1, for u_shadow_map on unit 6)
        $ddt = 0;
        glGenTextures(1, $ddt);
        $this->dummyDepthTex = $ddt;
        glBindTexture(GL_TEXTURE_2D, $this->dummyDepthTex);
        glTexImage2D(GL_TEXTURE_2D, 0, GL_DEPTH_COMPONENT, 1, 1, 0, GL_DEPTH_COMPONENT, GL_FLOAT, null);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_LINEAR);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_LINEAR);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_COMPARE_MODE, GL_COMPARE_REF_TO_TEXTURE);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_COMPARE_FUNC, GL_LEQUAL);
        glActiveTexture(GL_TEXTURE6);
        glBindTexture(GL_TEXTURE_2D, $this->dummyDepthTex);
        $this->setUniformInt('u_shadow_map', 6);

        // Dummy cloud shadow (1×1, for u_cloud_shadow_map on unit 7)
        $dcs = 0;
        glGenTextures(1, $dcs);
        $this->dummyCloudTex = $dcs;
        glBindTexture(GL_TEXTURE_2D, $this->dummyCloudTex);
        glTexImage2D(GL_TEXTURE_2D, 0, GL_R8, 1, 1, 0, GL_RED, GL_UNSIGNED_BYTE, null);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_LINEAR);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_LINEAR);
        glActiveTexture(GL_TEXTURE7);
        glBindTexture(GL_TEXTURE_2D, $this->dummyCloudTex);
        $this->setUniformInt('u_cloud_shadow_map', 7);

        glActiveTexture(GL_TEXTURE0);
    }

    private function loadShaderSource(string $path): string
    {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException("Cannot read shader file: {$path}");
        }
        return $source;
    }

    private function getUniformLocation(string $name): int
    {
        $program = $this->activeProgram;
        if (isset($this->uniformLocationCache[$program][$name])) {
            return $this->uniformLocationCache[$program][$name];
        }
        $loc = glGetUniformLocation($program, $name);
        $this->uniformLocationCache[$program][$name] = $loc;
        return $loc;
    }

    private function setUniformMat4(string $name, Mat4 $matrix): void
    {
        $loc = $this->getUniformLocation($name);
        if ($loc >= 0) {
            glUniformMatrix4fv($loc, false, new FloatBuffer($matrix->toArray()));
        }
    }

    /** @param float[] $value */
    private function setUniformVec3(string $name, array $value): void
    {
        $loc = $this->getUniformLocation($name);
        if ($loc >= 0) {
            glUniform3f($loc, $value[0], $value[1], $value[2]);
        }
    }

    /** @param float[] $value */
    private function setUniformVec2(string $name, array $value): void
    {
        $loc = $this->getUniformLocation($name);
        if ($loc >= 0) {
            glUniform2f($loc, $value[0], $value[1]);
        }
    }

    /**
     * Apply the per-frame TAA sub-pixel jitter to a base projection matrix
     * when TAA is active. The jitter is added as a translation in NDC -
     * matrix entries [8] and [9] are the projection's z-column x/y values
     * (column-major), which translate post-projection clip-space xy.
     *
     * Returns the matrix unchanged when TAA is not active so callers can
     * use this transparently.
     */
    /**
     * Free GL resources owned by post-process passes + shadow map. Called
     * automatically when the renderer is garbage-collected; games can also
     * invoke it explicitly during scene transitions to free GPU memory
     * before the new scene's allocations land.
     */
    public function __destruct()
    {
        $this->fxaaPass?->release();
        $this->fxaaPass = null;
        $this->ssrPass?->release();
        $this->ssrPass = null;
        $this->taaPass?->release();
        $this->taaPass = null;
        $this->shadowMap?->release();
        $this->shadowMap = null;
    }

    /**
     * Apply the per-frame TAA sub-pixel jitter to a base projection matrix.
     *
     * Mat4 stores its values column-major in toArray(): entries [8] and
     * [9] are proj[2][0] and proj[2][1] - the z-column x/y entries. Adding
     * `+ndc` to those shifts the post-perspective-divide vertex by the
     * offset along x/y in NDC. The temporal blend only requires a
     * deterministic per-frame offset, not a particular sign; a future
     * motion-vector / reprojection pass must adopt the same convention.
     *
     * Returns the matrix unchanged when TAA is not active so callers can
     * use this transparently.
     */
    private function jitteredProjection(Mat4 $base): Mat4
    {
        if ($this->settings->antiAliasing !== AntiAliasing::Taa || $this->offscreenTarget === null) {
            return $base;
        }
        [$jx, $jy] = TaaJitter::offset($this->frameIndex, $this->offscreenTarget->width(), $this->offscreenTarget->height());
        // Convert texel offset to NDC offset: 1 texel = 2 / dim NDC units.
        $ndcX = $jx * 2.0;
        $ndcY = $jy * 2.0;
        $arr = $base->toArray();
        $arr[8]  += $ndcX;
        $arr[9]  += $ndcY;
        return new Mat4($arr);
    }

    private function setUniformFloat(string $name, float $value): void
    {
        $loc = $this->getUniformLocation($name);
        if ($loc >= 0) {
            glUniform1f($loc, $value);
        }
    }

    private function setUniformInt(string $name, int $value): void
    {
        $loc = $this->getUniformLocation($name);
        if ($loc >= 0) {
            glUniform1i($loc, $value);
        }
    }

    // ─── Skybox ───────────────────────────────────────────────────────────────

    private function initSkybox(): void
    {
        $this->resolveShaderProgram('skybox');

        /** @var float[] $skyboxVertices */
        $skyboxVertices = [
            -1.0,  1.0, -1.0,  -1.0, -1.0, -1.0,   1.0, -1.0, -1.0,   1.0, -1.0, -1.0,   1.0,  1.0, -1.0,  -1.0,  1.0, -1.0,
            -1.0, -1.0,  1.0,  -1.0, -1.0, -1.0,  -1.0,  1.0, -1.0,  -1.0,  1.0, -1.0,  -1.0,  1.0,  1.0,  -1.0, -1.0,  1.0,
             1.0, -1.0, -1.0,   1.0, -1.0,  1.0,   1.0,  1.0,  1.0,   1.0,  1.0,  1.0,   1.0,  1.0, -1.0,   1.0, -1.0, -1.0,
            -1.0, -1.0,  1.0,  -1.0,  1.0,  1.0,   1.0,  1.0,  1.0,   1.0,  1.0,  1.0,   1.0, -1.0,  1.0,  -1.0, -1.0,  1.0,
            -1.0,  1.0, -1.0,   1.0,  1.0, -1.0,   1.0,  1.0,  1.0,   1.0,  1.0,  1.0,  -1.0,  1.0,  1.0,  -1.0,  1.0, -1.0,
            -1.0, -1.0, -1.0,  -1.0, -1.0,  1.0,   1.0, -1.0, -1.0,   1.0, -1.0, -1.0,  -1.0, -1.0,  1.0,   1.0, -1.0,  1.0,
        ];

        $vao = 0;
        glGenVertexArrays(1, $vao);
        if ($vao === 0) {
            throw new \RuntimeException('Failed to create skybox VAO');
        }
        glBindVertexArray($vao);

        $vbo = 0;
        glGenBuffers(1, $vbo);
        if (!is_int($vbo) || $vbo === 0) {
            throw new \RuntimeException('Failed to create skybox VBO');
        }
        glBindBuffer(GL_ARRAY_BUFFER, $vbo);
        glBufferData(GL_ARRAY_BUFFER, new FloatBuffer($skyboxVertices), GL_STATIC_DRAW);

        glVertexAttribPointer(0, 3, GL_FLOAT, false, 3 * 4, 0);
        glEnableVertexAttribArray(0);

        glBindVertexArray(0);
        $this->skyboxVao = $vao;
    }

    private function loadCubemap(string $cubemapId): int
    {
        if (isset($this->cubemapCache[$cubemapId])) {
            return $this->cubemapCache[$cubemapId];
        }

        $faces = CubemapRegistry::get($cubemapId);
        if ($faces === null) {
            return 0;
        }

        $texId = 0;
        glGenTextures(1, $texId);
        if ($texId === 0) {
            return 0;
        }
        glBindTexture(GL_TEXTURE_CUBE_MAP, $texId);

        $facePaths = $faces->toArray();
        for ($i = 0; $i < 6; $i++) {
            $img = @imagecreatefrompng($facePaths[$i]);
            if ($img === false) {
                $img = @imagecreatefromjpeg($facePaths[$i]);
            }
            if ($img === false) {
                glDeleteTextures(1, $texId);
                return 0;
            }

            $w = imagesx($img);
            $h = imagesy($img);
            $data = '';
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $rgb = imagecolorat($img, $x, $y);
                    $data .= chr(($rgb >> 16) & 0xFF) . chr(($rgb >> 8) & 0xFF) . chr($rgb & 0xFF);
                }
            }
            unset($img); // GdImage is freed by GC; imagedestroy() is a no-op deprecated in PHP 8.5

            $unpacked = unpack('C*', $data);
            /** @var array<int> $bytes */
            $bytes = $unpacked !== false ? array_values($unpacked) : [];
            $buffer = new \GL\Buffer\UByteBuffer($bytes);
            glTexImage2D(GL_TEXTURE_CUBE_MAP_POSITIVE_X + $i, 0, GL_RGB, $w, $h, 0, GL_RGB, GL_UNSIGNED_BYTE, $buffer);
        }

        glTexParameteri(GL_TEXTURE_CUBE_MAP, GL_TEXTURE_MIN_FILTER, GL_LINEAR);
        glTexParameteri(GL_TEXTURE_CUBE_MAP, GL_TEXTURE_MAG_FILTER, GL_LINEAR);
        glTexParameteri(GL_TEXTURE_CUBE_MAP, GL_TEXTURE_WRAP_S, GL_CLAMP_TO_EDGE);
        glTexParameteri(GL_TEXTURE_CUBE_MAP, GL_TEXTURE_WRAP_T, GL_CLAMP_TO_EDGE);
        glTexParameteri(GL_TEXTURE_CUBE_MAP, GL_TEXTURE_WRAP_R, GL_CLAMP_TO_EDGE);

        glBindTexture(GL_TEXTURE_CUBE_MAP, 0);
        $this->cubemapCache[$cubemapId] = $texId;
        return $texId;
    }

    /**
     * Render shadow map from directional light's perspective.
     * Uses a simple depth-only pass with the same meshes as the main scene.
     */
    private function renderShadowMap(RenderCommandList $commandList): void
    {
        // ShadowQuality::Off forces u_has_shadow_map = 0 and skips the pass entirely.
        if ($this->settings->shadowQuality === \PHPolygon\Rendering\Quality\ShadowQuality::Off) {
            $this->setUniformInt('u_has_shadow_map', 0);
            $this->setUniformInt('u_has_cloud_shadow', 0);
            return;
        }

        // Lazy-init shadow map at the resolution dictated by current settings.
        // Three cascades cover near (15 m), mid (50 m), and far (150 m)
        // ortho boxes - the shader picks one per fragment based on view-
        // space distance so close-up shadows stay sharp while distant ones
        // still resolve.
        if ($this->shadowMap === null) {
            $resolution = $this->settings->shadowQuality->resolution();
            if ($resolution <= 0) {
                $resolution = 2048;
            }
            $this->shadowMap = new ShadowMapRenderer(
                resolution: $resolution,
                orthoSize:  [15.0, 50.0, 150.0],
            );
            $this->shadowMap->initialize();
        }
        $shadowMap = $this->shadowMap;

        // Use the BRIGHTEST directional light for shadow casting (sun by day, moon by night)
        $lightDir = null;
        $lightIntensity = 0.0;
        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof SetDirectionalLight && $command->intensity > $lightIntensity) {
                $lightDir = $command->direction;
                $lightIntensity = $command->intensity;
            }
        }

        // No shadows when no light or too dim
        if ($lightDir === null || $lightIntensity < 0.05) {
            $this->setUniformInt('u_has_shadow_map', 0);
            return;
        }

        // Update light-space matrix. Centre the shadow frustum on the
        // camera so shadows resolve where the player is looking - prevents
        // the open-world "matschig at distance" failure mode of an
        // origin-pinned shadow map.
        $shadowCenter = $this->currentViewMatrix?->inverse()->getTranslation();
        $shadowMap->updateLightMatrix($lightDir, $shadowCenter);

        // Ensure all sampler units have valid textures during shadow passes
        glActiveTexture(GL_TEXTURE6);
        glBindTexture(GL_TEXTURE_2D, $this->dummyDepthTex);
        glActiveTexture(GL_TEXTURE7);
        glBindTexture(GL_TEXTURE_2D, $this->dummyCloudTex);
        glActiveTexture(GL_TEXTURE0);

        // Shadow pass per cascade. Each cascade owns its own FBO + light-
        // space matrix; geometry is drawn once per cascade with the
        // matching matrix. The cost is 3x draw count for casters, but the
        // shadow shader is depth-only and the meshes are tiny GPU work.
        $shadowProgram = $this->resolveShaderProgram('shadow');
        $cascadeCount  = $shadowMap->cascadeCount();
        $cascadeMatrices = [];

        for ($cIdx = 0; $cIdx < $cascadeCount; $cIdx++) {
            $shadowMap->beginShadowPass($cIdx);
            $this->useShaderProgram($shadowProgram);

            $lsmCascade = $shadowMap->getLightSpaceMatrixAt($cIdx);
            $cascadeMatrices[$cIdx] = $lsmCascade;
            $this->setUniformMat4('u_view', $lsmCascade);
            $this->setUniformMat4('u_projection', \PHPolygon\Math\Mat4::identity());

            foreach ($commandList->getCommands() as $command) {
                if ($command instanceof DrawMesh) {
                    $mat = MaterialRegistry::get($command->materialId);
                    if ($mat !== null && $mat->alpha >= 0.9) {
                        $matId = $command->materialId;
                        if (str_starts_with($matId, 'sky_') || str_starts_with($matId, 'sun_')
                            || str_starts_with($matId, 'moon_') || str_starts_with($matId, 'cloud_')
                            || $matId === 'precipitation') {
                            continue;
                        }
                        $this->setUniformMat4('u_model', $command->modelMatrix);
                        $meshData = MeshRegistry::get($command->meshId);
                        if ($meshData === null) continue;
                        if (!isset($this->vaoCache[$command->meshId])) {
                            $this->uploadMesh($command->meshId, $meshData);
                        }
                        $this->setUniformInt('u_use_instancing', 0);
                        glBindVertexArray($this->vaoCache[$command->meshId]);
                        glDrawElements(GL_TRIANGLES, $this->indexCountCache[$command->meshId], GL_UNSIGNED_INT, 0);
                        glBindVertexArray(0);
                    }
                }
            }
        }

        $shadowMap->endShadowPass();
        // Cascade 0's light-space matrix is what cloud shadows + the
        // legacy single-map fallback expect.
        $lsm = $cascadeMatrices[0] ?? $shadowMap->getLightSpaceMatrix();

        // Cloud shadows are an opt-in volumetric effect.
        if (!$this->settings->cloudShadows) {
            $this->setUniformInt('u_has_cloud_shadow', 0);
            while (glGetError() !== 0) {}
            $this->useShaderProgram($this->shaderProgramCache['default']);
            $this->bindCascadeUniforms($shadowMap, $cascadeMatrices);
            glClear(GL_DEPTH_BUFFER_BIT);
            return;
        }

        // --- Cloud shadow pass: render cloud opacity from sun's perspective ---
        if ($this->cloudShadow === null) {
            $this->cloudShadow = new CloudShadowRenderer(resolution: 1024);
            $this->cloudShadow->initialize();
        }
        $cloudShadow = $this->cloudShadow;

        $cloudShadow->beginPass();
        $unlitProgram = $this->resolveShaderProgram('unlit');
        $this->useShaderProgram($unlitProgram);

        // Same light-space view as geometry shadows
        $this->setUniformMat4('u_view', $lsm);
        $this->setUniformMat4('u_projection', \PHPolygon\Math\Mat4::identity());
        // Disable fog for cloud shadow pass (unlit shader needs valid fog range)
        $this->setUniformFloat('u_fog_near', 9999.0);
        $this->setUniformFloat('u_fog_far', 10000.0);
        $this->setUniformVec3('u_camera_pos', [0.0, 0.0, 0.0]);

        // Render ONLY clouds — their alpha determines shadow opacity
        $hasCloudGeometry = false;
        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof DrawMesh) {
                $matId = $command->materialId;
                if (!str_starts_with($matId, 'cloud_')) continue;

                $mat = MaterialRegistry::get($matId);
                if ($mat === null) continue;

                // Set cloud alpha as the output color (R channel = opacity)
                $opacity = (1.0 - ($mat->alpha ?? 1.0)) * 0.5 + 0.1; // thicker clouds = more shadow
                $this->setUniformVec3('u_albedo', [$opacity, $opacity, $opacity]);
                $this->setUniformVec3('u_emission', [0.0, 0.0, 0.0]);
                $this->setUniformFloat('u_alpha', 1.0);

                $this->setUniformMat4('u_model', $command->modelMatrix);
                $meshData = MeshRegistry::get($command->meshId);
                if ($meshData === null) continue;
                if (!isset($this->vaoCache[$command->meshId])) {
                    $this->uploadMesh($command->meshId, $meshData);
                }
                $this->setUniformInt('u_use_instancing', 0);
                glBindVertexArray($this->vaoCache[$command->meshId]);
                glDrawElements(GL_TRIANGLES, $this->indexCountCache[$command->meshId], GL_UNSIGNED_INT, 0);
                glBindVertexArray(0);
                $hasCloudGeometry = true;
            }
        }

        $cloudShadow->endPass();

        // Drain any GL errors from shadow/cloud passes before the main pass
        // so they don't accumulate and get blamed on the first drawMeshCommand.
        while (glGetError() !== 0) {}

        // Bind both shadow maps for main pass
        $this->useShaderProgram($this->shaderProgramCache['default']);
        $this->bindCascadeUniforms($shadowMap, $cascadeMatrices);

        if ($hasCloudGeometry) {
            $cloudShadow->bind(7);
            $this->setUniformInt('u_cloud_shadow_map', 7);
            $this->setUniformInt('u_has_cloud_shadow', 1);
        }

        // Restore depth buffer for main pass
        glClear(GL_DEPTH_BUFFER_BIT);
    }

    /**
     * Push every cascade's depth texture + light-space matrix + far-plane
     * into the default mesh shader. Cascade 0 also drives the legacy
     * single-map uniforms (`u_shadow_map`, `u_light_space_matrix`) so any
     * shader path that hasn't been migrated to CSM still works.
     *
     * Texture-unit budget:
     *   6  = shadow cascade 0 (legacy slot)
     *   7  = cloud shadow map (separate channel)
     *   8  = shadow cascade 1
     *   9  = shadow cascade 2
     *
     * @param array<int, \PHPolygon\Math\Mat4> $cascadeMatrices
     */
    private function bindCascadeUniforms(ShadowMapRenderer $shadowMap, array $cascadeMatrices): void
    {
        $count = $shadowMap->cascadeCount();
        $orthos = $shadowMap->cascadeOrthoSizes();

        // Cascade 0 -> legacy single-map slot (unit 6) so old shader paths still work.
        $shadowMap->bind(6, 0);
        $this->setUniformInt('u_shadow_map', 6);
        $this->setUniformMat4('u_light_space_matrix', $cascadeMatrices[0] ?? $shadowMap->getLightSpaceMatrix());
        $this->setUniformInt('u_has_shadow_map', 1);

        // Cascade-array uniforms.
        $this->setUniformInt('u_csm_count', $count);
        $this->setUniformMat4('u_csm_matrix_0', $cascadeMatrices[0] ?? $shadowMap->getLightSpaceMatrix());
        $this->setUniformFloat('u_csm_far_0', $orthos[0] ?? 60.0);
        $this->setUniformInt('u_csm_map_0', 6);

        if ($count > 1) {
            $shadowMap->bind(8, 1);
            $this->setUniformInt('u_csm_map_1', 8);
            $this->setUniformMat4('u_csm_matrix_1', $cascadeMatrices[1] ?? $shadowMap->getLightSpaceMatrix());
            $this->setUniformFloat('u_csm_far_1', $orthos[1] ?? 120.0);
        } else {
            $this->setUniformInt('u_csm_map_1', 6);
            $this->setUniformMat4('u_csm_matrix_1', $cascadeMatrices[0] ?? $shadowMap->getLightSpaceMatrix());
            $this->setUniformFloat('u_csm_far_1', $orthos[0] ?? 60.0);
        }

        if ($count > 2) {
            $shadowMap->bind(9, 2);
            $this->setUniformInt('u_csm_map_2', 9);
            $this->setUniformMat4('u_csm_matrix_2', $cascadeMatrices[2] ?? $shadowMap->getLightSpaceMatrix());
            $this->setUniformFloat('u_csm_far_2', $orthos[2] ?? 200.0);
        } else {
            $this->setUniformInt('u_csm_map_2', 6);
            $this->setUniformMat4('u_csm_matrix_2', $cascadeMatrices[0] ?? $shadowMap->getLightSpaceMatrix());
            $this->setUniformFloat('u_csm_far_2', $orthos[0] ?? 60.0);
        }
    }

    private function renderSkybox(string $cubemapId): void
    {
        $texId = $this->loadCubemap($cubemapId);
        if ($texId === 0) {
            return;
        }

        glDepthFunc(GL_LEQUAL);
        $this->useShaderProgram($this->resolveShaderProgram('skybox'));

        if ($this->currentViewMatrix !== null) {
            $this->setUniformMat4('u_view', $this->currentViewMatrix);
        }
        if ($this->currentProjectionMatrix !== null) {
            $this->setUniformMat4('u_projection', $this->currentProjectionMatrix);
        }

        glActiveTexture(GL_TEXTURE0);
        glBindTexture(GL_TEXTURE_CUBE_MAP, $texId);
        $this->setUniformInt('u_skybox', 0);

        glBindVertexArray($this->skyboxVao);
        glDrawArrays(GL_TRIANGLES, 0, 36);
        glBindVertexArray(0);

        glDepthFunc(GL_LESS);
        $this->useShaderProgram($this->shaderProgramCache['default']);
    }

    /**
     * Check for OpenGL errors and log them to stderr.
     * Drains all pending errors. Returns true if any error was found.
     */
    private function checkGLError(string $context): bool
    {
        $hadError = false;
        while (($err = glGetError()) !== 0) {
            $name = self::GL_ERROR_NAMES[$err] ?? sprintf('0x%04X', $err);
            fwrite(STDERR, "[GL ERROR] {$name} in {$context}\n");
            $hadError = true;
        }
        return $hadError;
    }

    /**
     * Capability-gate a Fieldtracing tier. OpenGL has 3D textures (core since
     * GL 1.2), so no tier needs degrading here; the helper exists for symmetry
     * with the other backends and a single place to add future gates.
     */
    private function gateFieldtracing(Quality\FieldtracingMode $mode): Quality\FieldtracingMode
    {
        return $mode;
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
     * Apply live graphics settings.
     *
     * Hot-swappable: shadow-map size, shadow disable, anisotropy, fog toggle,
     * cloud-shadow toggle, view-distance clamp, shader override (handled via
     * ShaderManager + SetShader command, not here), render scale and AA mode
     * (Phase 1.5: off-screen FBO + FXAA + MSAA renderbuffer).
     */
    public function applySettings(GraphicsSettings $settings): void
    {
        $previousShadow = $this->settings->shadowQuality;
        $this->settings = $settings;

        // Rebuild shadow map if the resolution tier changed
        if ($previousShadow !== $settings->shadowQuality) {
            $this->shadowMap = null;
        }

        // Anisotropy is applied per-texture-upload by the TextureManager.
        // No direct GL state to flush here - subsequent loads use the new value.

        // Render-scale and AA changes take effect on the next beginFrame().
        // We could rebuild the offscreen target here proactively, but the
        // backbuffer dimensions may not be authoritative until the frame
        // starts; delaying keeps the code simpler and the visible result
        // identical (one frame of latency on the user's quality slider).
        $this->resizeOffscreenIfNeeded();
    }

    public function getSettings(): GraphicsSettings
    {
        return $this->settings;
    }

    /**
     * Decide whether the offscreen pipeline is needed, allocate or resize the
     * target, and bind it. Called from beginFrame() so the 3D pass renders
     * into the offscreen FBO. Returns silently when the fast path applies.
     *
     * Fast path: renderScale == 1.0 AND antiAliasing == Off. The 3D pass
     * draws directly into the backbuffer like before this phase.
     */
    private function beginOffscreenIfRequired(): void
    {
        $needsOffscreen = $this->offscreenIsActive();
        if (!$needsOffscreen) {
            $this->offscreenActive = false;
            return;
        }

        $targetW = max(1, (int)round($this->backbufferWidth  * $this->settings->renderScale));
        $targetH = max(1, (int)round($this->backbufferHeight * $this->settings->renderScale));
        $samples = max(1, $this->settings->antiAliasing->sampleCount());

        if ($this->offscreenTarget === null) {
            $this->offscreenTarget = new OpenGLOffscreenTarget();
        }
        $this->offscreenTarget->resize($targetW, $targetH, $samples);

        if ($this->settings->antiAliasing === AntiAliasing::Fxaa && $this->fxaaPass === null) {
            $this->fxaaPass = new OpenGLFxaaPass(__DIR__ . '/../../resources/shaders/source');
        }

        if ($this->settings->ssr !== ScreenSpaceReflections::Off && $this->ssrPass === null) {
            $this->ssrPass = new OpenGLSsrPass(__DIR__ . '/../../resources/shaders/source');
        }

        if ($this->settings->antiAliasing === AntiAliasing::Taa && $this->taaPass === null) {
            $this->taaPass = new OpenGLTaaPass(__DIR__ . '/../../resources/shaders/source');
        }

        $this->offscreenTarget->bindForDraw();
        $this->offscreenActive = true;
    }

    /**
     * Recompute target size when applySettings() flips render scale or AA mode
     * mid-flight. Avoids waiting a full frame before the change is reflected
     * in the FBO allocation.
     */
    private function resizeOffscreenIfNeeded(): void
    {
        if ($this->offscreenTarget === null || $this->backbufferWidth <= 0 || $this->backbufferHeight <= 0) {
            return;
        }

        if (!$this->offscreenIsActive()) {
            $this->offscreenTarget->release();
            return;
        }

        $targetW = max(1, (int)round($this->backbufferWidth  * $this->settings->renderScale));
        $targetH = max(1, (int)round($this->backbufferHeight * $this->settings->renderScale));
        $samples = max(1, $this->settings->antiAliasing->sampleCount());
        $this->offscreenTarget->resize($targetW, $targetH, $samples);
    }

    private function offscreenIsActive(): bool
    {
        if ($this->settings->renderScale !== 1.0) {
            return true;
        }

        if ($this->settings->antiAliasing !== AntiAliasing::Off) {
            // FXAA, MSAA2x/4x, AND TAA all need the resolved colour
            // texture as a sampler target.
            return true;
        }

        // SSR samples the resolved scene + depth, which only exist when
        // the offscreen pipeline is on.
        return $this->settings->ssr !== ScreenSpaceReflections::Off;
    }
}
