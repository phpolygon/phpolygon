<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use GL\Buffer\FloatBuffer;
use GL\Buffer\IntBuffer;
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
use PHPolygon\Rendering\Command\SetShader;
use PHPolygon\Rendering\Command\SetSkybox;
use PHPolygon\Rendering\Command\SetWaveAnimation;

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

    private ?string $pendingSkyboxId = null;

    private ?Mat4 $currentViewMatrix = null;
    private ?Mat4 $currentProjectionMatrix = null;


    /** Global time for shader animations (seconds since start) */
    private float $globalTime = 0.0;
    private int $dummyCubemap = 0;
    private int $dummyDepthTex = 0;
    private int $dummyCloudTex = 0;
    private ?ShadowMapRenderer $shadowMap = null;
    private ?CloudShadowRenderer $cloudShadow = null;

    public function __construct(int $width = 1280, int $height = 720)
    {
        $this->width  = $width;
        $this->height = $height;

        // Register all built-in shaders (games can override by registering before construction)
        $builtins = [
            'default' => ['mesh3d.vert.glsl', 'mesh3d.frag.glsl'],
            'unlit'   => ['unlit.vert.glsl', 'unlit.frag.glsl'],
            'normals' => ['normals.vert.glsl', 'normals.frag.glsl'],
            'depth'   => ['depth.vert.glsl', 'depth.frag.glsl'],
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
    }

    public function endFrame(): void {}

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
        glClear(GL_DEPTH_BUFFER_BIT);
        $this->pointLightCount = 0;
        $this->pointLights     = [];
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

        $this->checkGLError('render() setup');

        // Pass 1: collect non-draw commands
        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof SetCamera) {
                $this->currentViewMatrix = $command->viewMatrix;
                $this->currentProjectionMatrix = $command->projectionMatrix;
                $this->setUniformMat4('u_view', $command->viewMatrix);
                $this->setUniformMat4('u_projection', $command->projectionMatrix);

                $cameraPos = $command->viewMatrix->inverse()->getTranslation();
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

            } elseif ($command instanceof SetFog) {
                $this->setUniformVec3('u_fog_color', [$command->color->r, $command->color->g, $command->color->b]);
                $this->setUniformFloat('u_fog_near', $command->near);
                $this->setUniformFloat('u_fog_far', $command->far);

            } elseif ($command instanceof SetSkybox) {
                $this->pendingSkyboxId = $command->cubemapId;

            } elseif ($command instanceof Command\SetSkyColors) {
                $this->setUniformVec3('u_sky_color', [$command->skyColor->r, $command->skyColor->g, $command->skyColor->b]);
                $this->setUniformVec3('u_horizon_color', [$command->horizonColor->r, $command->horizonColor->g, $command->horizonColor->b]);

            } elseif ($command instanceof Command\SetEnvironmentMap) {
                glActiveTexture(GL_TEXTURE5);
                glBindTexture(GL_TEXTURE_CUBE_MAP, $command->textureId);
                $this->setUniformInt('u_environment_map', 5);
                $this->setUniformInt('u_has_environment_map', 1);
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

        // Shadow map pass: render scene depth from sun's perspective
        $this->renderShadowMap($commandList);

        // Restore main viewport, camera, and lights after shadow pass
        glViewport(0, 0, $this->width, $this->height);
        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof SetCamera) {
                $this->setUniformMat4('u_view', $command->viewMatrix);
                // Use already Y-flipped projection stored in pass 1
                if ($this->currentProjectionMatrix !== null) {
                    $this->setUniformMat4('u_projection', $this->currentProjectionMatrix);
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
                    $this->drawMeshInstancedCommand($command->meshId, $command->materialId, $command->matrices, $command->isStatic);
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
                    $this->drawMeshInstancedCommand($command->meshId, $command->materialId, $command->matrices, $command->isStatic);
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
     *
     * @param Mat4[] $matrices
     */
    private function drawMeshInstancedCommand(string $meshId, string $materialId, array $matrices, bool $isStatic = false): void
    {
        if (empty($matrices)) {
            return;
        }

        $meshData = MeshRegistry::get($meshId);
        if ($meshData === null) {
            return;
        }

        if (!isset($this->instancedVaoCache[$meshId])) {
            $this->uploadInstancedMesh($meshId, $meshData);
        }

        $cacheKey = $meshId . ':' . $materialId;
        $fromCache = false;

        if ($isStatic && isset($this->staticFloatBufferCache[$cacheKey])) {
            $buffer = $this->staticFloatBufferCache[$cacheKey];
            $instanceCount = $this->staticInstanceCountCache[$cacheKey];
            $fromCache = true;
        } else {
            $floats = [];
            foreach ($matrices as $matrix) {
                foreach ($matrix->toArray() as $v) {
                    $floats[] = $v;
                }
            }
            $buffer = new FloatBuffer($floats);
            $instanceCount = count($matrices);

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
        if (!is_int($vao) || $vao === 0) {
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
        } else {
            $this->setUniformVec3('u_albedo', [0.8, 0.8, 0.8]);
            $this->setUniformVec3('u_emission', [0.0, 0.0, 0.0]);
            $this->setUniformFloat('u_roughness', 0.5);
            $this->setUniformFloat('u_metallic', 0.0);
            $this->setUniformFloat('u_alpha', 1.0);
        }
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

    private function uploadMesh(string $meshId, MeshData $meshData): void
    {
        $vao = 0;
        glGenVertexArrays(1, $vao);
        if (!is_int($vao) || $vao === 0) {
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
        if (!is_int($vao) || $vao === 0) {
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
            imagedestroy($img);

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
        // Lazy-init shadow map
        if ($this->shadowMap === null) {
            $this->shadowMap = new ShadowMapRenderer(resolution: 2048);
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

        // Update light-space matrix
        $shadowMap->updateLightMatrix($lightDir);

        // Ensure all sampler units have valid textures during shadow passes
        glActiveTexture(GL_TEXTURE6);
        glBindTexture(GL_TEXTURE_2D, $this->dummyDepthTex);
        glActiveTexture(GL_TEXTURE7);
        glBindTexture(GL_TEXTURE_2D, $this->dummyCloudTex);
        glActiveTexture(GL_TEXTURE0);

        // Shadow pass: render depth only
        $shadowMap->beginShadowPass();
        $this->useShaderProgram($this->shaderProgramCache['default']);

        // Set light-space matrix as view+projection for shadow pass
        $lsm = $shadowMap->getLightSpaceMatrix();
        $this->setUniformMat4('u_view', $lsm);
        $this->setUniformMat4('u_projection', \PHPolygon\Math\Mat4::identity());

        // Disable all fancy rendering for depth pass
        $this->setUniformInt('u_proc_mode', 0);
        $this->setUniformFloat('u_alpha', 1.0);
        $this->setUniformInt('u_vertex_anim', 0);
        $this->setUniformInt('u_dir_light_count', 0);
        $this->setUniformInt('u_has_shadow_map', 0);
        $this->setUniformInt('u_has_cloud_shadow', 0);

        // Draw only opaque geometry
        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof DrawMesh) {
                $mat = MaterialRegistry::get($command->materialId);
                // Skip sky, clouds, transparent — only solid geometry casts shadows
                if ($mat !== null && $mat->alpha >= 0.9) {
                    $matId = $command->materialId;
                    // Skip emission-only materials (sky, sun, moon, clouds)
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

        $shadowMap->endShadowPass();

        // --- Cloud shadow pass: render cloud opacity from sun's perspective ---
        if ($this->cloudShadow === null) {
            $this->cloudShadow = new CloudShadowRenderer(resolution: 1024);
            $this->cloudShadow->initialize();
        }
        $cloudShadow = $this->cloudShadow;

        $cloudShadow->beginPass();
        $this->useShaderProgram($this->shaderProgramCache['default']);

        // Same light-space view as geometry shadows
        $this->setUniformMat4('u_view', $lsm);
        $this->setUniformMat4('u_projection', \PHPolygon\Math\Mat4::identity());
        $this->setUniformInt('u_proc_mode', 0);
        $this->setUniformInt('u_vertex_anim', 0);

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

        // Bind both shadow maps for main pass
        $this->useShaderProgram($this->shaderProgramCache['default']);
        $shadowMap->bind(6);
        $this->setUniformInt('u_shadow_map', 6);
        $this->setUniformMat4('u_light_space_matrix', $lsm);
        $this->setUniformInt('u_has_shadow_map', 1);

        if ($hasCloudGeometry) {
            $cloudShadow->bind(7);
            $this->setUniformInt('u_cloud_shadow_map', 7);
            $this->setUniformInt('u_has_cloud_shadow', 1);
        }

        // Restore depth buffer for main pass
        glClear(GL_DEPTH_BUFFER_BIT);
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
}
