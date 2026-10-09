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
     * Temporal AA: per-frame sub-pixel jitter on the projection, motion
     * vectors and a reprojected, neighbourhood-clipped history. The vio
     * renderer resolves it with {@see \PHPolygon\Rendering\PostProcess\VioTaaPass}
     * (the same pass upsamples for {@see Upscaler::Taau}); the OpenGL
     * renderer with {@see \PHPolygon\Rendering\PostProcess\OpenGLTaaPass}.
     * Where a renderer cannot run it ({@see GraphicsCapabilities::$temporalAntiAliasing})
     * it falls back to FXAA via {@see fallback()}.
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
     * Effective AA mode on a renderer without a temporal pass: TAA degrades
     * to FXAA, everything else stays. Renderers that run TAA ignore it.
     */
    public function fallback(): self
    {
        return $this === self::Taa ? self::Fxaa : $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::Fxaa => 'FXAA',
            self::Msaa2x => 'MSAA 2x',
            self::Msaa4x => 'MSAA 4x',
            self::Taa  => 'TAA',
        };
    }
}
