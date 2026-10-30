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

        $distA = $segA->sub($tri->v0)->dot($normal);
        $distB = $segB->sub($tri->v0)->dot($normal);

        // Both ends out of reach on one side: nothing of the segment is in reach.
        if (($distA >= $radius && $distB >= $radius) || ($distA <= -$radius && $distB <= -$radius)) {
            return null;
        }

        [$closestOnSeg, $closestOnTri] = self::closestSegmentTriangle($segA, $segB, $distA, $distB, $tri);

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
     * The pair of points that is the true distance between a segment and a
     * triangle: [point on the segment, point on the triangle]. It lies at a segment
     * end or between the segment and a triangle edge, unless the segment pierces
     * the face. The answer must not hang on one reference point: a triangle of a
     * quad would then push towards its diagonal, as if the wall were a ramp.
     *
     * The common cases leave early: the nearer end lies over the face, so nothing
     * can be closer than its distance to the plane.
     *
     * @param float $distA signed distance of $segA to the triangle's plane
     * @param float $distB signed distance of $segB to the triangle's plane
     * @return array{Vec3, Vec3}
     */
    private static function closestSegmentTriangle(Vec3 $segA, Vec3 $segB, float $distA, float $distB, Triangle $tri): array
    {
        if ($distA * $distB < 0.0) {
            $crossing = $segA->add($segB->sub($segA)->mul($distA / ($distA - $distB)));
            $onTri = self::closestPointOnTriangle($tri->v0, $tri->v1, $tri->v2, $crossing);
            $diff = $crossing->sub($onTri);
            if ($diff->dot($diff) < 1e-16) {
                return [$crossing, $onTri];
            }
        } else {
            $near = abs($distA) < abs($distB) ? $segA : $segB;
            $nearDist = min(abs($distA), abs($distB));
            $onTri = self::closestPointOnTriangle($tri->v0, $tri->v1, $tri->v2, $near);
            $diff = $near->sub($onTri);
            if ($diff->dot($diff) <= $nearDist * $nearDist + 1e-12) {
                return [$near, $onTri];
            }
        }

        $best = [$segA, self::closestPointOnTriangle($tri->v0, $tri->v1, $tri->v2, $segA)];
        $bestSq = self::distanceSq($best[0], $best[1]);

        $onTri = self::closestPointOnTriangle($tri->v0, $tri->v1, $tri->v2, $segB);
        $distSq = self::distanceSq($segB, $onTri);
        if ($distSq < $bestSq) {
            $best = [$segB, $onTri];
            $bestSq = $distSq;
        }

        foreach ([[$tri->v0, $tri->v1], [$tri->v1, $tri->v2], [$tri->v2, $tri->v0]] as [$edgeA, $edgeB]) {
            $pair = self::closestBetweenSegments($segA, $segB, $edgeA, $edgeB);
            $distSq = self::distanceSq($pair[0], $pair[1]);
            if ($distSq < $bestSq) {
                $best = $pair;
                $bestSq = $distSq;
            }
        }

        return $best;
    }

    /**
     * Closest points of segments p1→q1 and p2→q2. Parallel segments have many such
     * pairs; this returns one.
     *
     * @return array{Vec3, Vec3}
     */
    private static function closestBetweenSegments(Vec3 $p1, Vec3 $q1, Vec3 $p2, Vec3 $q2): array
    {
        $d1 = $q1->sub($p1);
        $d2 = $q2->sub($p2);
        $r = $p1->sub($p2);
        $a = $d1->dot($d1);
        $e = $d2->dot($d2);
        $f = $d2->dot($r);

        if ($a < 1e-12 && $e < 1e-12) {
            return [$p1, $p2];
        }
        if ($a < 1e-12) {
            return [$p1, $p2->add($d2->mul(max(0.0, min(1.0, $f / $e))))];
        }

        $c = $d1->dot($r);
        if ($e < 1e-12) {
            return [$p1->add($d1->mul(max(0.0, min(1.0, -$c / $a)))), $p2];
        }

        $b = $d1->dot($d2);
        $denom = $a * $e - $b * $b;
        $s = $denom > 1e-12 ? max(0.0, min(1.0, ($b * $f - $c * $e) / $denom)) : 0.0;
        $t = ($b * $s + $f) / $e;
        if ($t < 0.0) {
            $t = 0.0;
            $s = max(0.0, min(1.0, -$c / $a));
        } elseif ($t > 1.0) {
            $t = 1.0;
            $s = max(0.0, min(1.0, ($b - $c) / $a));
        }

        return [$p1->add($d1->mul($s)), $p2->add($d2->mul($t))];
    }

    private static function distanceSq(Vec3 $a, Vec3 $b): float
    {
        $diff = $a->sub($b);

        return $diff->dot($diff);
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
