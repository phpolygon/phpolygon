<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

use PHPolygon\Math\Mat4;

/**
 * Frame-to-frame camera memory of a temporal renderer (motion vectors, TAA,
 * temporal upscaling).
 *
 * Each frame {@see begin()} jitters the camera ({@see TemporalJitter}) and
 * pairs it with last frame's UNJITTERED view-projection, the matrix motion
 * vectors reproject into. The history is dropped - no previous matrix, the
 * camera has no motion of its own, the resolve starts over - on the first
 * frame, a camera cut ({@see \PHPolygon\Rendering\Command\SetCamera::$cut}),
 * a change of the render or display size, and after {@see reset()} (settings
 * change, world clear).
 *
 * Pure math, no GPU state.
 */
final class TemporalCamera
{
    private ?Mat4 $prevViewProjection = null;
    private ?float $prevTime = null;
    private int $frameIndex = 0;
    /** @var array{0: int, 1: int, 2: int, 3: int}|null */
    private ?array $size = null;
    private ?string $pendingReset = null;

    /**
     * @param int   $renderWidth  size of the target the scene rasterises into
     * @param int   $displayWidth size the resolve writes (equal to the render
     *                            size without upscaling)
     * @param bool  $jitter       false: the camera's own projection (motion
     *                            vectors without temporal accumulation)
     * @param float $time         the frame clock the vertex animation runs on
     */
    public function begin(
        Mat4 $view,
        Mat4 $projection,
        bool $cut,
        int $renderWidth,
        int $renderHeight,
        int $displayWidth,
        int $displayHeight,
        bool $jitter,
        float $time = 0.0,
    ): TemporalFrame {
        $size = [$renderWidth, $renderHeight, $displayWidth, $displayHeight];
        $reason = $this->pendingReset;
        if ($this->prevViewProjection === null) {
            $reason ??= 'first';
        } elseif ($cut) {
            $reason = 'cut';
        } elseif ($this->size !== $size) {
            $reason ??= 'resize';
        }
        $this->pendingReset = null;
        $this->size = $size;

        $frame = $jitter
            ? TemporalJitter::jitter($view, $projection, $this->frameIndex, $renderWidth, $renderHeight, $displayWidth)
            : TemporalJitter::none($view, $projection);
        $this->frameIndex++;

        $current = $frame->unjitteredViewProjection;
        $valid = $reason === null;
        $prev = $valid && $this->prevViewProjection !== null ? $this->prevViewProjection : $current;
        $prevTime = $valid && $this->prevTime !== null ? $this->prevTime : $time;

        $this->prevViewProjection = $current;
        $this->prevTime = $time;

        return new TemporalFrame(
            jitter: $frame,
            prevViewProjection: $prev,
            reprojection: $valid ? $prev->multiply($current->inverse()) : Mat4::identity(),
            historyValid: $valid,
            resetReason: $reason,
            prevTime: $prevTime,
        );
    }

    /** Drop the history at the next {@see begin()}, e.g. after a settings change or a world clear. */
    public function reset(string $reason = 'reset'): void
    {
        $this->pendingReset = $reason;
    }

    /** Frames begun so far (the jitter sequence position). */
    public function frameIndex(): int
    {
        return $this->frameIndex;
    }
}
