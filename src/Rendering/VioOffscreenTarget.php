<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use VioContext;
use VioRenderTarget;
use VioTexture;

/**
 * Off-screen render target backing the Phase 1.5 render-scale + AA pipeline
 * on the vio backend.
 *
 * Mirrors `OpenGLOffscreenTarget` semantically. The target is allocated
 * lazily and rebuilt whenever the requested size or sample count changes.
 * vio is responsible for the underlying GPU lifecycle - the previous
 * VioRenderTarget object is dropped (PHP GC releases it) before a new one
 * is created.
 *
 * MSAA support is probed via the `'samples'` config key on first use.
 * If vio rejects the key or returns false for a sample-count > 1 config,
 * the target falls back to single-sample and `msaaSupported()` returns
 * false. Render-scale and FXAA work regardless of MSAA support.
 *
 * Lifecycle: callers invoke `resize($w, $h, $samples)` from
 * `applySettings()`/`beginFrame()`, `bind()` once at the start of the 3D
 * pass, and `texture()` to obtain the colour image for post-process
 * sampling or final blit.
 */
final class VioOffscreenTarget
{
    private int $width = 0;
    private int $height = 0;
    private int $samples = 1;

    private ?VioRenderTarget $target = null;
    private ?VioTexture $textureCache = null;
    private bool $allocated = false;

    /**
     * Tri-state MSAA support cache:
     *   null  - not yet probed
     *   true  - vio accepted a samples>1 target on this context
     *   false - vio rejected; future calls fall back to single-sample
     */
    private ?bool $msaaSupported = null;

    public function __construct(
        private readonly VioContext $ctx,
    ) {
    }

    /**
     * Allocate or rebuild the offscreen target.
     *
     * No-op when the requested size + sample count match the current state.
     * If MSAA was requested but the backend has rejected it earlier in this
     * lifetime, the new target is allocated single-sampled and `samples()`
     * returns 1.
     */
    public function resize(int $width, int $height, int $samples = 1): void
    {
        $width   = max(1, $width);
        $height  = max(1, $height);
        $samples = max(1, $samples);

        // Suppress MSAA on backends that previously refused it.
        if ($samples > 1 && $this->msaaSupported === false) {
            $samples = 1;
        }

        if ($this->allocated && $this->width === $width && $this->height === $height && $this->samples === $samples) {
            return;
        }

        $this->release();

        $this->width   = $width;
        $this->height  = $height;
        $this->samples = $samples;

        // Try MSAA first when requested. vio gives no feature query, so we
        // probe by attempting allocation - on failure we fall back to a
        // single-sample target and remember the rejection.
        if ($samples > 1) {
            $msaa = vio_render_target($this->ctx, [
                'width'   => $width,
                'height'  => $height,
                'samples' => $samples,
            ]);

            if ($msaa !== false) {
                $this->target         = $msaa;
                $this->msaaSupported  = true;
                $this->allocated      = true;
                return;
            }

            // Remember the rejection so future resize() calls skip the probe.
            $this->msaaSupported = false;
            $this->samples       = 1;
            fwrite(STDERR, "[VioOffscreenTarget] MSAA samples={$samples} rejected by vio - falling back to single-sample target.\n");
        }

        $rt = vio_render_target($this->ctx, [
            'width'  => $width,
            'height' => $height,
        ]);

        if ($rt === false) {
            $this->allocated = false;
            return;
        }

        $this->target    = $rt;
        $this->allocated = true;
    }

    /**
     * Bind this target as the active draw target on the vio context.
     * Caller is responsible for setting the viewport afterwards.
     */
    public function bindForDraw(): void
    {
        if (!$this->allocated || $this->target === null) {
            return;
        }
        vio_bind_render_target($this->ctx, $this->target);
    }

    /**
     * Unbind any custom render target so subsequent draws hit the swapchain.
     */
    public function unbind(): void
    {
        vio_unbind_render_target($this->ctx);
    }

    /**
     * Return the colour image of this target for sampling in a post-process
     * pass. Returns null when the target failed to allocate.
     */
    public function texture(): ?VioTexture
    {
        if (!$this->allocated || $this->target === null) {
            return null;
        }
        if ($this->textureCache === null) {
            $this->textureCache = vio_render_target_texture($this->target);
        }
        return $this->textureCache;
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
     * Whether MSAA has been verified working on this context.
     * Returns null until the first samples > 1 resize attempt.
     */
    public function msaaSupported(): ?bool
    {
        return $this->msaaSupported;
    }

    /**
     * Release the underlying vio render target. Safe to call before resize()
     * and during destruction; a second call is a no-op.
     */
    public function release(): void
    {
        // vio releases the GPU resource when the PHP reference is dropped.
        $this->target       = null;
        $this->textureCache = null;
        $this->allocated    = false;
        // Width/height/samples retained so resize() can short-circuit.
    }
}
