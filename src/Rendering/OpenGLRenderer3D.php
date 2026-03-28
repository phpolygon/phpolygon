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


    private int $shaderProgram = 0;
    private int $skyboxShaderProgram = 0;
    private int $skyboxVao = 0;

    /** @var array<string, int> GL cubemap texture IDs */
    private array $cubemapCache = [];

    private int $pointLightCount = 0;

    /** @var array<int, array{pos: float[], color: float[], intensity: float, radius: float}> */
    private array $pointLights = [];

    private ?string $pendingSkyboxId = null;

    private ?Mat4 $currentViewMatrix = null;
    private ?Mat4 $currentProjectionMatrix = null;

    /** Cached uniform location for u_use_instancing */
    private int $useInstancingLoc = -1;

    /** Global time for shader animations (seconds since start) */
    private float $globalTime = 0.0;

    public function __construct(int $width = 1280, int $height = 720)
    {
        $this->width  = $width;
        $this->height = $height;
        $this->initShaders();
        $this->initSkybox();
        $this->useInstancingLoc = glGetUniformLocation($this->shaderProgram, 'u_use_instancing');
    }

    public function beginFrame(): void
    {
        glEnable(GL_DEPTH_TEST);
        glDepthFunc(GL_LESS);
        glEnable(GL_MULTISAMPLE);
        glDisable(GL_CULL_FACE);
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
        glClear(GL_DEPTH_BUFFER_BIT);
        $this->pointLightCount = 0;
        $this->pointLights     = [];

        glUseProgram($this->shaderProgram);

        // Defaults
        $this->setUniformVec3('u_ambient_color', [1.0, 1.0, 1.0]);
        $this->setUniformFloat('u_ambient_intensity', 0.1);
        $this->setUniformVec3('u_dir_light_direction', [0.0, -1.0, 0.0]);
        $this->setUniformVec3('u_dir_light_color', [1.0, 1.0, 1.0]);
        $this->setUniformFloat('u_dir_light_intensity', 0.0);
        $this->setUniformFloat('u_fog_near', 50.0);
        $this->setUniformFloat('u_fog_far', 200.0);
        $this->setUniformVec3('u_fog_color', [0.5, 0.5, 0.5]);
        $this->setUniformVec3('u_albedo', [0.8, 0.8, 0.8]);

        // Instancing off by default
        if ($this->useInstancingLoc >= 0) {
            glUniform1i($this->useInstancingLoc, 0);
        }

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

            } elseif ($command instanceof SetDirectionalLight) {
                $this->setUniformVec3('u_dir_light_direction', [$command->direction->x, $command->direction->y, $command->direction->z]);
                $this->setUniformVec3('u_dir_light_color', [$command->color->r, $command->color->g, $command->color->b]);
                $this->setUniformFloat('u_dir_light_intensity', $command->intensity);

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
            }
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

        // Pass 2a: opaque
        glDepthMask(true);
        glDisable(GL_BLEND);
        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof SetWaveAnimation) {
                $this->setUniformInt('u_vertex_anim', $command->enabled ? 1 : 0);
                $this->setUniformFloat('u_wave_amplitude', $command->amplitude);
                $this->setUniformFloat('u_wave_frequency', $command->frequency);
                $this->setUniformFloat('u_wave_phase', $command->phase);
            } elseif ($command instanceof DrawMesh) {
                $mat = MaterialRegistry::get($command->materialId);
                if ($mat === null || $mat->alpha >= 1.0) {
                    $this->drawMeshCommand($command->meshId, $command->materialId, $command->modelMatrix);
                }
            } elseif ($command instanceof DrawMeshInstanced) {
                $mat = MaterialRegistry::get($command->materialId);
                if ($mat === null || $mat->alpha >= 1.0) {
                    $this->drawMeshInstancedCommand($command->meshId, $command->materialId, $command->matrices, $command->isStatic);
                }
            }
        }

        // Pass 2b: transparent
        glDepthMask(false);
        glEnable(GL_BLEND);
        glBlendFunc(GL_SRC_ALPHA, GL_ONE_MINUS_SRC_ALPHA);
        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof SetWaveAnimation) {
                $this->setUniformInt('u_vertex_anim', $command->enabled ? 1 : 0);
                $this->setUniformFloat('u_wave_amplitude', $command->amplitude);
                $this->setUniformFloat('u_wave_frequency', $command->frequency);
                $this->setUniformFloat('u_wave_phase', $command->phase);
            } elseif ($command instanceof DrawMesh) {
                $mat = MaterialRegistry::get($command->materialId);
                if ($mat !== null && $mat->alpha < 1.0) {
                    $this->drawMeshCommand($command->meshId, $command->materialId, $command->modelMatrix);
                }
            } elseif ($command instanceof DrawMeshInstanced) {
                $mat = MaterialRegistry::get($command->materialId);
                if ($mat !== null && $mat->alpha < 1.0) {
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
        if ($this->useInstancingLoc >= 0) {
            glUniform1i($this->useInstancingLoc, 0);
        }

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
        if ($this->useInstancingLoc >= 0) {
            glUniform1i($this->useInstancingLoc, 1);
        }

        $this->applyMaterial($materialId);

        glDrawArraysInstanced(
            GL_TRIANGLES,
            0,
            $this->expandedVertexCount[$meshId],
            $instanceCount,
        );
        $this->checkGLError("drawMeshInstanced({$meshId}, {$materialId}, n={$instanceCount})");

        if ($this->useInstancingLoc >= 0) {
            glUniform1i($this->useInstancingLoc, 0);
        }

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

    private function applyMaterial(string $materialId): void
    {
        // Procedural material mode: 0=standard, 1=sand, 2=water, 3=rock, 4=palm trunk, 5=palm leaf
        $procMode = 0;
        if (str_starts_with($materialId, 'sand_terrain')) {
            $procMode = 1;
        } elseif (str_starts_with($materialId, 'water_')) {
            $procMode = 2;
        } elseif (str_starts_with($materialId, 'rock')) {
            $procMode = 3;
        } elseif (str_starts_with($materialId, 'palm_trunk')) {
            $procMode = 4;
        } elseif (str_starts_with($materialId, 'palm_branch') || str_starts_with($materialId, 'palm_leaves') || str_starts_with($materialId, 'palm_leaf') || str_starts_with($materialId, 'palm_canopy') || str_starts_with($materialId, 'palm_frond')) {
            $procMode = 5;
        } elseif (str_starts_with($materialId, 'cloud_')) {
            $procMode = 6;
        }
        $this->setUniformInt('u_proc_mode', $procMode);

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

    private function initShaders(): void
    {
        $vertSource = $this->loadShaderSource(__DIR__ . '/../../resources/shaders/source/mesh3d.vert.glsl');
        $fragSource = $this->loadShaderSource(__DIR__ . '/../../resources/shaders/source/mesh3d.frag.glsl');

        $vert = glCreateShader(GL_VERTEX_SHADER);
        glShaderSource($vert, $vertSource);
        glCompileShader($vert);

        $vertStatus = 0;
        glGetShaderiv($vert, GL_COMPILE_STATUS, $vertStatus);
        if (!$vertStatus) {
            $log = glGetShaderInfoLog($vert, 4096);
            throw new \RuntimeException("Vertex shader compile error:\n{$log}");
        }

        $frag = glCreateShader(GL_FRAGMENT_SHADER);
        glShaderSource($frag, $fragSource);
        glCompileShader($frag);

        $fragStatus = 0;
        glGetShaderiv($frag, GL_COMPILE_STATUS, $fragStatus);
        if (!$fragStatus) {
            $log = glGetShaderInfoLog($frag, 4096);
            throw new \RuntimeException("Fragment shader compile error:\n{$log}");
        }

        $program = glCreateProgram();
        glAttachShader($program, $vert);
        glAttachShader($program, $frag);
        glLinkProgram($program);

        $linkStatus = 0;
        glGetProgramiv($program, GL_LINK_STATUS, $linkStatus);
        if (!$linkStatus) {
            $log = glGetProgramInfoLog($program, 4096);
            throw new \RuntimeException("Shader program link error:\n{$log}");
        }

        glDeleteShader($vert);
        glDeleteShader($frag);

        $this->shaderProgram = $program;
    }

    private function loadShaderSource(string $path): string
    {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException("Cannot read shader file: {$path}");
        }
        return $source;
    }

    private function setUniformMat4(string $name, Mat4 $matrix): void
    {
        $loc = glGetUniformLocation($this->shaderProgram, $name);
        if ($loc >= 0) {
            glUniformMatrix4fv($loc, false, new FloatBuffer($matrix->toArray()));
        }
    }

    /** @param float[] $value */
    private function setUniformVec3(string $name, array $value): void
    {
        $loc = glGetUniformLocation($this->shaderProgram, $name);
        if ($loc >= 0) {
            glUniform3f($loc, $value[0], $value[1], $value[2]);
        }
    }

    private function setUniformFloat(string $name, float $value): void
    {
        $loc = glGetUniformLocation($this->shaderProgram, $name);
        if ($loc >= 0) {
            glUniform1f($loc, $value);
        }
    }

    private function setUniformInt(string $name, int $value): void
    {
        $loc = glGetUniformLocation($this->shaderProgram, $name);
        if ($loc >= 0) {
            glUniform1i($loc, $value);
        }
    }

    // ─── Skybox ───────────────────────────────────────────────────────────────

    private function initSkybox(): void
    {
        $vertSource = $this->loadShaderSource(__DIR__ . '/../../resources/shaders/source/skybox.vert.glsl');
        $fragSource = $this->loadShaderSource(__DIR__ . '/../../resources/shaders/source/skybox.frag.glsl');

        $vert = glCreateShader(GL_VERTEX_SHADER);
        glShaderSource($vert, $vertSource);
        glCompileShader($vert);
        $vertStatus = 0;
        glGetShaderiv($vert, GL_COMPILE_STATUS, $vertStatus);
        if (!$vertStatus) {
            $log = glGetShaderInfoLog($vert, 4096);
            throw new \RuntimeException("Skybox vertex shader compile error:\n{$log}");
        }

        $frag = glCreateShader(GL_FRAGMENT_SHADER);
        glShaderSource($frag, $fragSource);
        glCompileShader($frag);
        $fragStatus = 0;
        glGetShaderiv($frag, GL_COMPILE_STATUS, $fragStatus);
        if (!$fragStatus) {
            $log = glGetShaderInfoLog($frag, 4096);
            throw new \RuntimeException("Skybox fragment shader compile error:\n{$log}");
        }

        $program = glCreateProgram();
        glAttachShader($program, $vert);
        glAttachShader($program, $frag);
        glLinkProgram($program);
        $linkStatus = 0;
        glGetProgramiv($program, GL_LINK_STATUS, $linkStatus);
        if (!$linkStatus) {
            $log = glGetProgramInfoLog($program, 4096);
            throw new \RuntimeException("Skybox shader link error:\n{$log}");
        }
        glDeleteShader($vert);
        glDeleteShader($frag);
        $this->skyboxShaderProgram = $program;

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
        if (!is_int($texId) || $texId === 0) {
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

    private function renderSkybox(string $cubemapId): void
    {
        $texId = $this->loadCubemap($cubemapId);
        if ($texId === 0) {
            return;
        }

        glDepthFunc(GL_LEQUAL);
        glUseProgram($this->skyboxShaderProgram);

        $viewLoc = glGetUniformLocation($this->skyboxShaderProgram, 'u_view');
        if ($viewLoc >= 0 && $this->currentViewMatrix !== null) {
            glUniformMatrix4fv($viewLoc, false, new FloatBuffer($this->currentViewMatrix->toArray()));
        }
        $projLoc = glGetUniformLocation($this->skyboxShaderProgram, 'u_projection');
        if ($projLoc >= 0 && $this->currentProjectionMatrix !== null) {
            glUniformMatrix4fv($projLoc, false, new FloatBuffer($this->currentProjectionMatrix->toArray()));
        }

        glActiveTexture(GL_TEXTURE0);
        glBindTexture(GL_TEXTURE_CUBE_MAP, $texId);
        $skyboxLoc = glGetUniformLocation($this->skyboxShaderProgram, 'u_skybox');
        if ($skyboxLoc >= 0) {
            glUniform1i($skyboxLoc, 0);
        }

        glBindVertexArray($this->skyboxVao);
        glDrawArrays(GL_TRIANGLES, 0, 36);
        glBindVertexArray(0);

        glDepthFunc(GL_LESS);
        glUseProgram($this->shaderProgram);
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
