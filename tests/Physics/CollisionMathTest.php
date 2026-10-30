<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Physics;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Vec3;
use PHPolygon\Physics\CollisionMath;
use PHPolygon\Physics\Triangle;

class CollisionMathTest extends TestCase
{
    public function testClosestPointOnSegmentMidpoint(): void
    {
        $a = new Vec3(0, 0, 0);
        $b = new Vec3(10, 0, 0);
        // Point above the middle of the segment projects onto the midpoint.
        $closest = CollisionMath::closestPointOnSegment($a, $b, new Vec3(5, 5, 0));
        $this->assertTrue($closest->equals(new Vec3(5, 0, 0)), (string)$closest);
    }

    public function testClosestPointOnSegmentClampsToEndpointA(): void
    {
        $a = new Vec3(0, 0, 0);
        $b = new Vec3(10, 0, 0);
        // Point behind A clamps to A.
        $closest = CollisionMath::closestPointOnSegment($a, $b, new Vec3(-5, 3, 0));
        $this->assertTrue($closest->equals($a), (string)$closest);
    }

    public function testClosestPointOnSegmentClampsToEndpointB(): void
    {
        $a = new Vec3(0, 0, 0);
        $b = new Vec3(10, 0, 0);
        // Point beyond B clamps to B.
        $closest = CollisionMath::closestPointOnSegment($a, $b, new Vec3(20, -2, 0));
        $this->assertTrue($closest->equals($b), (string)$closest);
    }

    public function testClosestPointOnSegmentDegenerateReturnsA(): void
    {
        // Zero-length segment returns the start point.
        $a = new Vec3(2, 2, 2);
        $closest = CollisionMath::closestPointOnSegment($a, $a, new Vec3(9, 9, 9));
        $this->assertTrue($closest->equals($a), (string)$closest);
    }

    public function testClosestPointOnTriangleInsideFace(): void
    {
        $v0 = new Vec3(0, 0, 0);
        $v1 = new Vec3(4, 0, 0);
        $v2 = new Vec3(0, 4, 0);
        // Point above the interior projects straight down onto the face.
        $p = new Vec3(1, 1, 5);
        $closest = CollisionMath::closestPointOnTriangle($v0, $v1, $v2, $p);
        $this->assertTrue($closest->equals(new Vec3(1, 1, 0)), (string)$closest);
    }

    public function testClosestPointOnTriangleVertexRegion(): void
    {
        $v0 = new Vec3(0, 0, 0);
        $v1 = new Vec3(4, 0, 0);
        $v2 = new Vec3(0, 4, 0);
        // Point in the Voronoi region of vertex A.
        $closest = CollisionMath::closestPointOnTriangle($v0, $v1, $v2, new Vec3(-3, -3, 1));
        $this->assertTrue($closest->equals($v0), (string)$closest);
    }

    public function testClosestPointOnTriangleEdgeRegion(): void
    {
        $v0 = new Vec3(0, 0, 0);
        $v1 = new Vec3(4, 0, 0);
        $v2 = new Vec3(0, 4, 0);
        // Point outside edge AB projects onto the AB edge.
        $closest = CollisionMath::closestPointOnTriangle($v0, $v1, $v2, new Vec3(2, -3, 0));
        $this->assertTrue($closest->equals(new Vec3(2, 0, 0)), (string)$closest);
    }

    public function testCapsuleVsTriangleHit(): void
    {
        // Triangle in the z=0 plane, normal = +z.
        $tri = new Triangle(
            new Vec3(-5, -5, 0),
            new Vec3(5, -5, 0),
            new Vec3(0, 5, 0),
        );
        // Vertical capsule segment passing close above the triangle centre.
        $segA = new Vec3(0, 0, 0.5);
        $segB = new Vec3(0, 0, 2.0);
        $radius = 1.0;
        $resolution = CollisionMath::capsuleVsTriangle($segA, $segB, $radius, $tri);
        $this->assertNotNull($resolution);
        // Closest point on segment is at z=0.5, depth = radius - 0.5 = 0.5, push along +z.
        $this->assertTrue($resolution->equals(new Vec3(0, 0, 0.5)), (string)$resolution);
    }

    public function testCapsuleVsTriangleMiss(): void
    {
        $tri = new Triangle(
            new Vec3(-5, -5, 0),
            new Vec3(5, -5, 0),
            new Vec3(0, 5, 0),
        );
        // Capsule far above the plane, beyond its radius.
        $segA = new Vec3(0, 0, 10);
        $segB = new Vec3(0, 0, 12);
        $this->assertNull(CollisionMath::capsuleVsTriangle($segA, $segB, 1.0, $tri));
    }

    public function testCapsuleVsTriangleDegenerateReturnsNull(): void
    {
        // Collinear vertices => degenerate triangle => no collision.
        $tri = new Triangle(
            new Vec3(0, 0, 0),
            new Vec3(1, 0, 0),
            new Vec3(2, 0, 0),
        );
        $this->assertNull(CollisionMath::capsuleVsTriangle(
            new Vec3(0, 0, 0),
            new Vec3(0, 0, 1),
            5.0,
            $tri,
        ));
    }

    /** Both triangles of a wall quad in the plane x = 0, y 0..3, z -2..2. @return array{Triangle, Triangle} */
    private static function wallQuad(): array
    {
        $a = new Vec3(0, 0, -2);
        $b = new Vec3(0, 0, 2);
        $c = new Vec3(0, 3, 2);
        $d = new Vec3(0, 3, -2);

        return [new Triangle($a, $b, $c), new Triangle($a, $c, $d)];
    }

    /**
     * A vertical capsule beside a flat wall has one depth, wherever the diagonal of
     * the quad runs: the deeper of the two triangles pushes it level. Measured from
     * one reference point, the diagonal acted as a ramp.
     */
    public function testTheDeeperTriangleOfAWallQuadPushesLevel(): void
    {
        [$first, $second] = self::wallQuad();
        for ($z = -1.8; $z <= 1.8; $z += 0.1) {
            for ($y = 0.0; $y <= 1.0; $y += 0.1) {
                $low = new Vec3(-0.25, $y + 0.3, $z);
                $high = new Vec3(-0.25, $y + 1.5, $z);
                $a = CollisionMath::capsuleVsTriangle($low, $high, 0.3, $first);
                $b = CollisionMath::capsuleVsTriangle($low, $high, 0.3, $second);
                $deeper = ($a?->length() ?? 0.0) >= ($b?->length() ?? 0.0) ? $a : $b;

                $this->assertNotNull($deeper);
                $at = "y {$y}, z {$z}";
                $this->assertEqualsWithDelta(-0.05, $deeper->x, 1e-9, $at);
                $this->assertEqualsWithDelta(0.0, $deeper->y, 1e-9, $at);
                $this->assertEqualsWithDelta(0.0, $deeper->z, 1e-9, $at);
            }
        }
    }

    public function testASegmentReachingATriangleBetweenItsEndsIsTheCollision(): void
    {
        // A small triangle at mid-height of a long capsule, both ends far from it.
        $tri = new Triangle(new Vec3(0, 4.5, -0.5), new Vec3(0, 5.5, -0.5), new Vec3(0, 5, 0.5));

        $resolution = CollisionMath::capsuleVsTriangle(new Vec3(-0.2, 0, 0), new Vec3(-0.2, 10, 0), 0.3, $tri);

        $this->assertNotNull($resolution);
        $this->assertEqualsWithDelta(-0.1, $resolution->x, 1e-9);
        $this->assertEqualsWithDelta(0.0, $resolution->y, 1e-9);
    }

    public function testASegmentPassingAnEdgeIsMeasuredToTheEdge(): void
    {
        // Horizontal capsule above the top edge of a wall, crossing it.
        [, $tri] = self::wallQuad();

        $resolution = CollisionMath::capsuleVsTriangle(new Vec3(-1, 3.2, 0), new Vec3(1, 3.2, 0), 0.3, $tri);

        $this->assertNotNull($resolution);
        $this->assertEqualsWithDelta(0.0, $resolution->x, 1e-9);
        $this->assertEqualsWithDelta(0.1, $resolution->y, 1e-9);
    }

    public function testACapsuleBeyondAnEdgeIsNoCollision(): void
    {
        [, $tri] = self::wallQuad();

        $this->assertNull(CollisionMath::capsuleVsTriangle(new Vec3(-0.25, 3.4, 0), new Vec3(-0.25, 4.5, 0), 0.3, $tri));
    }

    public function testASegmentPiercingATriangleIsPushedAlongItsNormal(): void
    {
        $tri = new Triangle(new Vec3(-5, -5, 0), new Vec3(5, -5, 0), new Vec3(0, 5, 0));

        $resolution = CollisionMath::capsuleVsTriangle(new Vec3(0, 0, -1), new Vec3(0, 0, 1), 0.3, $tri);

        $this->assertNotNull($resolution);
        $this->assertEqualsWithDelta(0.3, abs($resolution->z), 1e-9);
    }

    public function testSegmentIntersectsTriangle(): void
    {
        // Vertical wall in the plane x = 1.
        $tri = new Triangle(new Vec3(1, -5, -5), new Vec3(1, 5, -5), new Vec3(1, 0, 5));
        $origin = new Vec3(0, 0, 0);

        $this->assertTrue(CollisionMath::segmentIntersectsTriangle($origin, new Vec3(2, 0, 0), $tri));
        $this->assertTrue(CollisionMath::segmentIntersectsTriangle(new Vec3(2, 0, 0), $origin, $tri), 'back face');
        $this->assertTrue(CollisionMath::segmentIntersectsTriangle($origin, new Vec3(1, 0, 0), $tri), 'ends on the face');
        $this->assertFalse(CollisionMath::segmentIntersectsTriangle($origin, new Vec3(0.9, 0, 0), $tri), 'ends before');
        $this->assertFalse(CollisionMath::segmentIntersectsTriangle(new Vec3(1.1, 0, 0), new Vec3(3, 0, 0), $tri), 'starts behind');
        $this->assertFalse(CollisionMath::segmentIntersectsTriangle($origin, new Vec3(0, 0, 3), $tri), 'parallel');
        $this->assertFalse(CollisionMath::segmentIntersectsTriangle(new Vec3(0, 9, 0), new Vec3(2, 9, 0), $tri), 'beside');
    }

    public function testSegmentIntersectsAabb(): void
    {
        $min = new Vec3(1, 0, -1);
        $max = new Vec3(2, 3, 1);

        $this->assertTrue(CollisionMath::segmentIntersectsAabb(new Vec3(0, 1, 0), new Vec3(3, 1, 0), $min, $max));
        $this->assertTrue(CollisionMath::segmentIntersectsAabb(new Vec3(1.5, 1, 0), new Vec3(5, 1, 0), $min, $max), 'starts inside');
        $this->assertTrue(CollisionMath::segmentIntersectsAabb(new Vec3(1.5, 5, 0), new Vec3(1.5, -5, 0), $min, $max), 'axis-parallel through');
        $this->assertFalse(CollisionMath::segmentIntersectsAabb(new Vec3(0, 1, 0), new Vec3(0.8, 1, 0), $min, $max), 'ends before');
        $this->assertFalse(CollisionMath::segmentIntersectsAabb(new Vec3(0, 4, 0), new Vec3(3, 4, 0), $min, $max), 'passes above');
        $this->assertFalse(CollisionMath::segmentIntersectsAabb(new Vec3(0, 1, 5), new Vec3(5, 1, 0), $min, $max), 'diagonal miss');
    }
}
