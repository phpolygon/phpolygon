<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

use PHPolygon\Math\Mat4;

/**
 * One frame of {@see TemporalCamera}: the jittered camera plus what the
 * motion vectors and the temporal resolve need from the previous frame.
 */
final class TemporalFrame
{
    public function __construct(
        /** This frame's jittered camera and its unjittered matrices. */
        public readonly JitteredProjection $jitter,
        /** Last frame's unjittered view-projection (this frame's when the history was dropped). */
        public readonly Mat4 $prevViewProjection,
        /** prevViewProjection x inverse(unjittered view-projection): this frame's clip space to last frame's. */
        public readonly Mat4 $reprojection,
        /** False on the first frame and after a cut, resize or reset: nothing to accumulate. */
        public readonly bool $historyValid,
        /** Why the history was dropped ('first', 'cut', 'resize', or the reset() reason); null when valid. */
        public readonly ?string $resetReason,
        /** Last frame's animation clock (this frame's when the history was dropped). */
        public readonly float $prevTime,
    ) {}
}
