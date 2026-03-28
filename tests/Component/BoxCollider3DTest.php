<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Component;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Math\Vec3;

class BoxCollider3DTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $collider = new BoxCollider3D();
        $this->assertEqualsWithDelta(1.0, $collider->size->x, 0.001);
        $this->assertEqualsWithDelta(1.0, $collider->size->y, 0.001);
        $this->assertEqualsWithDelta(1.0, $collider->size->z, 0.001);
        $this->assertEqualsWithDelta(0.0, $collider->offset->x, 0.001);
        $this->assertFalse($collider->isTrigger);
        $this->assertTrue($collider->isStatic);
    }

    public function testWorldAABBCenteredOnPosition(): void
    {
        $collider = new BoxCollider3D(size: new Vec3(4.0, 2.0, 6.0));
        $aabb = $collider->getWorldAABB(new Vec3(10.0, 5.0, 0.0));

        $this->assertEqualsWithDelta(8.0, $aabb['min']->x, 0.001);
        $this->assertEqualsWithDelta(4.0, $aabb['min']->y, 0.001);
        $this->assertEqualsWithDelta(-3.0, $aabb['min']->z, 0.001);
        $this->assertEqualsWithDelta(12.0, $aabb['max']->x, 0.001);
        $this->assertEqualsWithDelta(6.0, $aabb['max']->y, 0.001);
        $this->assertEqualsWithDelta(3.0, $aabb['max']->z, 0.001);
    }

    public function testWorldAABBWithOffset(): void
    {
        $collider = new BoxCollider3D(
            size: new Vec3(2.0, 2.0, 2.0),
            offset: new Vec3(0.0, 1.0, 0.0),
        );
        $aabb = $collider->getWorldAABB(Vec3::zero());

        $this->assertEqualsWithDelta(-1.0, $aabb['min']->x, 0.001);
        $this->assertEqualsWithDelta(0.0, $aabb['min']->y, 0.001); // offset 1 - halfSize 1
        $this->assertEqualsWithDelta(1.0, $aabb['max']->x, 0.001);
        $this->assertEqualsWithDelta(2.0, $aabb['max']->y, 0.001); // offset 1 + halfSize 1
    }
}
