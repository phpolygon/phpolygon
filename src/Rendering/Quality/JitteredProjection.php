<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

use PHPolygon\Math\Mat4;

/**
 * One frame's camera for temporal anti-aliasing / upscaling
 * ({@see TemporalJitter::jitter()}).
 *
 * $projection is the jittered copy the scene is rasterised with. Motion
 * vectors, culling, reprojection of last frame and anything that must not
 * shimmer (sky reconstruction, UI anchored to the world) use the unjittered
 * matrices - otherwise the jitter itself shows up as motion.
 *
 * The offsets follow NDC convention (+Y up). A shader that converts them to
 * texture space on a backend whose render targets are Y-flipped
 * ({@see \PHPolygon\Rendering\BackendConventions::flipRenderTargetClipY()})
 * negates the Y component, exactly as for the motion vectors themselves.
 */
final class JitteredProjection
{
    public function __construct(
        /** Projection with the sub-pixel offset applied - rasterise with this. */
        public readonly Mat4 $projection,
        /** The projection as the camera built it. */
        public readonly Mat4 $unjitteredProjection,
        /** unjitteredProjection x view: the matrix motion vectors are computed with. */
        public readonly Mat4 $unjitteredViewProjection,
        /** Offset in render-target pixels, each in [-0.5, 0.5). */
        public readonly float $pixelX,
        public readonly float $pixelY,
        /** The same offset in NDC units (2 * pixel / render size). */
        public readonly float $ndcX,
        public readonly float $ndcY,
        /** Index into the sequence and its length (0 / 1 when jitter is off). */
        public readonly int $phase,
        public readonly int $phaseCount,
    ) {}
}
