<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;

/**
 * Snapshot of the camera a frame renders with: the view/projection matrices,
 * their six Frustum planes, and the eye position they were extracted from.
 * Returned by {@see \PHPolygon\Visibility\VisibilityService::frame()} so a
 * system that needs the raw planes (shadow cascades, a custom cull in Phase
 * 3) does not have to re-derive them from a SetCamera command itself.
 */
final class ViewVolume
{
    /**
     * @param list<array{0:float,1:float,2:float,3:float}> $planes
     */
    public function __construct(
        public readonly Mat4 $viewMatrix,
        public readonly Mat4 $projectionMatrix,
        public readonly array $planes,
        public readonly Vec3 $cameraPosition,
    ) {
    }

    /** True unless the sphere lies entirely outside at least one plane. */
    public function containsSphere(float $x, float $y, float $z, float $radius): bool
    {
        return !Frustum::sphereOutside($this->planes, $x, $y, $z, $radius);
    }
}
