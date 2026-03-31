<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;

/**
 * Renders the scene into a cubemap texture for environment reflections.
 * Updates 1 face per frame (6 frames for full refresh) to save performance.
 *
 * Usage: call updateFace() once per frame, then bind the cubemap texture.
 */
class CubemapRenderer
{
    private int $cubemapTexture = 0;
    private int $fbo = 0;
    private int $depthRbo = 0;
    private int $currentFace = 0;
    private bool $initialized = false;

    /** @var array<int, Mat4> View matrices for each cubemap face */
    private array $faceViews = [];

    public function __construct(
        private readonly int $resolution = 256,
    ) {}

    public function getTextureId(): int
    {
        return $this->cubemapTexture;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    /**
     * Initialize OpenGL resources (cubemap texture, FBO, depth buffer).
     * Must be called after OpenGL context is ready.
     */
    public function initialize(): void
    {
        if ($this->initialized) return;

        // Create cubemap texture
        $texId = 0;
        glGenTextures(1, $texId);
        $this->cubemapTexture = $texId;
        glBindTexture(GL_TEXTURE_CUBE_MAP, $this->cubemapTexture);

        for ($i = 0; $i < 6; $i++) {
            glTexImage2D(
                GL_TEXTURE_CUBE_MAP_POSITIVE_X + $i,
                0, GL_RGB,
                $this->resolution, $this->resolution,
                0, GL_RGB, GL_UNSIGNED_BYTE, null,
            );
        }

        glTexParameteri(GL_TEXTURE_CUBE_MAP, GL_TEXTURE_MIN_FILTER, GL_LINEAR);
        glTexParameteri(GL_TEXTURE_CUBE_MAP, GL_TEXTURE_MAG_FILTER, GL_LINEAR);
        glTexParameteri(GL_TEXTURE_CUBE_MAP, GL_TEXTURE_WRAP_S, GL_CLAMP_TO_EDGE);
        glTexParameteri(GL_TEXTURE_CUBE_MAP, GL_TEXTURE_WRAP_T, GL_CLAMP_TO_EDGE);
        glTexParameteri(GL_TEXTURE_CUBE_MAP, GL_TEXTURE_WRAP_R, GL_CLAMP_TO_EDGE);

        // Create FBO
        $fboId = 0;
        glGenFramebuffers(1, $fboId);
        $this->fbo = $fboId;

        // Depth renderbuffer
        $rboId = 0;
        glGenRenderbuffers(1, $rboId);
        $this->depthRbo = $rboId;
        glBindRenderbuffer(GL_RENDERBUFFER, $this->depthRbo);
        glRenderbufferStorage(GL_RENDERBUFFER, GL_DEPTH_COMPONENT24, $this->resolution, $this->resolution);

        glBindFramebuffer(GL_FRAMEBUFFER, $this->fbo);
        glFramebufferRenderbuffer(GL_FRAMEBUFFER, GL_DEPTH_ATTACHMENT, GL_RENDERBUFFER, $this->depthRbo);
        glBindFramebuffer(GL_FRAMEBUFFER, 0);

        // Pre-compute view matrices for each face
        $this->faceViews = self::computeFaceViews();

        $this->initialized = true;
    }

    /**
     * Get the projection matrix for cubemap rendering (90° FOV, 1:1 aspect).
     */
    public function getProjection(): Mat4
    {
        return Mat4::perspective(deg2rad(90.0), 1.0, 0.5, 500.0);
    }

    /**
     * Get the view matrix for the current face being rendered.
     */
    public function getCurrentFaceView(Vec3 $position): Mat4
    {
        $face = $this->faceViews[$this->currentFace];
        // Translate the view to the capture position
        $translation = Mat4::translation(-$position->x, -$position->y, -$position->z);
        return $face->multiply($translation);
    }

    /**
     * Get the current face index (0-5) and advance to next.
     */
    public function getCurrentFace(): int
    {
        return $this->currentFace;
    }

    /**
     * Begin rendering to the current cubemap face.
     * Call this before rendering the scene for this face.
     */
    public function beginFace(): void
    {
        if (!$this->initialized) return;

        glBindFramebuffer(GL_FRAMEBUFFER, $this->fbo);
        glFramebufferTexture2D(
            GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0,
            GL_TEXTURE_CUBE_MAP_POSITIVE_X + $this->currentFace,
            $this->cubemapTexture, 0,
        );
        glViewport(0, 0, $this->resolution, $this->resolution);
        glClear(GL_COLOR_BUFFER_BIT | GL_DEPTH_BUFFER_BIT);
    }

    /**
     * End rendering to the current face and advance to the next.
     */
    public function endFace(): void
    {
        if (!$this->initialized) return;

        glBindFramebuffer(GL_FRAMEBUFFER, 0);
        $this->currentFace = ($this->currentFace + 1) % 6;
    }

    /**
     * Bind the cubemap texture for sampling in shaders.
     */
    public function bind(int $textureUnit = 5): void
    {
        if (!$this->initialized) return;

        glActiveTexture(GL_TEXTURE0 + $textureUnit);
        glBindTexture(GL_TEXTURE_CUBE_MAP, $this->cubemapTexture);
    }

    /**
     * Compute the 6 view matrices for cubemap faces.
     * @return array<int, Mat4>
     */
    private static function computeFaceViews(): array
    {
        // Each face looks in a cardinal direction from the origin
        // OpenGL cubemap face order: +X, -X, +Y, -Y, +Z, -Z
        return [
            self::lookAt(Vec3::zero(), new Vec3(1, 0, 0), new Vec3(0, -1, 0)),   // +X
            self::lookAt(Vec3::zero(), new Vec3(-1, 0, 0), new Vec3(0, -1, 0)),  // -X
            self::lookAt(Vec3::zero(), new Vec3(0, 1, 0), new Vec3(0, 0, 1)),    // +Y
            self::lookAt(Vec3::zero(), new Vec3(0, -1, 0), new Vec3(0, 0, -1)),  // -Y
            self::lookAt(Vec3::zero(), new Vec3(0, 0, 1), new Vec3(0, -1, 0)),   // +Z
            self::lookAt(Vec3::zero(), new Vec3(0, 0, -1), new Vec3(0, -1, 0)),  // -Z
        ];
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
