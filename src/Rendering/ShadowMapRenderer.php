<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;

/**
 * Renders the scene from the sun's perspective into a depth texture (shadow map).
 * Used by OpenGLRenderer3D to test whether fragments are in shadow.
 */
class ShadowMapRenderer
{
    private int $fbo = 0;
    private int $depthTexture = 0;
    private bool $initialized = false;
    private Mat4 $lightSpaceMatrix;

    public function __construct(
        private readonly int $resolution = 2048,
        private readonly float $orthoSize = 60.0,
        private readonly float $nearPlane = 0.5,
        private readonly float $farPlane = 200.0,
    ) {
        $this->lightSpaceMatrix = Mat4::identity();
    }

    public function getDepthTextureId(): int
    {
        return $this->depthTexture;
    }

    public function getLightSpaceMatrix(): Mat4
    {
        return $this->lightSpaceMatrix;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function initialize(): void
    {
        if ($this->initialized) return;

        // Create depth texture
        $texId = 0;
        glGenTextures(1, $texId);
        $this->depthTexture = $texId;
        glBindTexture(GL_TEXTURE_2D, $this->depthTexture);
        glTexImage2D(GL_TEXTURE_2D, 0, GL_DEPTH_COMPONENT, $this->resolution, $this->resolution, 0, GL_DEPTH_COMPONENT, GL_FLOAT, null);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_LINEAR);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_LINEAR);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_S, GL_CLAMP_TO_BORDER);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_T, GL_CLAMP_TO_BORDER);
        // Border color = 1.0 (no shadow outside map)
        glTexParameterfv(GL_TEXTURE_2D, GL_TEXTURE_BORDER_COLOR, new \GL\Buffer\FloatBuffer([1.0, 1.0, 1.0, 1.0]));

        // Compare mode for hardware PCF
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_COMPARE_MODE, GL_COMPARE_REF_TO_TEXTURE);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_COMPARE_FUNC, GL_LEQUAL);

        // Create FBO
        $fboId = 0;
        glGenFramebuffers(1, $fboId);
        $this->fbo = $fboId;
        glBindFramebuffer(GL_FRAMEBUFFER, $this->fbo);
        glFramebufferTexture2D(GL_FRAMEBUFFER, GL_DEPTH_ATTACHMENT, GL_TEXTURE_2D, $this->depthTexture, 0);
        glDrawBuffer(GL_NONE);
        glReadBuffer(GL_NONE);
        glBindFramebuffer(GL_FRAMEBUFFER, 0);

        $this->initialized = true;
    }

    /**
     * Compute the light-space matrix from the sun direction.
     * Uses orthographic projection centered on the world origin.
     */
    public function updateLightMatrix(Vec3 $sunDirection): void
    {
        // Normalize sun direction
        $len = sqrt($sunDirection->x ** 2 + $sunDirection->y ** 2 + $sunDirection->z ** 2);
        if ($len < 0.001) return;
        $dx = $sunDirection->x / $len;
        $dy = $sunDirection->y / $len;
        $dz = $sunDirection->z / $len;

        // Light position = opposite of direction, far from scene
        $lightPos = new Vec3(-$dx * 80.0, -$dy * 80.0, -$dz * 80.0);
        $target = Vec3::zero();

        // Up vector: use (0,0,1) when sun is nearly vertical to avoid degenerate cross product
        $up = abs($dy) > 0.9
            ? new Vec3(0.0, 0.0, 1.0)
            : new Vec3(0.0, 1.0, 0.0);

        $lightView = self::lookAt($lightPos, $target, $up);

        // Orthographic projection covering the scene
        $s = $this->orthoSize;
        $lightProj = Mat4::orthographic(-$s, $s, -$s, $s, $this->nearPlane, $this->farPlane);

        $this->lightSpaceMatrix = $lightProj->multiply($lightView);
    }

    /**
     * Begin shadow pass: bind FBO, set viewport, clear depth.
     */
    public function beginShadowPass(): void
    {
        if (!$this->initialized) return;

        glBindFramebuffer(GL_FRAMEBUFFER, $this->fbo);
        glViewport(0, 0, $this->resolution, $this->resolution);
        glClear(GL_DEPTH_BUFFER_BIT);
    }

    /**
     * End shadow pass: unbind FBO.
     */
    public function endShadowPass(): void
    {
        glBindFramebuffer(GL_FRAMEBUFFER, 0);
    }

    /**
     * Bind shadow map texture for sampling in the main pass.
     */
    public function bind(int $textureUnit = 6): void
    {
        if (!$this->initialized) return;

        glActiveTexture(GL_TEXTURE0 + $textureUnit);
        glBindTexture(GL_TEXTURE_2D, $this->depthTexture);
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
}
