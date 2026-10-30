<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Math\Vec4;

/**
 * World to screen for markers and overlays drawn in 2D over the 3D scene (y
 * down, origin top left). For text that belongs to an object use
 * {@see \PHPolygon\Component\SurfaceText}.
 */
final class ScreenProjection
{
    private function __construct() {}

    /**
     * Pixel position of $world (z = 0), or null behind the camera or off screen.
     *
     * @param float $margin how far past the screen edge still counts as on
     *                      screen, in viewport half-widths (0.2 = 20 % beyond,
     *                      for a marker that slides out instead of popping)
     */
    public static function worldToScreen(Vec3 $world, Mat4 $viewProjection, float $width, float $height, float $margin = 0.0): ?Vec3
    {
        $clip = $viewProjection->multiplyVec4(new Vec4($world->x, $world->y, $world->z, 1.0));
        if ($clip->w <= 0.0) {
            return null;
        }

        $ndcX = $clip->x / $clip->w;
        $ndcY = $clip->y / $clip->w;
        $limit = 1.0 + $margin;
        if ($ndcX < -$limit || $ndcX > $limit || $ndcY < -$limit || $ndcY > $limit) {
            return null;
        }

        return new Vec3(($ndcX + 1.0) * 0.5 * $width, (1.0 - $ndcY) * 0.5 * $height, 0.0);
    }

    /** World position of the camera with this view matrix. */
    public static function cameraPosition(Mat4 $view): Vec3
    {
        return $view->inverse()->getTranslation();
    }
}
