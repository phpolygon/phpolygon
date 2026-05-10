<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * Anti-aliasing technique selection.
 *
 * Off:    No AA, fastest path.
 * FXAA:   Cheap post-process AA, no FBO sample multiplier.
 * MSAA2x: 2x multisample anti-aliasing on the main framebuffer.
 * MSAA4x: 4x multisample anti-aliasing on the main framebuffer.
 *
 * Implementation: every Renderer3D backend owns a multisample off-screen
 * target that resolves into the present buffer. Sample-count > 1 may be
 * rejected by the underlying GPU/driver - in that case the offscreen
 * target falls back to single-sample silently and {@see sampleCount()}
 * still reports the requested value (the renderer logs the rejection
 * once on STDERR for diagnostics).
 *
 * AA mode interaction with the offscreen pipeline:
 *   Off    : fast path, no offscreen target unless renderScale != 1.
 *   FXAA   : offscreen target + fullscreen post-process.
 *   MSAA2x : multisample offscreen target, resolve into single-sample
 *            blit during present.
 *   MSAA4x : same as MSAA2x with 4x sample count.
 */
enum AntiAliasing: string
{
    case Off = 'off';
    case Fxaa = 'fxaa';
    case Msaa2x = 'msaa2x';
    case Msaa4x = 'msaa4x';
    /**
     * Temporal AA. Implemented on the OpenGL backend as
     * {@see \PHPolygon\Rendering\PostProcess\OpenGLTaaPass}: per-frame
     * Halton sub-pixel jitter on the projection matrix, neighbourhood-
     * clamped composite against a private history target. Vio and Metal
     * still fall back to FXAA via {@see fallback()} until their post-
     * process chains are migrated; renderers that own a real TAA pass
     * ignore the fall-back and use this case directly.
     */
    case Taa = 'taa';

    public function sampleCount(): int
    {
        return match ($this) {
            self::Off, self::Fxaa, self::Taa => 1,
            self::Msaa2x => 2,
            self::Msaa4x => 4,
        };
    }

    /**
     * Effective AA mode after capability fall-back. Kept around for
     * shaders/backends that don't yet implement every mode - they can
     * call this and degrade silently. The OpenGL backend now ships a
     * real TAA pass so this is a no-op there; older Vio/Metal paths
     * that haven't migrated still benefit from the FXAA fall-back.
     */
    public function fallback(): self
    {
        // TAA is implemented on the OpenGL backend (with history buffer
        // + neighbourhood-clamped composite). Keep the fall-back as a
        // safety net for backends without a TAA pass; renderers that
        // do support TAA should ignore this mapping.
        return $this === self::Taa ? self::Fxaa : $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::Fxaa => 'FXAA',
            self::Msaa2x => 'MSAA 2x',
            self::Msaa4x => 'MSAA 4x',
            self::Taa  => 'TAA (preview)',
        };
    }
}
