<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use Metal\Device;
use Metal\SamplerState;
use Metal\Texture;
use Metal\TextureDescriptor;
use Metal\SamplerDescriptor;

/**
 * Render-target cubemap for the Metal IBL pipeline.
 *
 * Owns:
 *   - one cubemap texture (RGBA16Float, mipmapped, render-target + shader-read),
 *   - one depth texture (depth32float) shared across all six faces,
 *   - one trilinear sampler with mip filter for shader-side roughness LOD.
 *
 * Usage pattern (driven by MetalRenderer3D):
 *
 *   for face = 0..5:
 *     RenderPassDescriptor:
 *       colorAttachment[0].texture = cubemap
 *       colorAttachment[0].slice   = face
 *       colorAttachment[0].level   = 0   ← always render to mip 0
 *       depthAttachment.texture    = depthTexture
 *     encode sky pass with the face's view matrix
 *
 *   BlitCommandEncoder::generateMipmaps(cubemap)   ← prefilter chain
 *
 * The fragment shader then samples the cubemap with explicit LOD:
 *
 *   cubemap.sample(s, R, level(roughness * (mipCount - 1)))
 *
 * which yields trilinear roughness-driven blur identical to industry IBL
 * pipelines (just without the split-sum BRDF lookup texture - that's a
 * future iteration).
 */
final class MetalCubemapTarget
{
    /**
     * Resolution per face. 256² is a sensible default — large enough for
     * sharp mirror reflections on shiny carpaint, small enough that the
     * six render passes stay well below the per-frame budget.
     */
    private const FACE_SIZE = 256;

    /** floor(log2(FACE_SIZE)) + 1. Hard-coded so the shader can match. */
    private const MIP_LEVELS = 9;

    private ?Texture $cubemap = null;
    private ?Texture $depthTexture = null;
    private ?SamplerState $sampler = null;

    private bool $allocated = false;
    /**
     * Hash of the most recently rendered SetSky parameters. The renderer
     * skips the six face passes when the hash hasn't changed, since the
     * sky is the only thing that drives the cubemap content.
     */
    private string $lastSkyHash = '';

    public function __construct(
        private readonly Device $device,
        private readonly int $colorPixelFormat = \Metal\PixelFormatRGBA16Float,
        private readonly int $depthPixelFormat = \Metal\PixelFormatDepth32Float,
    ) {
    }

    /**
     * Lazily allocate the cubemap, depth texture, and sampler. Idempotent:
     * subsequent calls are no-ops once allocation succeeds.
     *
     * Returns false when allocation throws (e.g. Metal device out of
     * memory). The caller can safely fall back to the sky-tinted pseudo-
     * IBL path while $allocated remains false.
     */
    public function ensureAllocated(): bool
    {
        if ($this->allocated) {
            return true;
        }

        try {
            // ─── Cubemap colour attachment ──────────────────────────────
            $cubeDesc = new TextureDescriptor();
            $cubeDesc->setPixelFormat($this->colorPixelFormat);
            $cubeDesc->setWidth(self::FACE_SIZE);
            $cubeDesc->setHeight(self::FACE_SIZE);
            $cubeDesc->setTextureType(\Metal\TextureTypeCube);
            $cubeDesc->setUsage(\Metal\TextureUsageRenderTarget | \Metal\TextureUsageShaderRead);
            $cubeDesc->setStorageMode(\Metal\StorageModePrivate);
            $cubeDesc->setMipmapLevelCount(self::MIP_LEVELS);
            $cubeDesc->setArrayLength(1);

            $this->cubemap = $this->device->createTexture($cubeDesc);

            // ─── Shared depth attachment ────────────────────────────────
            // The sky pass uses depth-test "always pass, never write", so a
            // single 2D depth texture is enough for all six faces - no
            // slice/level configuration needed.
            $depthDesc = new TextureDescriptor();
            $depthDesc->setPixelFormat($this->depthPixelFormat);
            $depthDesc->setWidth(self::FACE_SIZE);
            $depthDesc->setHeight(self::FACE_SIZE);
            $depthDesc->setTextureType(\Metal\TextureType2D);
            $depthDesc->setUsage(\Metal\TextureUsageRenderTarget);
            $depthDesc->setStorageMode(\Metal\StorageModePrivate);

            $this->depthTexture = $this->device->createTexture($depthDesc);

            // ─── Trilinear sampler with mip filter ─────────────────────
            $samplerDesc = new SamplerDescriptor();
            $samplerDesc->setMinFilter(\Metal\SamplerMinMagFilterLinear);
            $samplerDesc->setMagFilter(\Metal\SamplerMinMagFilterLinear);
            $samplerDesc->setMipFilter(\Metal\SamplerMipFilterLinear);
            $samplerDesc->setSAddressMode(\Metal\SamplerAddressModeClampToEdge);
            $samplerDesc->setTAddressMode(\Metal\SamplerAddressModeClampToEdge);
            $samplerDesc->setRAddressMode(\Metal\SamplerAddressModeClampToEdge);
            $samplerDesc->setLodMinClamp(0.0);
            $samplerDesc->setLodMaxClamp((float)(self::MIP_LEVELS - 1));
            $samplerDesc->setMaxAnisotropy(1);

            $this->sampler = $this->device->createSamplerState($samplerDesc);

            $this->allocated = true;
            return true;
        } catch (\Throwable) {
            $this->cubemap = null;
            $this->depthTexture = null;
            $this->sampler = null;
            $this->allocated = false;
            return false;
        }
    }

    public function isAllocated(): bool
    {
        return $this->allocated;
    }

    public function cubemap(): ?Texture
    {
        return $this->cubemap;
    }

    public function depthTexture(): ?Texture
    {
        return $this->depthTexture;
    }

    public function sampler(): ?SamplerState
    {
        return $this->sampler;
    }

    public function faceSize(): int
    {
        return self::FACE_SIZE;
    }

    public function mipLevels(): int
    {
        return self::MIP_LEVELS;
    }

    /**
     * Returns true when the supplied hash differs from the last rendered
     * sky configuration; in that case the caller should re-render all six
     * faces and regenerate mipmaps. The renderer is expected to call
     * markRendered() with the same hash once it finishes the update.
     */
    public function needsUpdate(string $skyHash): bool
    {
        return $this->lastSkyHash !== $skyHash;
    }

    public function markRendered(string $skyHash): void
    {
        $this->lastSkyHash = $skyHash;
    }

    /**
     * View matrices for the six standard cubemap faces, in Metal slice
     * order (+X, -X, +Y, -Y, +Z, -Z). All matrices look from the world
     * origin; the camera has no translation because the sky is treated as
     * infinitely distant.
     *
     * Each matrix is a column-major float[16] suitable for direct upload
     * via Mat4 / pack('f16', ...).
     *
     * @return array{0: float[], 1: float[], 2: float[], 3: float[], 4: float[], 5: float[]}
     */
    public static function faceViewMatrices(): array
    {
        // Right-handed look-at, no translation. Up vectors flip on +Y / -Y
        // so the resulting cube is consistent with Metal's left-handed
        // cubemap convention.
        return [
            self::lookAt([1.0, 0.0, 0.0],  [0.0, -1.0, 0.0]),  // +X
            self::lookAt([-1.0, 0.0, 0.0], [0.0, -1.0, 0.0]),  // -X
            self::lookAt([0.0, 1.0, 0.0],  [0.0, 0.0, 1.0]),   // +Y
            self::lookAt([0.0, -1.0, 0.0], [0.0, 0.0, -1.0]),  // -Y
            self::lookAt([0.0, 0.0, 1.0],  [0.0, -1.0, 0.0]),  // +Z
            self::lookAt([0.0, 0.0, -1.0], [0.0, -1.0, 0.0]),  // -Z
        ];
    }

    /**
     * Build a 90° FOV, aspect=1, near=0.1, far=10 projection matrix for
     * cube face rendering. Same Z-correction trick the main renderer uses
     * to map OpenGL clip space to Metal clip space.
     *
     * @return float[]
     */
    public static function faceProjectionMatrix(): array
    {
        // Standard perspective with FOV = 90° (tan(45°) = 1), aspect = 1.
        $near = 0.1;
        $far  = 10.0;
        $f    = 1.0; // 1 / tan(fov/2) with fov = 90°

        // OpenGL-style projection (mirrors the engine's Mat4::perspective).
        $proj = [
            $f,  0.0, 0.0,                              0.0,
            0.0, $f,  0.0,                              0.0,
            0.0, 0.0, ($far + $near) / ($near - $far),  -1.0,
            0.0, 0.0, (2.0 * $far * $near) / ($near - $far), 0.0,
        ];

        // Metal clip-space correction: z' = z * 0.5 + 0.5 (matches the
        // metalClip multiplier in MetalRenderer3D::encodeSkyPass).
        $clip = [
            1.0, 0.0, 0.0, 0.0,
            0.0, 1.0, 0.0, 0.0,
            0.0, 0.0, 0.5, 0.0,
            0.0, 0.0, 0.5, 1.0,
        ];

        return self::matMul($clip, $proj);
    }

    /**
     * @param float[] $forward unit vector
     * @param float[] $up      unit vector, not parallel to $forward
     * @return float[] column-major 4×4
     */
    private static function lookAt(array $forward, array $up): array
    {
        // Right vector = up × forward
        $rx = $up[1] * $forward[2] - $up[2] * $forward[1];
        $ry = $up[2] * $forward[0] - $up[0] * $forward[2];
        $rz = $up[0] * $forward[1] - $up[1] * $forward[0];
        $rl = sqrt($rx * $rx + $ry * $ry + $rz * $rz);
        if ($rl > 1e-9) { $rx /= $rl; $ry /= $rl; $rz /= $rl; }

        // Recompute up = forward × right so the basis is orthonormal.
        $ux = $forward[1] * $rz - $forward[2] * $ry;
        $uy = $forward[2] * $rx - $forward[0] * $rz;
        $uz = $forward[0] * $ry - $forward[1] * $rx;

        // Column-major view matrix (pure rotation, no translation).
        return [
            $rx,           $ux,           -$forward[0], 0.0,
            $ry,           $uy,           -$forward[1], 0.0,
            $rz,           $uz,           -$forward[2], 0.0,
            0.0,           0.0,           0.0,          1.0,
        ];
    }

    /**
     * Column-major 4×4 multiplication: result = a * b.
     *
     * @param float[] $a
     * @param float[] $b
     * @return float[]
     */
    private static function matMul(array $a, array $b): array
    {
        $r = array_fill(0, 16, 0.0);
        for ($i = 0; $i < 4; $i++) {
            for ($j = 0; $j < 4; $j++) {
                $sum = 0.0;
                for ($k = 0; $k < 4; $k++) {
                    $sum += $a[$k * 4 + $j] * $b[$i * 4 + $k];
                }
                $r[$i * 4 + $j] = $sum;
            }
        }
        return $r;
    }
}
