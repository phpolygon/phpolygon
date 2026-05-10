<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\PostProcess;

use GL\Buffer\FloatBuffer;
use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\ShaderDefinition;
use PHPolygon\Rendering\ShaderRegistry;

/**
 * OpenGL screen-space reflections post-process pass.
 *
 * Samples the resolved scene colour + depth from the main offscreen
 * target, ray-marches reflections in screen space, and writes the
 * composite (scene + reflection) into the currently-bound framebuffer.
 *
 * Reconstructs world normals per-fragment from screen-space derivatives
 * of the world position - cheap, no extra G-buffer required, but
 * softer reflection edges than a true normal-attached path. The
 * upgrade path (G-buffer normal sampler) is single-line: bind a normal
 * texture in apply(), add the sampler uniform, swap the dFdx/dFdy
 * reconstruction in ssr.frag.glsl. The current implementation is the
 * production tier the engine ships with.
 *
 * Lifecycle mirrors {@see OpenGLFxaaPass}: lazily compiled on first
 * use, reused every frame, released by the renderer's destructor.
 */
final class OpenGLSsrPass
{
    private const GL_TEXTURE_2D = 0x0DE1;
    private const GL_TEXTURE0   = 0x84C0;
    private const GL_TEXTURE1   = 0x84C1;
    private const GL_TRIANGLES  = 0x0004;

    private bool $initialised = false;
    private int $program = 0;
    private int $vao = 0;
    private int $uColorTex   = -1;
    private int $uDepthTex   = -1;
    private int $uInvVp      = -1;
    private int $uVp         = -1;
    private int $uCameraPos  = -1;
    private int $uInvRes     = -1;
    private int $uIntensity  = -1;

    public function __construct(string $shaderSourceDir)
    {
        if (!ShaderRegistry::has('ssr')) {
            ShaderRegistry::register('ssr', new ShaderDefinition(
                rtrim($shaderSourceDir, '/') . '/ssr.vert.glsl',
                rtrim($shaderSourceDir, '/') . '/ssr.frag.glsl',
            ));
        }
    }

    /**
     * Run the SSR composite. Caller is responsible for binding the
     * destination framebuffer + viewport before calling.
     *
     * @param int   $colorTextureId Resolved scene colour (sampleable).
     * @param int   $depthTextureId Resolved scene depth (sampleable, NEAREST filter).
     * @param Mat4  $viewProjection Current frame's V*P matrix.
     * @param array $cameraPos      [x, y, z] world-space camera position.
     * @param int     $sourceWidth    Resolve target width.
     * @param int     $sourceHeight   Resolve target height.
     * @param float   $intensity      ScreenSpaceReflections::intensity().
     * @param array{0: float, 1: float, 2: float} $cameraPos World-space
     *                       camera position.
     */
    public function apply(
        int $colorTextureId,
        int $depthTextureId,
        Mat4 $viewProjection,
        array $cameraPos,
        int $sourceWidth,
        int $sourceHeight,
        float $intensity,
    ): void {
        if (!$this->initialised) {
            $this->initialise();
        }
        if ($this->program === 0) {
            return;
        }

        $invW = $sourceWidth  > 0 ? 1.0 / (float)$sourceWidth  : 0.0;
        $invH = $sourceHeight > 0 ? 1.0 / (float)$sourceHeight : 0.0;
        $invVp = $viewProjection->inverse();

        glUseProgram($this->program);

        glActiveTexture(self::GL_TEXTURE0);
        glBindTexture(self::GL_TEXTURE_2D, $colorTextureId);
        if ($this->uColorTex >= 0) {
            glUniform1i($this->uColorTex, 0);
        }

        glActiveTexture(self::GL_TEXTURE1);
        glBindTexture(self::GL_TEXTURE_2D, $depthTextureId);
        if ($this->uDepthTex >= 0) {
            glUniform1i($this->uDepthTex, 1);
        }

        if ($this->uInvVp >= 0) {
            glUniformMatrix4fv($this->uInvVp, false, new FloatBuffer($invVp->toArray()));
        }
        if ($this->uVp >= 0) {
            glUniformMatrix4fv($this->uVp, false, new FloatBuffer($viewProjection->toArray()));
        }
        if ($this->uCameraPos >= 0) {
            glUniform3f($this->uCameraPos, $cameraPos[0], $cameraPos[1], $cameraPos[2]);
        }
        if ($this->uInvRes >= 0) {
            glUniform2f($this->uInvRes, $invW, $invH);
        }
        if ($this->uIntensity >= 0) {
            glUniform1f($this->uIntensity, $intensity);
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
        if ($this->program !== 0) {
            // Program is built directly via compileProgram(); ShaderRegistry
            // only owns the source-file definition, not the GL program.
            glDeleteProgram($this->program);
            $this->program = 0;
        }
        $this->initialised = false;
    }

    private function initialise(): void
    {
        $definition = ShaderRegistry::get('ssr');
        if ($definition === null) {
            $this->initialised = true;
            return;
        }

        $this->program = $this->compileProgram(
            (string)file_get_contents($definition->vertexPath),
            (string)file_get_contents($definition->fragmentPath),
        );

        if ($this->program !== 0) {
            $this->uColorTex  = glGetUniformLocation($this->program, 'u_color_texture');
            $this->uDepthTex  = glGetUniformLocation($this->program, 'u_depth_texture');
            $this->uInvVp     = glGetUniformLocation($this->program, 'u_inverse_view_projection');
            $this->uVp        = glGetUniformLocation($this->program, 'u_view_projection');
            $this->uCameraPos = glGetUniformLocation($this->program, 'u_camera_pos');
            $this->uInvRes    = glGetUniformLocation($this->program, 'u_inverse_resolution');
            $this->uIntensity = glGetUniformLocation($this->program, 'u_ssr_intensity');
        }

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
            fwrite(STDERR, "[OpenGLSsrPass] vertex compile failed:\n" . glGetShaderInfoLog($vert, 4096) . "\n");
            return 0;
        }
        $frag = glCreateShader(GL_FRAGMENT_SHADER);
        glShaderSource($frag, $fragSource);
        glCompileShader($frag);
        $fragOk = 0;
        glGetShaderiv($frag, GL_COMPILE_STATUS, $fragOk);
        if (!$fragOk) {
            fwrite(STDERR, "[OpenGLSsrPass] fragment compile failed:\n" . glGetShaderInfoLog($frag, 4096) . "\n");
            return 0;
        }
        $program = glCreateProgram();
        glAttachShader($program, $vert);
        glAttachShader($program, $frag);
        glLinkProgram($program);
        $linkOk = 0;
        glGetProgramiv($program, GL_LINK_STATUS, $linkOk);
        if (!$linkOk) {
            fwrite(STDERR, "[OpenGLSsrPass] link failed:\n" . glGetProgramInfoLog($program, 4096) . "\n");
            return 0;
        }
        glDeleteShader($vert);
        glDeleteShader($frag);
        return $program;
    }

}
