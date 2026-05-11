<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * Sub-pixel jitter generator for Temporal Anti-Aliasing.
 *
 * TAA needs the camera to nudge by a sub-pixel amount each frame so a
 * moving accumulation buffer ends up sampling many positions inside one
 * output pixel. The Halton sequence delivers low-discrepancy 2D points
 * that distribute evenly across the [0, 1)^2 unit square - far better
 * than uniform random or a fixed grid pattern.
 *
 * Pattern: Halton(2, 3), centred to [-0.5, 0.5]^2 and scaled by the
 * inverse render-target dimensions. Eight-sample period is the common
 * default; longer runs converge to a finer grid but increase ghosting
 * sensitivity for scenes with quickly-moving content.
 *
 * Usage from a renderer:
 *
 * ```php
 * $jitter = TaaJitter::offset($frameIndex, $rtWidth, $rtHeight);
 * // Add the jitter to the projection matrix's last column (NDC offset):
 * //   projection.m[8]  += jitter[0] * 2.0
 * //   projection.m[9]  += jitter[1] * 2.0
 * // (or build a translated projection - the wire-up belongs to the
 * // renderer; this helper only owns the deterministic offset.)
 * ```
 *
 * The full history-buffer composite pass that consumes these offsets
 * is the follow-up iteration; this helper exists today so games can
 * be written against the eventual API and so the math layer is unit-
 * tested independently of GPU code.
 */
final class TaaJitter
{
    /** Default Halton sample-period - one full pattern in 8 frames. */
    public const DEFAULT_SAMPLE_COUNT = 8;

    /**
     * Sub-pixel jitter offset for the given frame index, in render-target
     * texel units (i.e. [-0.5/width, +0.5/width] x [-0.5/height, ...]).
     *
     * @return array{0: float, 1: float}
     */
    public static function offset(int $frameIndex, int $rtWidth, int $rtHeight, int $sampleCount = self::DEFAULT_SAMPLE_COUNT): array
    {
        $sampleCount = max(1, $sampleCount);
        // Halton starts at index 1; the first sample is well-distributed.
        $i = ($frameIndex % $sampleCount) + 1;
        $x = self::halton($i, 2) - 0.5;
        $y = self::halton($i, 3) - 0.5;
        $w = max(1, $rtWidth);
        $h = max(1, $rtHeight);
        return [$x / $w, $y / $h];
    }

    /**
     * Halton(index, base). Standard radical-inverse implementation; for
     * the typical 8-sample period the loop terminates after at most 4
     * iterations.
     */
    public static function halton(int $index, int $base): float
    {
        $result = 0.0;
        $f = 1.0;
        $i = $index;
        while ($i > 0) {
            $f /= (float) $base;
            $result += $f * (float) ($i % $base);
            $i = (int) ($i / $base);
        }
        return $result;
    }
}
