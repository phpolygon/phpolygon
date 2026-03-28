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

/**
 * OpenGL 4.1 3D renderer. Translates a RenderCommandList into GL draw calls.
 * Requires an active GLFW/GL context before construction.
 */
class OpenGLRenderer3D implements Renderer3DInterface
{
    private int $width;
    private int $height;

    /** @var array<string, int> */
    private array $vaoCache = [];

    /** @var array<string, int> */
    private array $indexCountCache = [];

    private int $shaderProgram = 0;
    private int $skyboxShaderProgram = 0;
    private int $skyboxVao = 0;

    /** @var array<string, int> GL cubemap texture IDs */
    private array $cubemapCache = [];

    /** Accumulated per-frame point lights (capped at 8) */
    private int $pointLightCount = 0;

    /** @var array<int, array{pos: float[], color: float[], intensity: float, radius: float}> */
    private array $pointLights = [];

    private ?string $pendingSkyboxId = null;

    /** Cached view/projection matrices for skybox rendering */
    private ?Mat4 $currentViewMatrix = null;
    private ?Mat4 $currentProjectionMatrix = null;

    public function __construct(int $width = 1280, int $height = 720)
    {
        $this->width  = $width;
        $this->height = $height;
        $this->initShaders();
        $this->initSkybox();
    }

    public function beginFrame(): void
    {
        glEnable(GL_DEPTH_TEST);
        glDepthFunc(GL_LESS);
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
        glUseProgram($this->shaderProgram);

        // Set default ambient
        $this->setUniformVec3('u_ambient_color', [1.0, 1.0, 1.0]);
        $this->setUniformFloat('u_ambient_intensity', 0.1);

        // Defaults for lights and fog
        $this->setUniformVec3('u_dir_light_direction', [0.0, -1.0, 0.0]);
        $this->setUniformVec3('u_dir_light_color', [1.0, 1.0, 1.0]);
        $this->setUniformFloat('u_dir_light_intensity', 0.0);
        $this->setUniformFloat('u_fog_near', 50.0);
        $this->setUniformFloat('u_fog_far', 200.0);
        $this->setUniformVec3('u_fog_color', [0.5, 0.5, 0.5]);
        $this->setUniformVec3('u_albedo', [0.8, 0.8, 0.8]);

        // Pass 1: collect non-draw commands
        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof SetCamera) {
                $this->currentViewMatrix = $command->viewMatrix;
                $this->currentProjectionMatrix = $command->projectionMatrix;
                $this->setUniformMat4('u_view', $command->viewMatrix);
                $this->setUniformMat4('u_projection', $command->projectionMatrix);

                // Camera position for fog: inverse of view matrix, extract translation
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

        // Pass 2: draw calls
        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof DrawMesh) {
                $this->drawMeshCommand($command->meshId, $command->materialId, $command->modelMatrix);
            } elseif ($command instanceof DrawMeshInstanced) {
                foreach ($command->matrices as $matrix) {
                    $this->drawMeshCommand($command->meshId, $command->materialId, $matrix);
                }
            }
        }

        // Pass 3: skybox (drawn last with depth ≤ test so it fills background)
        if ($this->pendingSkyboxId !== null && $this->currentViewMatrix !== null && $this->currentProjectionMatrix !== null) {
            $this->renderSkybox($this->pendingSkyboxId);
            $this->pendingSkyboxId = null;
        }
    }

    private function drawMeshCommand(string $meshId, string $materialId, Mat4 $modelMatrix): void
    {
        $meshData = MeshRegistry::get($meshId);
        if ($meshData === null) {
            return; // Mesh not registered — skip silently
        }

        if (!isset($this->vaoCache[$meshId])) {
            $this->uploadMesh($meshId, $meshData);
        }

        $this->setUniformMat4('u_model', $modelMatrix);

        // Resolve material properties
        $material = MaterialRegistry::get($materialId);
        if ($material !== null) {
            $this->setUniformVec3('u_albedo', [$material->albedo->r, $material->albedo->g, $material->albedo->b]);
            $this->setUniformVec3('u_emission', [$material->emission->r, $material->emission->g, $material->emission->b]);
            $this->setUniformFloat('u_roughness', $material->roughness);
            $this->setUniformFloat('u_metallic', $material->metallic);
        } else {
            $this->setUniformVec3('u_albedo', [0.8, 0.8, 0.8]);
            $this->setUniformVec3('u_emission', [0.0, 0.0, 0.0]);
            $this->setUniformFloat('u_roughness', 0.5);
            $this->setUniformFloat('u_metallic', 0.0);
        }

        glBindVertexArray($this->vaoCache[$meshId]);
        glDrawElements(GL_TRIANGLES, $this->indexCountCache[$meshId], GL_UNSIGNED_INT, 0);
        glBindVertexArray(0);
    }

    private function uploadMesh(string $meshId, MeshData $meshData): void
    {
        // VAO
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
            // position
            $interleaved[] = $meshData->vertices[$i * 3];
            $interleaved[] = $meshData->vertices[$i * 3 + 1];
            $interleaved[] = $meshData->vertices[$i * 3 + 2];
            // normal
            $interleaved[] = $meshData->normals[$i * 3];
            $interleaved[] = $meshData->normals[$i * 3 + 1];
            $interleaved[] = $meshData->normals[$i * 3 + 2];
            // uv
            $interleaved[] = $meshData->uvs[$i * 2];
            $interleaved[] = $meshData->uvs[$i * 2 + 1];
        }

        $vbo = 0;
        glGenBuffers(1, $vbo);
        if (!is_int($vbo) || $vbo === 0) {
            throw new \RuntimeException('glGenBuffers failed (VBO)');
        }
        glBindBuffer(GL_ARRAY_BUFFER, $vbo);
        glBufferData(GL_ARRAY_BUFFER, new FloatBuffer($interleaved), GL_STATIC_DRAW);

        $stride = 8 * 4; // 8 floats × 4 bytes
        // position: layout location 0
        glVertexAttribPointer(0, 3, GL_FLOAT, false, $stride, 0);
        glEnableVertexAttribArray(0);
        // normal: layout location 1
        glVertexAttribPointer(1, 3, GL_FLOAT, false, $stride, 3 * 4);
        glEnableVertexAttribArray(1);
        // uv: layout location 2
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
            glUniformMatrix4fv($loc, false, $matrix->toArray());
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
        // Compile skybox shaders
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

        // Create skybox cube VAO (unit cube, positions only)
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
            glTexImage2D(GL_TEXTURE_CUBE_MAP_POSITIVE_X + $i, 0, GL_RGB, $w, $h, 0, GL_RGB, GL_UNSIGNED_BYTE, $data);
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

        // Draw skybox with depth ≤ (passes where nothing has been drawn, fails behind objects)
        glDepthFunc(GL_LEQUAL);
        glUseProgram($this->skyboxShaderProgram);

        $viewLoc = glGetUniformLocation($this->skyboxShaderProgram, 'u_view');
        if ($viewLoc >= 0 && $this->currentViewMatrix !== null) {
            glUniformMatrix4fv($viewLoc, false, $this->currentViewMatrix->toArray());
        }
        $projLoc = glGetUniformLocation($this->skyboxShaderProgram, 'u_projection');
        if ($projLoc >= 0 && $this->currentProjectionMatrix !== null) {
            glUniformMatrix4fv($projLoc, false, $this->currentProjectionMatrix->toArray());
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
        // Restore mesh shader for any subsequent draws
        glUseProgram($this->shaderProgram);
    }
}
