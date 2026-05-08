<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use Metal\Device;
use Metal\Texture;
use Metal\TextureDescriptor;

/**
 * Off-screen render-target trio for the Phase 1.5 Metal pipeline.
 *
 * Owns:
 *   - colour texture (single- or multi-sample, used as the main pass'
 *     colour attachment),
 *   - depth texture (matches the colour texture's sample count),
 *   - resolve texture (single-sample, only allocated when MSAA is on).
 *
 * On MSAA-enabled frames the render-pass descriptor sets the resolve
 * attachment to the resolve texture; Metal performs the resolve at the
 * end of the encoder. On non-MSAA frames the colour texture itself is
 * what the FXAA / passthrough pass samples.
 *
 * Lifecycle: lazily allocated and rebuilt whenever the requested size or
 * sample count changes. Old textures drop out of scope so Metal can
 * reclaim them on the next frame boundary.
 */
final class MetalOffscreenTarget
{
    private int $width = 0;
    private int $height = 0;
    private int $samples = 1;

    /** Colour render-target. Texture2D when samples==1, Texture2DMultisample otherwise. */
    private ?Texture $colorTexture = null;
    /** Depth attachment for the main pass; sample count matches $colorTexture. */
    private ?Texture $depthTexture = null;
    /** Single-sample resolve target; only allocated when $samples > 1. */
    private ?Texture $resolveTexture = null;

    private bool $allocated = false;

    public function __construct(
        private readonly Device $device,
        private readonly int $colorPixelFormat,
        private readonly int $depthPixelFormat,
    ) {
    }

    /**
     * Allocate or rebuild the offscreen attachments.
     *
     * No-op when the requested dimensions + sample count match the current
     * state. Caller is responsible for issuing this from the start of a
     * frame so subsequent draws see a stable target.
     */
    public function resize(int $width, int $height, int $samples = 1): void
    {
        $width   = max(1, $width);
        $height  = max(1, $height);
        $samples = max(1, $samples);

        if ($this->allocated
            && $this->width === $width
            && $this->height === $height
            && $this->samples === $samples
        ) {
            return;
        }

        $this->release();

        $this->width   = $width;
        $this->height  = $height;
        $this->samples = $samples;

        // Colour attachment: shader-readable as well as render-target so the
        // FXAA / blit pass can sample it in fragment_fxaa / fragment_blit.
        // Multisample textures are not directly sampleable; when MSAA is on
        // we sample the resolve texture instead - colorTexture is render-only.
        $colorDesc = new TextureDescriptor();
        $colorDesc->setPixelFormat($this->colorPixelFormat);
        $colorDesc->setWidth($width);
        $colorDesc->setHeight($height);
        $colorDesc->setStorageMode(\Metal\StorageModePrivate);

        if ($samples > 1) {
            $colorDesc->setTextureType(\Metal\TextureType2DMultisample);
            $colorDesc->setUsage(\Metal\TextureUsageRenderTarget);
        } else {
            $colorDesc->setTextureType(\Metal\TextureType2D);
            $colorDesc->setUsage(\Metal\TextureUsageRenderTarget | \Metal\TextureUsageShaderRead);
        }

        $this->colorTexture = $this->device->createTexture($colorDesc);

        // Depth attachment: same dimensions + sample count, render-target only.
        $depthDesc = new TextureDescriptor();
        $depthDesc->setPixelFormat($this->depthPixelFormat);
        $depthDesc->setWidth($width);
        $depthDesc->setHeight($height);
        $depthDesc->setUsage(\Metal\TextureUsageRenderTarget);
        $depthDesc->setStorageMode(\Metal\StorageModePrivate);
        $depthDesc->setTextureType($samples > 1
            ? \Metal\TextureType2DMultisample
            : \Metal\TextureType2D);

        $this->depthTexture = $this->device->createTexture($depthDesc);

        // Resolve target: only when MSAA is active. Single-sample, sampleable.
        if ($samples > 1) {
            $resolveDesc = new TextureDescriptor();
            $resolveDesc->setPixelFormat($this->colorPixelFormat);
            $resolveDesc->setWidth($width);
            $resolveDesc->setHeight($height);
            $resolveDesc->setStorageMode(\Metal\StorageModePrivate);
            $resolveDesc->setTextureType(\Metal\TextureType2D);
            $resolveDesc->setUsage(\Metal\TextureUsageRenderTarget | \Metal\TextureUsageShaderRead);
            $this->resolveTexture = $this->device->createTexture($resolveDesc);
        }

        $this->allocated = true;
    }

    public function isAllocated(): bool
    {
        return $this->allocated;
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

    /** Colour render target (multisample when samples > 1). */
    public function colorTexture(): ?Texture
    {
        return $this->colorTexture;
    }

    /** Depth render target. */
    public function depthTexture(): ?Texture
    {
        return $this->depthTexture;
    }

    /** Single-sample resolve texture, only present when MSAA is active. */
    public function resolveTexture(): ?Texture
    {
        return $this->resolveTexture;
    }

    /**
     * Texture the FXAA / passthrough blit pass should sample. When MSAA is
     * on this is the resolve target (post-Metal-resolve); otherwise it is
     * the single-sample colour attachment.
     */
    public function presentTexture(): ?Texture
    {
        return $this->samples > 1
            ? $this->resolveTexture
            : $this->colorTexture;
    }

    public function release(): void
    {
        $this->colorTexture   = null;
        $this->depthTexture   = null;
        $this->resolveTexture = null;
        $this->allocated      = false;
    }
}
