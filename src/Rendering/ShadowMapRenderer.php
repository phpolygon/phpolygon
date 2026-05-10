<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;

/**
 * Renders the scene from the sun's perspective into one or more depth
 * textures (shadow maps).
 *
 * Cascade-shadow-map (CSM) layout:
 *   - The renderer owns N FBOs / depth textures, each at the shared
 *     `$resolution`. N = count(`$cascadeOrthoSizes`).
 *   - Cascade i uses ortho box `[-orthoSizes[i], +orthoSizes[i]]` so the
 *     cascade-zero (smallest box) gets the highest-resolution shadows
 *     near the camera, with progressively larger boxes covering the
 *     far view.
 *   - Each cascade gets its own `Mat4` light-space matrix and its own
 *     shadow-map texture. The fragment shader picks the cascade based
 *     on view-space distance.
 *   - For backwards compatibility the legacy single-map API (no
 *     `$cascade` argument) keeps working - it operates on cascade 0.
 *
 * Shadow centre + texel snap: see `updateLightMatrix()`.
 */
class ShadowMapRenderer
{
    /** @var int[] FBO ids, one per cascade. */
    private array $fbos = [];
    /** @var int[] depth-texture ids, one per cascade. */
    private array $depthTextures = [];
    /** @var Mat4[] light-space matrices, one per cascade. */
    private array $lightSpaceMatrices = [];
    /** @var float[] cascade ortho-box half-extents. */
    private array $cascadeOrthoSizes;

    private bool $initialized = false;

    /**
     * @param int $resolution shadow-map texture size, applied to every cascade.
     * @param float|float[] $orthoSize either a single float (legacy single-map)
     *        or a list of half-extents to enable CSM (e.g. [15.0, 50.0, 150.0]
     *        for three cascades).
     * @param float $nearPlane near plane for the light's ortho projection.
     * @param float $farPlane  far plane for the light's ortho projection.
     */
    public function __construct(
        private readonly int $resolution = 2048,
        float|array $orthoSize = 60.0,
        private readonly float $nearPlane = 0.5,
        private readonly float $farPlane = 200.0,
    ) {
        $this->cascadeOrthoSizes = is_array($orthoSize) ? array_values($orthoSize) : [$orthoSize];
        if (count($this->cascadeOrthoSizes) === 0) {
            $this->cascadeOrthoSizes = [60.0];
        }
        for ($i = 0; $i < count($this->cascadeOrthoSizes); $i++) {
            $this->lightSpaceMatrices[$i] = Mat4::identity();
        }
    }

    public function cascadeCount(): int
    {
        return count($this->cascadeOrthoSizes);
    }

    /**
     * @return float[] cascade ortho-box half-extents (read-only).
     */
    public function cascadeOrthoSizes(): array
    {
        return $this->cascadeOrthoSizes;
    }

    /**
     * Cascade 0 (legacy API). Kept for callers that don't yet honour CSM.
     */
    public function getDepthTextureId(): int
    {
        return $this->depthTextures[0] ?? 0;
    }

    public function getDepthTextureIdAt(int $cascade): int
    {
        return $this->depthTextures[$cascade] ?? 0;
    }

    public function getLightSpaceMatrix(): Mat4
    {
        return $this->lightSpaceMatrices[0];
    }

    public function getLightSpaceMatrixAt(int $cascade): Mat4
    {
        return $this->lightSpaceMatrices[$cascade] ?? Mat4::identity();
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function initialize(): void
    {
        if ($this->initialized) return;

        for ($c = 0; $c < count($this->cascadeOrthoSizes); $c++) {
            // Depth texture
            $texId = 0;
            glGenTextures(1, $texId);
            $this->depthTextures[$c] = $texId;
            glBindTexture(GL_TEXTURE_2D, $texId);
            glTexImage2D(GL_TEXTURE_2D, 0, GL_DEPTH_COMPONENT, $this->resolution, $this->resolution, 0, GL_DEPTH_COMPONENT, GL_FLOAT, null);
            glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_LINEAR);
            glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_LINEAR);
            glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_S, GL_CLAMP_TO_BORDER);
            glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_T, GL_CLAMP_TO_BORDER);
            glTexParameterfv(GL_TEXTURE_2D, GL_TEXTURE_BORDER_COLOR, new \GL\Buffer\FloatBuffer([1.0, 1.0, 1.0, 1.0]));

            // Hardware PCF compare
            glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_COMPARE_MODE, GL_COMPARE_REF_TO_TEXTURE);
            glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_COMPARE_FUNC, GL_LEQUAL);

            // FBO
            $fboId = 0;
            glGenFramebuffers(1, $fboId);
            $this->fbos[$c] = $fboId;
            glBindFramebuffer(GL_FRAMEBUFFER, $fboId);
            glFramebufferTexture2D(GL_FRAMEBUFFER, GL_DEPTH_ATTACHMENT, GL_TEXTURE_2D, $texId, 0);
            glDrawBuffer(GL_NONE);
            glReadBuffer(GL_NONE);
            glBindFramebuffer(GL_FRAMEBUFFER, 0);
        }

        $this->initialized = true;
    }

    /**
     * Build the light-space matrix for every cascade.
     *
     * The shadow frustum centre follows `$cameraTarget` (when supplied)
     * and is texel-snapped to the per-cascade shadow-map grid - both
     * prerequisites for stable open-world shadows.
     */
    public function updateLightMatrix(Vec3 $sunDirection, ?Vec3 $cameraTarget = null): void
    {
        $len = sqrt($sunDirection->x ** 2 + $sunDirection->y ** 2 + $sunDirection->z ** 2);
        if ($len < 0.001) return;
        $dx = $sunDirection->x / $len;
        $dy = $sunDirection->y / $len;
        $dz = $sunDirection->z / $len;

        $center = $cameraTarget ?? Vec3::zero();

        // Avoid degenerate cross-product when the sun is nearly vertical;
        // 0.999 matches the threshold the particle system uses for the
        // same "view-aligned" pivot.
        $up = abs($dy) > 0.999
            ? new Vec3(0.0, 0.0, 1.0)
            : new Vec3(0.0, 1.0, 0.0);

        foreach ($this->cascadeOrthoSizes as $cIdx => $s) {
            $cascadeCenter = $center;
            if ($cameraTarget !== null && $this->resolution > 0) {
                $worldUnitsPerTexel = (2.0 * $s) / $this->resolution;
                $cascadeCenter = new Vec3(
                    round($center->x / $worldUnitsPerTexel) * $worldUnitsPerTexel,
                    round($center->y / $worldUnitsPerTexel) * $worldUnitsPerTexel,
                    round($center->z / $worldUnitsPerTexel) * $worldUnitsPerTexel,
                );
            }

            $lightPos = new Vec3(
                $cascadeCenter->x - $dx * 80.0,
                $cascadeCenter->y - $dy * 80.0,
                $cascadeCenter->z - $dz * 80.0,
            );

            $lightView = self::lookAt($lightPos, $cascadeCenter, $up);
            $lightProj = Mat4::orthographic(-$s, $s, -$s, $s, $this->nearPlane, $this->farPlane);
            $this->lightSpaceMatrices[$cIdx] = $lightProj->multiply($lightView);
        }
    }

    /**
     * Release every FBO + depth texture owned by the cascades. Safe to
     * call before destruction; subsequent draws will silently no-op
     * because $initialized stays false until initialize() is called
     * again.
     */
    public function release(): void
    {
        foreach ($this->fbos as $fbo) {
            if ($fbo !== 0) {
                glDeleteFramebuffers(1, $fbo);
            }
        }
        foreach ($this->depthTextures as $tex) {
            if ($tex !== 0) {
                glDeleteTextures(1, $tex);
            }
        }
        $this->fbos = [];
        $this->depthTextures = [];
        $this->initialized = false;
    }

    public function beginShadowPass(int $cascade = 0): void
    {
        if (!$this->initialized) return;
        if (!isset($this->fbos[$cascade])) return;

        glBindFramebuffer(GL_FRAMEBUFFER, $this->fbos[$cascade]);
        glViewport(0, 0, $this->resolution, $this->resolution);
        glClear(GL_DEPTH_BUFFER_BIT);
    }

    public function endShadowPass(): void
    {
        glBindFramebuffer(GL_FRAMEBUFFER, 0);
    }

    /**
     * Bind cascade $cascade's depth texture to texture unit $textureUnit.
     */
    public function bind(int $textureUnit = 6, int $cascade = 0): void
    {
        if (!$this->initialized) return;
        if (!isset($this->depthTextures[$cascade])) return;

        glActiveTexture(GL_TEXTURE0 + $textureUnit);
        glBindTexture(GL_TEXTURE_2D, $this->depthTextures[$cascade]);
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
