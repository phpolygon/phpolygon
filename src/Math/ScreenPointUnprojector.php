<?php

declare(strict_types=1);

namespace PHPolygon\Math;

/**
 * Converts screen-space pixel coordinates into world-space rays.
 *
 * Works with both perspective and orthographic projections by
 * unprojecting near/far clip points through the inverse VP matrix.
 */
class ScreenPointUnprojector
{
    private Mat4 $inverseVP;

    public function __construct(
        Mat4 $viewMatrix,
        Mat4 $projectionMatrix,
        private readonly int $viewportWidth,
        private readonly int $viewportHeight,
    ) {
        $vp = $projectionMatrix->multiply($viewMatrix);
        $this->inverseVP = $vp->inverse();
    }

    /**
     * Create a world-space ray from a screen pixel coordinate.
     *
     * @param float $screenX Pixel X (0 = left edge)
     * @param float $screenY Pixel Y (0 = top edge)
     */
    public function screenToRay(float $screenX, float $screenY): Ray
    {
        // Normalize to [-1, 1] NDC
        $ndcX = (2.0 * $screenX / $this->viewportWidth) - 1.0;
        $ndcY = 1.0 - (2.0 * $screenY / $this->viewportHeight);

        // Unproject near and far points
        $nearWorld = $this->unprojectNDC($ndcX, $ndcY, -1.0);
        $farWorld  = $this->unprojectNDC($ndcX, $ndcY, 1.0);

        $direction = $farWorld->sub($nearWorld);

        return new Ray($nearWorld, $direction);
    }

    /**
     * Convenience: screen pixel to ground plane intersection.
     */
    public function screenToGround(float $screenX, float $screenY, float $groundY = 0.0): ?Vec3
    {
        $ray = $this->screenToRay($screenX, $screenY);
        return $ray->intersectsGroundPlane($groundY);
    }

    /**
     * Convenience: screen pixel to grid cell (integer XZ coordinates).
     *
     * @return array{x: int, z: int}|null
     */
    public function screenToGridCell(float $screenX, float $screenY, float $cellSize = 1.0, float $groundY = 0.0): ?array
    {
        $point = $this->screenToGround($screenX, $screenY, $groundY);
        if ($point === null) {
            return null;
        }

        return [
            'x' => (int) floor($point->x / $cellSize),
            'z' => (int) floor($point->z / $cellSize),
        ];
    }

    private function unprojectNDC(float $ndcX, float $ndcY, float $ndcZ): Vec3
    {
        $clip = new Vec4($ndcX, $ndcY, $ndcZ, 1.0);
        $world = $this->inverseVP->multiplyVec4($clip);

        if (abs($world->w) > 1e-10) {
            return new Vec3($world->x / $world->w, $world->y / $world->w, $world->z / $world->w);
        }

        return new Vec3($world->x, $world->y, $world->z);
    }
}
