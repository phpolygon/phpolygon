<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\PostProcess;

use PHPolygon\Rendering\ShaderDefinition;
use PHPolygon\Rendering\ShaderRegistry;

/**
 * OpenGL Temporal Anti-Aliasing post-process pass.
 *
 * Owns a private FBO + colour texture that holds the previous TAA
 * output. apply() blends the current frame against this history,
 * writes the composite to the bound framebuffer, AND copies the
 * composite into the history target so it is available for the next
 * frame.
 *
 * Camera-side sub-pixel jitter is owned by
 * {@see \PHPolygon\Rendering\Quality\TaaJitter} and applied by the
 * renderer when a TAA frame's projection matrix is uploaded - this
 * pass only owns the temporal blend.
 *
 * Limitations:
 *   - Neighbourhood clamping suppresses but doesn't eliminate ghosting
 *     for high-contrast moving content. Velocity-buffer-driven motion
 *     vectors are the next iteration upgrade.
 *   - History is not invalidated on scene reload; games that hot-swap
 *     scenes should call resetHistory() to drop the carry-over.
 */
final class OpenGLTaaPass
{
    private const GL_TEXTURE_2D            = 0x0DE1;
    private const GL_TEXTURE0              = 0x84C0;
    private const GL_TEXTURE1              = 0x84C1;
    private const GL_TRIANGLES             = 0x0004;
    private const GL_FRAMEBUFFER           = 0x8D40;
    private const GL_DRAW_FRAMEBUFFER      = 0x8CA9;
    private const GL_READ_FRAMEBUFFER      = 0x8CA8;
    private const GL_COLOR_ATTACHMENT0     = 0x8CE0;
    private const GL_RGBA8                 = 0x8058;
    private const GL_TEXTURE_MIN_FILTER    = 0x2801;
    private const GL_TEXTURE_MAG_FILTER    = 0x2800;
    private const GL_TEXTURE_WRAP_S        = 0x2802;
    private const GL_TEXTURE_WRAP_T        = 0x2803;
    private const GL_LINEAR                = 0x2601;
    private const GL_CLAMP_TO_EDGE         = 0x812F;
    private const GL_COLOR_BUFFER_BIT      = 0x4000;
    private const GL_DRAW_FRAMEBUFFER_BINDING = 0x8CA6;

    private bool $initialised = false;
    private int $program = 0;
    private int $vao = 0;
    private int $uColorTex   = -1;
    private int $uHistoryTex = -1;
    private int $uInvRes     = -1;
    private int $uBlend      = -1;

    private int $historyFbo = 0;
    private int $historyTex = 0;
    private int $historyW = 0;
    private int $historyH = 0;
    private bool $historySeeded = false;

    /**
     * Intermediate texture-backed FBO. apply() composites into here at
     * the source-resolution, then blits the result to (a) the
     * destination framebuffer at backbuffer scale and (b) the history
     * target. This breaks the chicken-and-egg of "compose into
     * backbuffer + read backbuffer for history" that fails when
     * renderScale != 1.0 (the backbuffer rect and source rect would
     * differ).
     */
    private int $compositeFbo = 0;
    private int $compositeTex = 0;

    public function __construct(string $shaderSourceDir)
    {
        if (!ShaderRegistry::has('taa')) {
            ShaderRegistry::register('taa', new ShaderDefinition(
                rtrim($shaderSourceDir, '/') . '/taa.vert.glsl',
                rtrim($shaderSourceDir, '/') . '/taa.frag.glsl',
            ));
        }
    }

    /**
     * Run the TAA composite pass. Caller binds destination framebuffer
     * + viewport. Internally also blits the composite into the history
     * target for the next frame.
     *
     * @param int   $colorTextureId Resolved current-frame colour.
     * @param int   $sourceWidth    Resolve target width.
     * @param int   $sourceHeight   Resolve target height.
     * @param float $blendFactor    History weight, [0..0.99]. Default 0.9.
     */
    public function apply(int $colorTextureId, int $sourceWidth, int $sourceHeight, int $destWidth = 0, int $destHeight = 0, float $blendFactor = 0.9): void
    {
        if (!$this->initialised) {
            $this->initialise();
        }
        if ($this->program === 0) {
            return;
        }

        if ($destWidth <= 0)  $destWidth  = $sourceWidth;
        if ($destHeight <= 0) $destHeight = $sourceHeight;

        $this->ensureHistory($sourceWidth, $sourceHeight);
        $this->ensureComposite($sourceWidth, $sourceHeight);

        // First-frame seed: copy current colour into history so the
        // initial blend has a sensible source instead of black.
        if (!$this->historySeeded) {
            $this->copyToHistory($colorTextureId, $sourceWidth, $sourceHeight);
            $this->historySeeded = true;
        }

        $invW = $sourceWidth  > 0 ? 1.0 / (float)$sourceWidth  : 0.0;
        $invH = $sourceHeight > 0 ? 1.0 / (float)$sourceHeight : 0.0;

        // Capture the caller's bound destination framebuffer (typically 0
        // = backbuffer) so we can return to it after the composite + blit.
        // glGetIntegerv writes through a reference; PHPStan loses the int
        // type so we re-narrow with a strict-int helper rather than scatter
        // (int) casts across every call site.
        $destFbo = self::readBoundDrawFbo();

        // 1) Composite into our private intermediate at source resolution.
        glBindFramebuffer(self::GL_FRAMEBUFFER, $this->compositeFbo);
        glViewport(0, 0, $sourceWidth, $sourceHeight);

        glUseProgram($this->program);

        glActiveTexture(self::GL_TEXTURE0);
        glBindTexture(self::GL_TEXTURE_2D, $colorTextureId);
        if ($this->uColorTex >= 0) {
            glUniform1i($this->uColorTex, 0);
        }

        glActiveTexture(self::GL_TEXTURE1);
        glBindTexture(self::GL_TEXTURE_2D, $this->historyTex);
        if ($this->uHistoryTex >= 0) {
            glUniform1i($this->uHistoryTex, 1);
        }

        if ($this->uInvRes >= 0) {
            glUniform2f($this->uInvRes, $invW, $invH);
        }
        if ($this->uBlend >= 0) {
            glUniform1f($this->uBlend, $blendFactor);
        }

        glBindVertexArray($this->vao);
        glDrawArrays(self::GL_TRIANGLES, 0, 3);
        glBindVertexArray(0);

        // 2) Blit composite -> history (source dims, NEAREST keeps pixels exact).
        glBindFramebuffer(self::GL_READ_FRAMEBUFFER, $this->compositeFbo);
        glBindFramebuffer(self::GL_DRAW_FRAMEBUFFER, $this->historyFbo);
        glBlitFramebuffer(
            0, 0, $sourceWidth, $sourceHeight,
            0, 0, $sourceWidth, $sourceHeight,
            self::GL_COLOR_BUFFER_BIT,
            self::GL_LINEAR,
        );

        // 3) Blit composite -> caller's destination (backbuffer dims, LINEAR
        //    handles up/down-scale when renderScale != 1.0).
        glBindFramebuffer(self::GL_READ_FRAMEBUFFER, $this->compositeFbo);
        glBindFramebuffer(self::GL_DRAW_FRAMEBUFFER, (int)$destFbo);
        glBlitFramebuffer(
            0, 0, $sourceWidth, $sourceHeight,
            0, 0, $destWidth, $destHeight,
            self::GL_COLOR_BUFFER_BIT,
            self::GL_LINEAR,
        );
        glBindFramebuffer(self::GL_FRAMEBUFFER, $destFbo);
    }

    /**
     * Drop the cached history, e.g. after a scene reload. The next apply()
     * call will re-seed history from the current frame.
     */
    public function resetHistory(): void
    {
        $this->historySeeded = false;
    }

    public function release(): void
    {
        if ($this->vao !== 0) {
            glDeleteVertexArrays(1, $this->vao);
            $this->vao = 0;
        }
        if ($this->historyFbo !== 0) {
            glDeleteFramebuffers(1, $this->historyFbo);
            $this->historyFbo = 0;
        }
        if ($this->historyTex !== 0) {
            glDeleteTextures(1, $this->historyTex);
            $this->historyTex = 0;
        }
        if ($this->compositeFbo !== 0) {
            glDeleteFramebuffers(1, $this->compositeFbo);
            $this->compositeFbo = 0;
        }
        if ($this->compositeTex !== 0) {
            glDeleteTextures(1, $this->compositeTex);
            $this->compositeTex = 0;
        }
        if ($this->program !== 0) {
            glDeleteProgram($this->program);
            $this->program = 0;
        }
        $this->historyW = 0;
        $this->historyH = 0;
        $this->historySeeded = false;
        $this->initialised = false;
    }

    private function ensureComposite(int $width, int $height): void
    {
        if ($this->compositeTex !== 0 && $this->historyW === $width && $this->historyH === $height) {
            // ensureHistory() already keeps width/height in sync; reuse the
            // history's invariants for the composite check too.
            return;
        }
        if ($this->compositeFbo !== 0) {
            glDeleteFramebuffers(1, $this->compositeFbo);
            $this->compositeFbo = 0;
        }
        if ($this->compositeTex !== 0) {
            glDeleteTextures(1, $this->compositeTex);
            $this->compositeTex = 0;
        }

        $tex = 0;
        glGenTextures(1, $tex);
        $this->compositeTex = $tex;
        glBindTexture(self::GL_TEXTURE_2D, $tex);
        glTexImage2D(self::GL_TEXTURE_2D, 0, self::GL_RGBA8, $width, $height, 0, GL_RGBA, GL_UNSIGNED_BYTE, null);
        glTexParameteri(self::GL_TEXTURE_2D, self::GL_TEXTURE_MIN_FILTER, self::GL_LINEAR);
        glTexParameteri(self::GL_TEXTURE_2D, self::GL_TEXTURE_MAG_FILTER, self::GL_LINEAR);
        glTexParameteri(self::GL_TEXTURE_2D, self::GL_TEXTURE_WRAP_S, self::GL_CLAMP_TO_EDGE);
        glTexParameteri(self::GL_TEXTURE_2D, self::GL_TEXTURE_WRAP_T, self::GL_CLAMP_TO_EDGE);

        $fbo = 0;
        glGenFramebuffers(1, $fbo);
        $this->compositeFbo = $fbo;
        glBindFramebuffer(self::GL_FRAMEBUFFER, $fbo);
        glFramebufferTexture2D(self::GL_FRAMEBUFFER, self::GL_COLOR_ATTACHMENT0, self::GL_TEXTURE_2D, $tex, 0);
        glBindFramebuffer(self::GL_FRAMEBUFFER, 0);
    }

    private function initialise(): void
    {
        $definition = ShaderRegistry::get('taa');
        if ($definition === null) {
            $this->initialised = true;
            return;
        }
        $this->program = $this->compileProgram(
            (string)file_get_contents($definition->vertexPath),
            (string)file_get_contents($definition->fragmentPath),
        );
        if ($this->program !== 0) {
            $this->uColorTex   = glGetUniformLocation($this->program, 'u_color_texture');
            $this->uHistoryTex = glGetUniformLocation($this->program, 'u_history_texture');
            $this->uInvRes     = glGetUniformLocation($this->program, 'u_inverse_resolution');
            $this->uBlend      = glGetUniformLocation($this->program, 'u_blend_factor');
        }
        $vao = 0;
        glGenVertexArrays(1, $vao);
        $this->vao = $vao;
        $this->initialised = true;
    }

    private function ensureHistory(int $width, int $height): void
    {
        if ($this->historyTex !== 0 && $this->historyW === $width && $this->historyH === $height) {
            return;
        }
        if ($this->historyFbo !== 0) {
            glDeleteFramebuffers(1, $this->historyFbo);
            $this->historyFbo = 0;
        }
        if ($this->historyTex !== 0) {
            glDeleteTextures(1, $this->historyTex);
            $this->historyTex = 0;
        }

        $tex = 0;
        glGenTextures(1, $tex);
        $this->historyTex = $tex;
        glBindTexture(self::GL_TEXTURE_2D, $tex);
        glTexImage2D(self::GL_TEXTURE_2D, 0, self::GL_RGBA8, $width, $height, 0, GL_RGBA, GL_UNSIGNED_BYTE, null);
        glTexParameteri(self::GL_TEXTURE_2D, self::GL_TEXTURE_MIN_FILTER, self::GL_LINEAR);
        glTexParameteri(self::GL_TEXTURE_2D, self::GL_TEXTURE_MAG_FILTER, self::GL_LINEAR);
        glTexParameteri(self::GL_TEXTURE_2D, self::GL_TEXTURE_WRAP_S, self::GL_CLAMP_TO_EDGE);
        glTexParameteri(self::GL_TEXTURE_2D, self::GL_TEXTURE_WRAP_T, self::GL_CLAMP_TO_EDGE);

        $fbo = 0;
        glGenFramebuffers(1, $fbo);
        $this->historyFbo = $fbo;
        glBindFramebuffer(self::GL_FRAMEBUFFER, $fbo);
        glFramebufferTexture2D(self::GL_FRAMEBUFFER, self::GL_COLOR_ATTACHMENT0, self::GL_TEXTURE_2D, $tex, 0);
        glBindFramebuffer(self::GL_FRAMEBUFFER, 0);

        $this->historyW = $width;
        $this->historyH = $height;
        $this->historySeeded = false;
    }

    /**
     * Direct framebuffer-to-framebuffer copy of $colorTextureId into the
     * history target. Used to seed the very first frame.
     */
    private function copyToHistory(int $colorTextureId, int $width, int $height): void
    {
        // We don't have the source FBO id (the texture is exposed by the
        // offscreen target without revealing its FBO), so render with the
        // composite shader and a blend factor of 0.0 = take pure current.
        // This is functionally identical to a blit for the seed frame.
        // glGetIntegerv writes through a reference; PHPStan loses the int
        // type so we re-narrow with a strict-int helper rather than scatter
        // (int) casts across every call site.
        $destFbo = self::readBoundDrawFbo();

        glBindFramebuffer(self::GL_FRAMEBUFFER, $this->historyFbo);
        glViewport(0, 0, $width, $height);

        glUseProgram($this->program);
        glActiveTexture(self::GL_TEXTURE0);
        glBindTexture(self::GL_TEXTURE_2D, $colorTextureId);
        if ($this->uColorTex >= 0) {
            glUniform1i($this->uColorTex, 0);
        }
        // Bind the (zero / uninitialised) history to slot 1 so the sampler
        // is valid; with blend=0 the result ignores it anyway.
        glActiveTexture(self::GL_TEXTURE1);
        glBindTexture(self::GL_TEXTURE_2D, $this->historyTex);
        if ($this->uHistoryTex >= 0) {
            glUniform1i($this->uHistoryTex, 1);
        }
        if ($this->uInvRes >= 0) {
            glUniform2f($this->uInvRes, 1.0 / (float)$width, 1.0 / (float)$height);
        }
        if ($this->uBlend >= 0) {
            glUniform1f($this->uBlend, 0.0);
        }
        glBindVertexArray($this->vao);
        glDrawArrays(self::GL_TRIANGLES, 0, 3);
        glBindVertexArray(0);

        glBindFramebuffer(self::GL_FRAMEBUFFER, $destFbo);
    }

    /**
     * Read the currently-bound draw framebuffer, narrowed to int.
     * glGetIntegerv writes through a reference and PHPStan widens the
     * out-var to mixed; we re-narrow once here so the value flows
     * through the rest of the pass type-safely.
     */
    private static function readBoundDrawFbo(): int
    {
        $out = 0;
        glGetIntegerv(self::GL_DRAW_FRAMEBUFFER_BINDING, $out);
        return is_int($out) ? $out : 0;
    }

    private function compileProgram(string $vertSource, string $fragSource): int
    {
        $vert = glCreateShader(GL_VERTEX_SHADER);
        glShaderSource($vert, $vertSource);
        glCompileShader($vert);
        $vertOk = 0;
        glGetShaderiv($vert, GL_COMPILE_STATUS, $vertOk);
        if (!$vertOk) {
            fwrite(STDERR, "[OpenGLTaaPass] vertex compile failed:\n" . glGetShaderInfoLog($vert, 4096) . "\n");
            return 0;
        }
        $frag = glCreateShader(GL_FRAGMENT_SHADER);
        glShaderSource($frag, $fragSource);
        glCompileShader($frag);
        $fragOk = 0;
        glGetShaderiv($frag, GL_COMPILE_STATUS, $fragOk);
        if (!$fragOk) {
            fwrite(STDERR, "[OpenGLTaaPass] fragment compile failed:\n" . glGetShaderInfoLog($frag, 4096) . "\n");
            return 0;
        }
        $program = glCreateProgram();
        glAttachShader($program, $vert);
        glAttachShader($program, $frag);
        glLinkProgram($program);
        $linkOk = 0;
        glGetProgramiv($program, GL_LINK_STATUS, $linkOk);
        if (!$linkOk) {
            fwrite(STDERR, "[OpenGLTaaPass] link failed:\n" . glGetProgramInfoLog($program, 4096) . "\n");
            return 0;
        }
        glDeleteShader($vert);
        glDeleteShader($frag);
        return $program;
    }
}
