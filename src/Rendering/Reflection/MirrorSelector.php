<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Reflection;

use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\SetPlanarMirror;
use PHPolygon\Rendering\Frustum;

/**
 * Which of several mirrors is drawn this frame. A mirror qualifies when the eye is in
 * front of it (its reflecting side), it lies within its own distance limit, and its
 * rectangle's bounding sphere intersects the camera frustum. Of those the one that
 * covers the most of the view wins: the largest of (radius / distance)^2 - the mirror
 * the player looks into, not the one behind. Ties keep the earlier candidate.
 */
final class MirrorSelector
{
    private function __construct() {}

    /**
     * @param list<SetPlanarMirror> $candidates
     * @param list<array{0: float, 1: float, 2: float, 3: float}>|null $frustumPlanes null = no culling
     */
    public static function choose(array $candidates, Vec3 $eye, ?array $frustumPlanes): ?SetPlanarMirror
    {
        $best = null;
        $bestScore = 0.0;
        foreach ($candidates as $mirror) {
            $score = self::score($mirror, $eye, $frustumPlanes);
            if ($score !== null && $score > $bestScore) {
                $best = $mirror;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Screen-coverage score of a qualifying mirror, null when it does not qualify.
     *
     * @param list<array{0: float, 1: float, 2: float, 3: float}>|null $frustumPlanes
     */
    public static function score(SetPlanarMirror $mirror, Vec3 $eye, ?array $frustumPlanes): ?float
    {
        $offset = $eye->sub($mirror->center);
        // Eye behind (or in) the plane: the reflecting side is not seen.
        if ($offset->dot($mirror->normal) <= 1e-4) {
            return null;
        }
        $distance = $offset->length();
        if ($mirror->maxDistance > 0.0 && $distance - $mirror->boundingRadius() > $mirror->maxDistance) {
            return null;
        }
        $radius = $mirror->boundingRadius();
        if ($frustumPlanes !== null
            && Frustum::sphereOutside($frustumPlanes, $mirror->center->x, $mirror->center->y, $mirror->center->z, $radius)) {
            return null;
        }
        $d = max($distance, 0.05);

        return ($radius * $radius) / ($d * $d);
    }
}
