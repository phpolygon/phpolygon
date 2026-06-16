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
}
