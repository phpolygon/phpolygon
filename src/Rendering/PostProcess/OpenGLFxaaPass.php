<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\PostProcess;

use PHPolygon\Rendering\ShaderDefinition;
use PHPolygon\Rendering\ShaderRegistry;

/**
 * OpenGL FXAA post-process pass.
 *
 * Fullscreen-triangle shader pass that samples a single-sample color texture
 * and writes anti-aliased pixels to whichever framebuffer is currently bound
 * by the caller. The vertex shader uses the gl_VertexID trick to generate
 * a screen-covering triangle, so no VAO/VBO is required - we only need a
 * dummy VAO bound to make core-profile drivers happy.
 *
 * Lifecycle: lazily compiled on first use; reused for every frame. Resources
 * are released via release(), which the OpenGL renderer calls in its
 * destruction path.
 */
final class OpenGLFxaaPass
{
    private const GL_TEXTURE_2D     = 0x0DE1;
    private const GL_TEXTURE0       = 0x84C0;
    private const GL_TRIANGLES      = 0x0004;

    private bool $initialised = false;
    private int $program = 0;
    private int $vao = 0;
    private int $uColorTexLocation = -1;
    private int $uInvResolutionLocation = -1;

    public function __construct(string $shaderSourceDir)
    {
        if (!ShaderRegistry::has('fxaa')) {
            ShaderRegistry::register('fxaa', new ShaderDefinition(
                rtrim($shaderSourceDir, '/') . '/fxaa.vert.glsl',
                rtrim($shaderSourceDir, '/') . '/fxaa.frag.glsl',
            ));
        }
    }

    /**
     * Compile the shader (once) and run a fullscreen FXAA pass that samples
     * `$inputTextureId` and writes to the currently bound framebuffer.
     *
     * Caller is responsible for binding the destination framebuffer + viewport
     * before invoking apply().
     */
    public function apply(int $inputTextureId, int $sourceWidth, int $sourceHeight): void
    {
        if (!$this->initialised) {
            $this->initialise();
        }

        if ($this->program === 0) {
            return;
        }

        $invW = $sourceWidth  > 0 ? 1.0 / (float)$sourceWidth  : 0.0;
        $invH = $sourceHeight > 0 ? 1.0 / (float)$sourceHeight : 0.0;

        glUseProgram($this->program);
        glActiveTexture(self::GL_TEXTURE0);
        glBindTexture(self::GL_TEXTURE_2D, $inputTextureId);
        if ($this->uColorTexLocation >= 0) {
            glUniform1i($this->uColorTexLocation, 0);
        }
        if ($this->uInvResolutionLocation >= 0) {
            glUniform2f($this->uInvResolutionLocation, $invW, $invH);
        }

        glBindVertexArray($this->vao);
        glDrawArrays(self::GL_TRIANGLES, 0, 3);
        glBindVertexArray(0);
    }

    public function release(): void
    {
        if ($this->vao !== 0) {
            glDeleteVertexArrays(1, $this->vao);
            $this->vao = 0;
        }
        // Program is owned by the engine-wide ShaderRegistry/program cache in
        // the OpenGL renderer; we don't delete it here to avoid double-free.
        $this->initialised = false;
    }

    private function initialise(): void
    {
        $definition = ShaderRegistry::get('fxaa');
        if ($definition === null) {
            // Should be impossible - registered in the constructor.
            $this->initialised = true;
            return;
        }

        $this->program = $this->compileProgram(
            (string)file_get_contents($definition->vertexPath),
            (string)file_get_contents($definition->fragmentPath),
        );

        if ($this->program !== 0) {
            $this->uColorTexLocation       = glGetUniformLocation($this->program, 'u_color_texture');
            $this->uInvResolutionLocation  = glGetUniformLocation($this->program, 'u_inverse_resolution');
        }

        // Core-profile drivers require a bound VAO even for shader-only draws.
        $vao = 0;
        glGenVertexArrays(1, $vao);
        $this->vao = $vao;

        $this->initialised = true;
    }

    private function compileProgram(string $vertSource, string $fragSource): int
    {
        $vert = glCreateShader(GL_VERTEX_SHADER);
        glShaderSource($vert, $vertSource);
        glCompileShader($vert);
        $vertOk = 0;
        glGetShaderiv($vert, GL_COMPILE_STATUS, $vertOk);
        if (!$vertOk) {
            $log = glGetShaderInfoLog($vert, 4096);
            fwrite(STDERR, "[OpenGLFxaaPass] vertex compile failed:\n{$log}\n");
            return 0;
        }

        $frag = glCreateShader(GL_FRAGMENT_SHADER);
        glShaderSource($frag, $fragSource);
        glCompileShader($frag);
        $fragOk = 0;
        glGetShaderiv($frag, GL_COMPILE_STATUS, $fragOk);
        if (!$fragOk) {
            $log = glGetShaderInfoLog($frag, 4096);
            fwrite(STDERR, "[OpenGLFxaaPass] fragment compile failed:\n{$log}\n");
            return 0;
        }

        $program = glCreateProgram();
        glAttachShader($program, $vert);
        glAttachShader($program, $frag);
        glLinkProgram($program);
        $linkOk = 0;
        glGetProgramiv($program, GL_LINK_STATUS, $linkOk);
        if (!$linkOk) {
            $log = glGetProgramInfoLog($program, 4096);
            fwrite(STDERR, "[OpenGLFxaaPass] link failed:\n{$log}\n");
            return 0;
        }

        glDeleteShader($vert);
        glDeleteShader($frag);
        return $program;
    }
}
