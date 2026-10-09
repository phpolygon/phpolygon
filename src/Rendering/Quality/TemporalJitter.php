<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

use PHPolygon\Math\Mat4;

/**
 * Camera jitter for temporal anti-aliasing and temporal upscaling.
 *
 * Builds on {@see TaaJitter}'s Halton(2, 3) sequence with the two things a
 * temporal upscaler adds:
 *
 *  - the sequence length scales with the upscale ratio, ceil(8 * (display /
 *    render)^2) phases, so every display pixel still receives about eight
 *    samples per cycle (the rule FSR 2/3 and DLSS document), and
 *  - the offset is applied to a COPY of the projection, keeping the
 *    unjittered projection and view-projection separate for motion vectors.
 *
 * The jitter is a clip-space translation (NDC.xy += offset), applied as
 * row0 += dx * row3 and row1 += dy * row3. That form is exact for perspective
 * and orthographic projections alike and leaves depth untouched.
 *
 * Pure math, no GPU state: the renderer decides when to use it.
 */
final class TemporalJitter
{
    /** Phases at native resolution; scaled by the squared upscale ratio. */
    public const BASE_PHASES = 8;

    /** ceil(8 * (display / render)^2) along the horizontal axis, at least 1. */
    public static function phaseCount(int $renderWidth, int $displayWidth): int
    {
        if ($renderWidth <= 0 || $displayWidth <= 0) {
            return self::BASE_PHASES;
        }
        $ratio = $displayWidth / $renderWidth;
        // The epsilon keeps exact ratios (1.5 -> 18) from rounding up a step.
        return max(1, (int) ceil(self::BASE_PHASES * $ratio * $ratio - 1e-9));
    }

    /**
     * Sub-pixel offset of a frame in render-target pixels, centred to
     * [-0.5, 0.5); repeats every $phaseCount frames.
     *
     * @return array{0: float, 1: float}
     */
    public static function pixelOffset(int $frameIndex, int $phaseCount): array
    {
        $count = max(1, $phaseCount);
        $i = (($frameIndex % $count) + $count) % $count + 1; // Halton starts at 1
        return [TaaJitter::halton($i, 2) - 0.5, TaaJitter::halton($i, 3) - 0.5];
    }

    /**
     * The frame's jittered camera. $renderWidth/$renderHeight are the size of
     * the target the scene rasterises into, $displayWidth the width it is
     * finally presented at (equal to $renderWidth for TAA without upscaling).
     */
    public static function jitter(
        Mat4 $view,
        Mat4 $projection,
        int $frameIndex,
        int $renderWidth,
        int $renderHeight,
        int $displayWidth,
    ): JitteredProjection {
        $phases = self::phaseCount($renderWidth, $displayWidth);
        $phase = (($frameIndex % $phases) + $phases) % $phases;
        [$px, $py] = self::pixelOffset($phase, $phases);
        $dx = 2.0 * $px / max(1, $renderWidth);
        $dy = 2.0 * $py / max(1, $renderHeight);

        // Column-major: element (row, col) sits at col * 4 + row.
        $m = $projection->toArray();
        for ($col = 0; $col < 4; $col++) {
            $w = $m[$col * 4 + 3];
            $m[$col * 4] += $dx * $w;
            $m[$col * 4 + 1] += $dy * $w;
        }

        return new JitteredProjection(
            projection: new Mat4($m),
            unjitteredProjection: $projection,
            unjitteredViewProjection: $projection->multiply($view),
            pixelX: $px,
            pixelY: $py,
            ndcX: $dx,
            ndcY: $dy,
            phase: $phase,
            phaseCount: $phases,
        );
    }

    /** Jitter switched off: the camera's own projection, no offset. */
    public static function none(Mat4 $view, Mat4 $projection): JitteredProjection
    {
        return new JitteredProjection(
            projection: $projection,
            unjitteredProjection: $projection,
            unjitteredViewProjection: $projection->multiply($view),
            pixelX: 0.0,
            pixelY: 0.0,
            ndcX: 0.0,
            ndcY: 0.0,
            phase: 0,
            phaseCount: 1,
        );
    }
}
