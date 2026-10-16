<?php

declare(strict_types=1);

namespace PHPolygon\Physics;

use PHPolygon\Math\Vec3;

/**
 * Static utility methods for triangle-based collision detection.
 */
class CollisionMath
{
    /**
     * Closest point on line segment AB to point P.
     */
    public static function closestPointOnSegment(Vec3 $a, Vec3 $b, Vec3 $p): Vec3
    {
        $ab = $b->sub($a);
        $lengthSq = $ab->dot($ab);

        if ($lengthSq < 1e-12) {
            return $a;
        }

        $t = $p->sub($a)->dot($ab) / $lengthSq;
        $t = max(0.0, min(1.0, $t));

        return $a->add($ab->mul($t));
    }

    /**
     * Closest point on triangle (v0,v1,v2) to point P.
     * Uses the Voronoi region method.
     */
    public static function closestPointOnTriangle(Vec3 $v0, Vec3 $v1, Vec3 $v2, Vec3 $p): Vec3
    {
        $ab = $v1->sub($v0);
        $ac = $v2->sub($v0);
        $ap = $p->sub($v0);

        $d1 = $ab->dot($ap);
        $d2 = $ac->dot($ap);
        if ($d1 <= 0.0 && $d2 <= 0.0) {
            return $v0; // Vertex region A
        }

        $bp = $p->sub($v1);
        $d3 = $ab->dot($bp);
        $d4 = $ac->dot($bp);
        if ($d3 >= 0.0 && $d4 <= $d3) {
            return $v1; // Vertex region B
        }

        $vc = $d1 * $d4 - $d3 * $d2;
        if ($vc <= 0.0 && $d1 >= 0.0 && $d3 <= 0.0) {
            $v = $d1 / ($d1 - $d3);
            return $v0->add($ab->mul($v)); // Edge AB
        }

        $cp = $p->sub($v2);
        $d5 = $ab->dot($cp);
        $d6 = $ac->dot($cp);
        if ($d6 >= 0.0 && $d5 <= $d6) {
            return $v2; // Vertex region C
        }

        $vb = $d5 * $d2 - $d1 * $d6;
        if ($vb <= 0.0 && $d2 >= 0.0 && $d6 <= 0.0) {
            $w = $d2 / ($d2 - $d6);
            return $v0->add($ac->mul($w)); // Edge AC
        }

        $va = $d3 * $d6 - $d5 * $d4;
        if ($va <= 0.0 && ($d4 - $d3) >= 0.0 && ($d5 - $d6) >= 0.0) {
            $w = ($d4 - $d3) / (($d4 - $d3) + ($d5 - $d6));
            return $v1->add($v2->sub($v1)->mul($w)); // Edge BC
        }

        // Inside face
        $denom = 1.0 / ($va + $vb + $vc);
        $v = $vb * $denom;
        $w = $vc * $denom;

        return $v0->add($ab->mul($v))->add($ac->mul($w));
    }

    /**
     * Test capsule (defined by segment segA->segB with radius) against a triangle.
     * Returns a resolution vector (push-out direction * distance) to resolve the collision,
     * or null if no collision.
     *
     * The capsule segment is the medial axis (the line from bottom-center + radius to top-center - radius).
     */
    public static function capsuleVsTriangle(Vec3 $segA, Vec3 $segB, float $radius, Triangle $tri): ?Vec3
    {
        if ($tri->isDegenerate()) {
            return null;
        }

        $normal = $tri->normal;

        // Find the point on the capsule segment closest to the triangle plane
        $distA = $segA->sub($tri->v0)->dot($normal);
        $distB = $segB->sub($tri->v0)->dot($normal);

        // Pick the capsule segment point closest to the triangle plane
        // If both are on the same side, pick the one closest to the plane
        if ($distA * $distB >= 0.0) {
            // Same side — pick the closer one
            $referencePoint = abs($distA) < abs($distB) ? $segA : $segB;
        } else {
            // Different sides — interpolate to the plane intersection
            $t = $distA / ($distA - $distB);
            $referencePoint = $segA->add($segB->sub($segA)->mul($t));
        }

        // Project reference point onto triangle plane
        $distRef = $referencePoint->sub($tri->v0)->dot($normal);
        $projected = $referencePoint->sub($normal->mul($distRef));

        // Find closest point on the triangle to the projected point
        $closestOnTri = self::closestPointOnTriangle($tri->v0, $tri->v1, $tri->v2, $projected);

        // Find closest point on capsule segment to the triangle's closest point
        $closestOnSeg = self::closestPointOnSegment($segA, $segB, $closestOnTri);

        // Vector from triangle closest point to capsule segment closest point
        $diff = $closestOnSeg->sub($closestOnTri);
        $distSq = $diff->dot($diff);
        $radiusSq = $radius * $radius;

        if ($distSq >= $radiusSq) {
            return null; // No collision
        }

        $dist = sqrt($distSq);

        if ($dist < 1e-8) {
            // Capsule center is exactly on the triangle — push along triangle normal
            return $normal->mul($radius);
        }

        // Resolution: push the capsule out along the penetration direction
        $penetrationDir = $diff->div($dist);
        $penetrationDepth = $radius - $dist;

        return $penetrationDir->mul($penetrationDepth);
    }

    /**
     * Does the segment from → to cross the triangle? Möller–Trumbore, double-sided
     * (winding does not matter), with the hit parameter clamped to [0, 1] so a
     * triangle beyond either end point does not count. A segment lying in the
     * triangle's plane is treated as a miss.
     */
    public static function segmentIntersectsTriangle(Vec3 $from, Vec3 $to, Triangle $tri): bool
    {
        $dx = $to->x - $from->x;
        $dy = $to->y - $from->y;
        $dz = $to->z - $from->z;

        $e1x = $tri->v1->x - $tri->v0->x;
        $e1y = $tri->v1->y - $tri->v0->y;
        $e1z = $tri->v1->z - $tri->v0->z;
        $e2x = $tri->v2->x - $tri->v0->x;
        $e2y = $tri->v2->y - $tri->v0->y;
        $e2z = $tri->v2->z - $tri->v0->z;

        // p = d × e2
        $px = $dy * $e2z - $dz * $e2y;
        $py = $dz * $e2x - $dx * $e2z;
        $pz = $dx * $e2y - $dy * $e2x;
        $det = $e1x * $px + $e1y * $py + $e1z * $pz;
        if (abs($det) < 1e-9) {
            return false; // parallel to the triangle's plane
        }
        $inv = 1.0 / $det;

        $sx = $from->x - $tri->v0->x;
        $sy = $from->y - $tri->v0->y;
        $sz = $from->z - $tri->v0->z;
        $u = ($sx * $px + $sy * $py + $sz * $pz) * $inv;
        if ($u < 0.0 || $u > 1.0) {
            return false;
        }

        // q = s × e1
        $qx = $sy * $e1z - $sz * $e1y;
        $qy = $sz * $e1x - $sx * $e1z;
        $qz = $sx * $e1y - $sy * $e1x;
        $v = ($dx * $qx + $dy * $qy + $dz * $qz) * $inv;
        if ($v < 0.0 || $u + $v > 1.0) {
            return false;
        }

        $t = ($e2x * $qx + $e2y * $qy + $e2z * $qz) * $inv;

        return $t >= 0.0 && $t <= 1.0;
    }

    /**
     * Does the segment from → to touch the axis-aligned box [min, max]? Slab test
     * over the segment parameter [0, 1]; a segment that starts or ends inside the
     * box counts as touching.
     */
    public static function segmentIntersectsAabb(Vec3 $from, Vec3 $to, Vec3 $min, Vec3 $max): bool
    {
        $t0 = 0.0;
        $t1 = 1.0;
        foreach ([
            [$from->x, $to->x - $from->x, $min->x, $max->x],
            [$from->y, $to->y - $from->y, $min->y, $max->y],
            [$from->z, $to->z - $from->z, $min->z, $max->z],
        ] as [$o, $d, $lo, $hi]) {
            if (abs($d) < 1e-9) {
                if ($o < $lo || $o > $hi) {
                    return false;
                }
                continue;
            }
            $a = ($lo - $o) / $d;
            $b = ($hi - $o) / $d;
            if ($a > $b) {
                [$a, $b] = [$b, $a];
            }
            $t0 = max($t0, $a);
            $t1 = min($t1, $b);
            if ($t0 > $t1) {
                return false;
            }
        }

        return true;
    }
}
