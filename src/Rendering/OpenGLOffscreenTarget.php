<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use GL\Buffer\FloatBuffer;

/**
 * Off-screen FBO pair that backs the Phase 1.5 render-scale and MSAA pipeline
 * on the OpenGL backend.
 *
 * The target is allocated lazily and rebuilt whenever the requested size or
 * sample count changes. When `samples > 1` an additional multisample
 * renderbuffer pair is created and resolved into the single-sample color
 * texture before the result is blitted to the backbuffer or sampled by a
 * post-process pass (FXAA).
 *
 * Layout:
 *   draw  -> msaaFbo (samples > 1)   then resolve -> resolveFbo (single-sample)
 *   draw  -> resolveFbo (samples == 1)
 *   blit/post-process -> default framebuffer (id 0)
 *
 * The resolve color attachment is always a real GL_TEXTURE_2D, so post-process
 * passes (FXAA) can sample it without an extra blit.
 *
 * Lifecycle: callers invoke `resize($w, $h, $samples)` from `applySettings()`
 * or whenever the drawable size changes, `bind()` once at the start of the 3D
 * pass, `resolve()` to flatten MSAA into the single-sample texture, and
 * `presentToBackbuffer($bbW, $bbH)` to scale up to the window resolution.
 */
final class OpenGLOffscreenTarget
{
    private const GL_FRAMEBUFFER          = 0x8D40;
    private const GL_DRAW_FRAMEBUFFER     = 0x8CA9;
    private const GL_READ_FRAMEBUFFER     = 0x8CA8;
    private const GL_RENDERBUFFER         = 0x8D41;
    private const GL_COLOR_ATTACHMENT0    = 0x8CE0;
    private const GL_DEPTH_ATTACHMENT     = 0x8D00;
    private const GL_DEPTH_COMPONENT24    = 0x81A6;
    private const GL_RGBA8                = 0x8058;
    private const GL_FRAMEBUFFER_COMPLETE = 0x8CD5;
    private const GL_TEXTURE_2D           = 0x0DE1;
    private const GL_TEXTURE_MIN_FILTER   = 0x2801;
    private const GL_TEXTURE_MAG_FILTER   = 0x2800;
    private const GL_TEXTURE_WRAP_S       = 0x2802;
    private const GL_TEXTURE_WRAP_T       = 0x2803;
    private const GL_LINEAR               = 0x2601;
    private const GL_CLAMP_TO_EDGE        = 0x812F;
    private const GL_COLOR_BUFFER_BIT     = 0x4000;

    private int $width = 0;
    private int $height = 0;
    private int $samples = 1;

    /** Single-sample resolve target. Color attachment is a sampleable GL_TEXTURE_2D. */
    private int $resolveFbo = 0;
    private int $resolveColorTex = 0;
    private int $resolveDepthRbo = 0;

    /** Multisample target. Only allocated when $samples > 1. */
    private int $msaaFbo = 0;
    private int $msaaColorRbo = 0;
    private int $msaaDepthRbo = 0;

    private bool $allocated = false;

    /**
     * Allocate or rebuild the offscreen target.
     *
     * No-op when the requested size + sample count match the current state.
     */
    public function resize(int $width, int $height, int $samples = 1): void
    {
        $width   = max(1, $width);
        $height  = max(1, $height);
        $samples = max(1, $samples);

        if ($this->allocated && $this->width === $width && $this->height === $height && $this->samples === $samples) {
            return;
        }

        $this->release();

        $this->width   = $width;
        $this->height  = $height;
        $this->samples = $samples;

        // ── Resolve target ─────────────────────────────────────────────────
        $tex = 0;
        glGenTextures(1, $tex);
        $this->resolveColorTex = $tex;
        glBindTexture(self::GL_TEXTURE_2D, $this->resolveColorTex);
        glTexImage2D(self::GL_TEXTURE_2D, 0, self::GL_RGBA8, $width, $height, 0, GL_RGBA, GL_UNSIGNED_BYTE, null);
        glTexParameteri(self::GL_TEXTURE_2D, self::GL_TEXTURE_MIN_FILTER, self::GL_LINEAR);
        glTexParameteri(self::GL_TEXTURE_2D, self::GL_TEXTURE_MAG_FILTER, self::GL_LINEAR);
        glTexParameteri(self::GL_TEXTURE_2D, self::GL_TEXTURE_WRAP_S, self::GL_CLAMP_TO_EDGE);
        glTexParameteri(self::GL_TEXTURE_2D, self::GL_TEXTURE_WRAP_T, self::GL_CLAMP_TO_EDGE);

        $rbo = 0;
        glGenRenderbuffers(1, $rbo);
        $this->resolveDepthRbo = $rbo;
        glBindRenderbuffer(self::GL_RENDERBUFFER, $this->resolveDepthRbo);
        glRenderbufferStorage(self::GL_RENDERBUFFER, self::GL_DEPTH_COMPONENT24, $width, $height);

        $fbo = 0;
        glGenFramebuffers(1, $fbo);
        $this->resolveFbo = $fbo;
        glBindFramebuffer(self::GL_FRAMEBUFFER, $this->resolveFbo);
        glFramebufferTexture2D(self::GL_FRAMEBUFFER, self::GL_COLOR_ATTACHMENT0, self::GL_TEXTURE_2D, $this->resolveColorTex, 0);
        glFramebufferRenderbuffer(self::GL_FRAMEBUFFER, self::GL_DEPTH_ATTACHMENT, self::GL_RENDERBUFFER, $this->resolveDepthRbo);

        $status = glCheckFramebufferStatus(self::GL_FRAMEBUFFER);
        if ($status !== self::GL_FRAMEBUFFER_COMPLETE) {
            fwrite(STDERR, sprintf("[OpenGLOffscreenTarget] resolve FBO incomplete (status=0x%04X)\n", $status));
        }

        // ── Multisample target ─────────────────────────────────────────────
        if ($samples > 1) {
            $rbo = 0;
            glGenRenderbuffers(1, $rbo);
            $this->msaaColorRbo = $rbo;
            glBindRenderbuffer(self::GL_RENDERBUFFER, $this->msaaColorRbo);
            glRenderbufferStorageMultisample(self::GL_RENDERBUFFER, $samples, self::GL_RGBA8, $width, $height);

            $rbo = 0;
            glGenRenderbuffers(1, $rbo);
            $this->msaaDepthRbo = $rbo;
            glBindRenderbuffer(self::GL_RENDERBUFFER, $this->msaaDepthRbo);
            glRenderbufferStorageMultisample(self::GL_RENDERBUFFER, $samples, self::GL_DEPTH_COMPONENT24, $width, $height);

            $fbo = 0;
            glGenFramebuffers(1, $fbo);
            $this->msaaFbo = $fbo;
            glBindFramebuffer(self::GL_FRAMEBUFFER, $this->msaaFbo);
            glFramebufferRenderbuffer(self::GL_FRAMEBUFFER, self::GL_COLOR_ATTACHMENT0, self::GL_RENDERBUFFER, $this->msaaColorRbo);
            glFramebufferRenderbuffer(self::GL_FRAMEBUFFER, self::GL_DEPTH_ATTACHMENT, self::GL_RENDERBUFFER, $this->msaaDepthRbo);

            $status = glCheckFramebufferStatus(self::GL_FRAMEBUFFER);
            if ($status !== self::GL_FRAMEBUFFER_COMPLETE) {
                fwrite(STDERR, sprintf("[OpenGLOffscreenTarget] MSAA FBO incomplete (status=0x%04X)\n", $status));
            }
        }

        glBindFramebuffer(self::GL_FRAMEBUFFER, 0);
        $this->allocated = true;
    }

    /**
     * Bind the appropriate draw target (MSAA when samples > 1, else resolve).
     * Sets the viewport to the offscreen size.
     */
    public function bindForDraw(): void
    {
        if (!$this->allocated) {
            return;
        }

        $fbo = $this->samples > 1 ? $this->msaaFbo : $this->resolveFbo;
        glBindFramebuffer(self::GL_FRAMEBUFFER, $fbo);
        glViewport(0, 0, $this->width, $this->height);
    }

    /**
     * Flatten MSAA samples into the single-sample resolve texture.
     * No-op when samples == 1 (already drawing into the resolve target).
     */
    public function resolve(): void
    {
        if (!$this->allocated || $this->samples <= 1) {
            return;
        }

        glBindFramebuffer(self::GL_READ_FRAMEBUFFER, $this->msaaFbo);
        glBindFramebuffer(self::GL_DRAW_FRAMEBUFFER, $this->resolveFbo);
        glBlitFramebuffer(
            0, 0, $this->width, $this->height,
            0, 0, $this->width, $this->height,
            self::GL_COLOR_BUFFER_BIT,
            self::GL_LINEAR,
        );
        glBindFramebuffer(self::GL_FRAMEBUFFER, 0);
    }

    /**
     * Blit the resolved single-sample texture onto the default framebuffer
     * at the requested backbuffer size with linear filtering.
     *
     * Caller is responsible for invoking resolve() first when samples > 1.
     */
    public function presentToBackbuffer(int $backbufferWidth, int $backbufferHeight): void
    {
        if (!$this->allocated) {
            return;
        }

        glBindFramebuffer(self::GL_READ_FRAMEBUFFER, $this->resolveFbo);
        glBindFramebuffer(self::GL_DRAW_FRAMEBUFFER, 0);
        glBlitFramebuffer(
            0, 0, $this->width, $this->height,
            0, 0, $backbufferWidth, $backbufferHeight,
            self::GL_COLOR_BUFFER_BIT,
            self::GL_LINEAR,
        );
        glBindFramebuffer(self::GL_FRAMEBUFFER, 0);
        glViewport(0, 0, $backbufferWidth, $backbufferHeight);
    }

    /** Texture handle holding the resolved color image (for post-process sampling). */
    public function colorTextureId(): int
    {
        return $this->resolveColorTex;
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function samples(): int
    {
        return $this->samples;
    }

    public function isAllocated(): bool
    {
        return $this->allocated;
    }

    /**
     * Release every GL resource held by the target. Safe to call before
     * `resize()` and during destruction; the GL handles are cleared so a
     * second call is a no-op.
     */
    public function release(): void
    {
        if ($this->resolveFbo !== 0) {
            glDeleteFramebuffers(1, $this->resolveFbo);
            $this->resolveFbo = 0;
        }
        if ($this->resolveColorTex !== 0) {
            glDeleteTextures(1, $this->resolveColorTex);
            $this->resolveColorTex = 0;
        }
        if ($this->resolveDepthRbo !== 0) {
            glDeleteRenderbuffers(1, $this->resolveDepthRbo);
            $this->resolveDepthRbo = 0;
        }
        if ($this->msaaFbo !== 0) {
            glDeleteFramebuffers(1, $this->msaaFbo);
            $this->msaaFbo = 0;
        }
        if ($this->msaaColorRbo !== 0) {
            glDeleteRenderbuffers(1, $this->msaaColorRbo);
            $this->msaaColorRbo = 0;
        }
        if ($this->msaaDepthRbo !== 0) {
            glDeleteRenderbuffers(1, $this->msaaDepthRbo);
            $this->msaaDepthRbo = 0;
        }

        $this->allocated = false;
        // Width/height/samples retained so resize() can short-circuit.
    }
}
