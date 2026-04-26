<?php

declare(strict_types=1);

namespace PHPolygon\Navigation;

use PHPolygon\Math\Vec3;

/**
 * Simple Stupid Funnel Algorithm for path smoothing.
 *
 * Takes a corridor (sequence of polygon IDs) and the portal edges
 * between them, then computes the shortest path through the portals.
 */
class FunnelSmoother
{
    /**
     * Smooth a corridor of polygons into a direct waypoint path.
     *
     * @param Vec3 $start World-space start position.
     * @param Vec3 $end World-space end position.
     * @param NavMeshEdge[] $portals Portal edges in corridor order.
     */
    public function smooth(Vec3 $start, Vec3 $end, array $portals): NavMeshPath
    {
        if (count($portals) === 0) {
            return new NavMeshPath([$start, $end]);
        }

        // Build portal list: start apex + portals + end apex
        $leftVertices = [$start];
        $rightVertices = [$start];

        foreach ($portals as $portal) {
            $leftVertices[] = $portal->left;
            $rightVertices[] = $portal->right;
        }

        $leftVertices[] = $end;
        $rightVertices[] = $end;

        // Funnel algorithm
        $path = [$start];
        $apexIndex = 0;
        $leftIndex = 0;
        $rightIndex = 0;

        $apex = $start;
        $funnelLeft = $start;
        $funnelRight = $start;

        $n = count($leftVertices);

        for ($i = 1; $i < $n; $i++) {
            $newLeft = $leftVertices[$i];
            $newRight = $rightVertices[$i];

            // Update right funnel side
            if (self::triArea2D($apex, $funnelRight, $newRight) <= 0.0) {
                if ($apex->equals($funnelRight) || self::triArea2D($apex, $funnelLeft, $newRight) > 0.0) {
                    // Tighten the funnel
                    $funnelRight = $newRight;
                    $rightIndex = $i;
                } else {
                    // Right crosses left - add left as new apex
                    $path[] = $funnelLeft;
                    $apex = $funnelLeft;
                    $apexIndex = $leftIndex;

                    // Reset funnel
                    $funnelRight = $apex;
                    $rightIndex = $apexIndex;

                    // Restart scan from the apex
                    $i = $apexIndex;
                    continue;
                }
            }

            // Update left funnel side
            if (self::triArea2D($apex, $funnelLeft, $newLeft) >= 0.0) {
                if ($apex->equals($funnelLeft) || self::triArea2D($apex, $funnelRight, $newLeft) < 0.0) {
                    // Tighten the funnel
                    $funnelLeft = $newLeft;
                    $leftIndex = $i;
                } else {
                    // Left crosses right - add right as new apex
                    $path[] = $funnelRight;
                    $apex = $funnelRight;
                    $apexIndex = $rightIndex;

                    // Reset funnel
                    $funnelLeft = $apex;
                    $leftIndex = $apexIndex;

                    // Restart scan from the apex
                    $i = $apexIndex;
                    continue;
                }
            }
        }

        // Add endpoint
        if (!$path[count($path) - 1]->equals($end)) {
            $path[] = $end;
        }

        return new NavMeshPath($path);
    }

    /**
     * Signed 2D triangle area (XZ plane) - used for left/right tests.
     *
     * Positive = counter-clockwise, negative = clockwise, zero = collinear.
     */
    private static function triArea2D(Vec3 $a, Vec3 $b, Vec3 $c): float
    {
        return ($b->x - $a->x) * ($c->z - $a->z) - ($b->z - $a->z) * ($c->x - $a->x);
    }
}
