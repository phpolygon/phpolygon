<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Math\Vec3;

/**
 * Decides whether a rendered camera frame continues the previous one or is a
 * cut ({@see Command\SetCamera::$cut}): the first frame, a switch to another
 * camera entity, or an eye jump larger than a teleport threshold between two
 * consecutive rendered frames. Temporal passes drop their history on a cut.
 *
 * Camera systems feed it the eye they actually render with, once per frame,
 * and call {@see reset()} on World::clear() (entity ids restart there).
 */
final class CameraCutDetector
{
    /** 5 world units: the same teleport threshold the 3D camera snaps at. */
    public const DEFAULT_JUMP_DIST_SQ = 25.0;

    private ?int $cameraId = null;
    private ?Vec3 $eye = null;

    public function __construct(
        private readonly float $jumpDistSq = self::DEFAULT_JUMP_DIST_SQ,
    ) {}

    /** Record this frame's camera; true when it breaks continuity with the last one. */
    public function observe(int $cameraId, Vec3 $eye): bool
    {
        $prevId = $this->cameraId;
        $prevEye = $this->eye;
        $this->cameraId = $cameraId;
        $this->eye = $eye;

        if ($prevId !== $cameraId || $prevEye === null) {
            return true;
        }
        $dx = $eye->x - $prevEye->x;
        $dy = $eye->y - $prevEye->y;
        $dz = $eye->z - $prevEye->z;
        return $dx * $dx + $dy * $dy + $dz * $dz > $this->jumpDistSq;
    }

    public function reset(): void
    {
        $this->cameraId = null;
        $this->eye = null;
    }
}
