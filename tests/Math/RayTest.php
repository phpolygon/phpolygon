<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Math;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Ray;
use PHPolygon\Math\Vec3;

class RayTest extends TestCase
{
    public function testDirectionIsNormalized(): void
    {
        $ray = new Ray(Vec3::zero(), new Vec3(3.0, 0.0, 4.0));
        $this->assertEqualsWithDelta(1.0, $ray->direction->length(), 1e-6);
    }

    public function testPointAt(): void
    {
        $ray = new Ray(new Vec3(1.0, 2.0, 3.0), new Vec3(1.0, 0.0, 0.0));
        $point = $ray->pointAt(5.0);
        $this->assertEqualsWithDelta(6.0, $point->x, 1e-6);
        $this->assertEqualsWithDelta(2.0, $point->y, 1e-6);
        $this->assertEqualsWithDelta(3.0, $point->z, 1e-6);
    }

    public function testIntersectsAABBHit(): void
    {
        $ray = new Ray(new Vec3(-5.0, 0.5, 0.5), new Vec3(1.0, 0.0, 0.0));
        $t = $ray->intersectsAABB(Vec3::zero(), new Vec3(1.0, 1.0, 1.0));
        $this->assertNotNull($t);
        $this->assertEqualsWithDelta(5.0, $t, 1e-4);
    }

    public function testIntersectsAABBMiss(): void
    {
        $ray = new Ray(new Vec3(-5.0, 5.0, 5.0), new Vec3(1.0, 0.0, 0.0));
        $t = $ray->intersectsAABB(Vec3::zero(), new Vec3(1.0, 1.0, 1.0));
        $this->assertNull($t);
    }

    public function testIntersectsAABBFromInside(): void
    {
        $ray = new Ray(new Vec3(0.5, 0.5, 0.5), new Vec3(1.0, 0.0, 0.0));
        $t = $ray->intersectsAABB(Vec3::zero(), new Vec3(1.0, 1.0, 1.0));
        $this->assertNotNull($t);
        $this->assertEqualsWithDelta(0.5, $t, 1e-4);
    }

    public function testIntersectsAABBBehind(): void
    {
        // Ray pointing away from the box
        $ray = new Ray(new Vec3(5.0, 0.5, 0.5), new Vec3(1.0, 0.0, 0.0));
        $t = $ray->intersectsAABB(Vec3::zero(), new Vec3(1.0, 1.0, 1.0));
        $this->assertNull($t);
    }

    public function testIntersectsPlane(): void
    {
        $ray = new Ray(new Vec3(0.0, 10.0, 0.0), new Vec3(0.0, -1.0, 0.0));
        $t = $ray->intersectsPlane(Vec3::zero(), new Vec3(0.0, 1.0, 0.0));
        $this->assertNotNull($t);
        $this->assertEqualsWithDelta(10.0, $t, 1e-6);
    }

    public function testIntersectsPlaneParallel(): void
    {
        $ray = new Ray(new Vec3(0.0, 10.0, 0.0), new Vec3(1.0, 0.0, 0.0));
        $t = $ray->intersectsPlane(Vec3::zero(), new Vec3(0.0, 1.0, 0.0));
        $this->assertNull($t);
    }

    public function testIntersectsPlaneBehind(): void
    {
        // Plane behind the ray
        $ray = new Ray(new Vec3(0.0, 10.0, 0.0), new Vec3(0.0, 1.0, 0.0));
        $t = $ray->intersectsPlane(Vec3::zero(), new Vec3(0.0, 1.0, 0.0));
        $this->assertNull($t);
    }

    public function testIntersectsGroundPlane(): void
    {
        $ray = new Ray(new Vec3(5.0, 20.0, 3.0), new Vec3(0.0, -1.0, 0.0));
        $point = $ray->intersectsGroundPlane(0.0);
        $this->assertNotNull($point);
        $this->assertEqualsWithDelta(5.0, $point->x, 1e-6);
        $this->assertEqualsWithDelta(0.0, $point->y, 1e-6);
        $this->assertEqualsWithDelta(3.0, $point->z, 1e-6);
    }

    public function testIntersectsGroundPlaneElevated(): void
    {
        $ray = new Ray(new Vec3(0.0, 10.0, 0.0), new Vec3(0.0, -1.0, 0.0));
        $point = $ray->intersectsGroundPlane(5.0);
        $this->assertNotNull($point);
        $this->assertEqualsWithDelta(5.0, $point->y, 1e-6);
    }

    public function testIntersectsSphereHit(): void
    {
        $ray = new Ray(new Vec3(-5.0, 0.0, 0.0), new Vec3(1.0, 0.0, 0.0));
        $t = $ray->intersectsSphere(Vec3::zero(), 1.0);
        $this->assertNotNull($t);
        $this->assertEqualsWithDelta(4.0, $t, 1e-4);
    }

    public function testIntersectsSphereMiss(): void
    {
        $ray = new Ray(new Vec3(-5.0, 5.0, 0.0), new Vec3(1.0, 0.0, 0.0));
        $t = $ray->intersectsSphere(Vec3::zero(), 1.0);
        $this->assertNull($t);
    }

    public function testIntersectsSphereFromInside(): void
    {
        $ray = new Ray(Vec3::zero(), new Vec3(1.0, 0.0, 0.0));
        $t = $ray->intersectsSphere(Vec3::zero(), 2.0);
        $this->assertNotNull($t);
        $this->assertEqualsWithDelta(2.0, $t, 1e-4);
    }

    public function testZeroDirectionDefaultsToNegZ(): void
    {
        $ray = new Ray(Vec3::zero(), Vec3::zero());
        $this->assertEqualsWithDelta(-1.0, $ray->direction->z, 1e-6);
    }
}
