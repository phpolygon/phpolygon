<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Renders cloud opacity from the sun's perspective into a 2D texture.
 * Used by the main pass to create soft cloud shadows on the terrain.
 *
 * Unlike the depth-based shadow map, this stores opacity (alpha) values.
 * Clouds are semi-transparent, so the shadow varies in intensity.
 */
class CloudShadowRenderer
{
    private int $fbo = 0;
    private int $colorTexture = 0;
    private int $depthRbo = 0;
    private bool $initialized = false;

    public function __construct(
        private readonly int $resolution = 1024,
    ) {}

    public function getTextureId(): int
    {
        return $this->colorTexture;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function initialize(): void
    {
        if ($this->initialized) return;

        // Color texture — stores cloud opacity in R channel
        $texId = 0;
        glGenTextures(1, $texId);
        $this->colorTexture = $texId;
        glBindTexture(GL_TEXTURE_2D, $this->colorTexture);
        glTexImage2D(GL_TEXTURE_2D, 0, GL_R8, $this->resolution, $this->resolution, 0, GL_RED, GL_UNSIGNED_BYTE, null);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_LINEAR);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_LINEAR);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_S, GL_CLAMP_TO_BORDER);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_T, GL_CLAMP_TO_BORDER);
        // Border = 0 opacity (no cloud shadow outside map)
        glTexParameterfv(GL_TEXTURE_2D, GL_TEXTURE_BORDER_COLOR, new \GL\Buffer\FloatBuffer([0.0, 0.0, 0.0, 0.0]));

        // Depth renderbuffer (needed for correct occlusion between overlapping clouds)
        $rboId = 0;
        glGenRenderbuffers(1, $rboId);
        $this->depthRbo = $rboId;
        glBindRenderbuffer(GL_RENDERBUFFER, $this->depthRbo);
        glRenderbufferStorage(GL_RENDERBUFFER, GL_DEPTH_COMPONENT24, $this->resolution, $this->resolution);

        // FBO
        $fboId = 0;
        glGenFramebuffers(1, $fboId);
        $this->fbo = $fboId;
        glBindFramebuffer(GL_FRAMEBUFFER, $this->fbo);
        glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $this->colorTexture, 0);
        glFramebufferRenderbuffer(GL_FRAMEBUFFER, GL_DEPTH_ATTACHMENT, GL_RENDERBUFFER, $this->depthRbo);
        glBindFramebuffer(GL_FRAMEBUFFER, 0);

        $this->initialized = true;
    }

    /**
     * Begin cloud shadow pass.
     * Clear to black (0 opacity = no clouds = no shadow).
     */
    public function beginPass(): void
    {
        if (!$this->initialized) return;

        glBindFramebuffer(GL_FRAMEBUFFER, $this->fbo);
        glViewport(0, 0, $this->resolution, $this->resolution);
        glClearColor(0.0, 0.0, 0.0, 0.0);
        glClear(GL_COLOR_BUFFER_BIT | GL_DEPTH_BUFFER_BIT);

        // Additive blending — overlapping clouds accumulate opacity
        glEnable(GL_BLEND);
        glBlendFunc(GL_ONE, GL_ONE);
    }

    /**
     * End cloud shadow pass.
     */
    public function endPass(): void
    {
        glDisable(GL_BLEND);
        glBindFramebuffer(GL_FRAMEBUFFER, 0);
    }

    /**
     * Bind cloud shadow texture for sampling.
     */
    public function bind(int $textureUnit = 7): void
    {
        if (!$this->initialized) return;

        glActiveTexture(GL_TEXTURE0 + $textureUnit);
        glBindTexture(GL_TEXTURE_2D, $this->colorTexture);
    }
}
