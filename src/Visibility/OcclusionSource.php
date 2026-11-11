<?php

declare(strict_types=1);

namespace PHPolygon\Visibility;

/**
 * Optional occluder a game plugs into {@see VisibilityService::setOcclusionSource()}
 * (e.g. a baked `VisibilityZone` graph of rooms/portals) to drop a sphere
 * that passed the frustum test but sits behind occluding geometry. No
 * concrete implementation ships with the engine yet - Phase 3 of the
 * visibility plan only wires the call site in, same as {@see
 * VisibilityService::tierAt()}'s Phase-4 stub.
 */
interface OcclusionSource
{
    /** True when $center/$radius is hidden from the current frame's viewpoint. */
    public function occludes(float $x, float $y, float $z, float $radius): bool;
}
