<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Physics;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Vec3;
use PHPolygon\Physics\Collision3D;

class Collision3DTest extends TestCase
{
    public function testStoresConstructorValues(): void
    {
        $normal = new Vec3(0, 1, 0);
        $contact = new Vec3(1, 2, 3);
        $collision = new Collision3D(7, 11, $normal, 0.25, $contact);

        $this->assertSame(7, $collision->entityA);
        $this->assertSame(11, $collision->entityB);
        $this->assertSame(0.25, $collision->penetration);
        $this->assertSame($normal, $collision->normal);
        $this->assertSame($contact, $collision->contactPoint);
    }

    public function testNormalPointsFromAToB(): void
    {
        $normal = new Vec3(1, 0, 0);
        $collision = new Collision3D(1, 2, $normal, 0.5, Vec3::zero());
        $this->assertTrue($collision->normal->equals(new Vec3(1, 0, 0)));
    }

    public function testIsReadonlyImmutable(): void
    {
        $collision = new Collision3D(1, 2, new Vec3(0, 1, 0), 1.0, Vec3::zero());
        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line intentionally mutating a readonly property */
        $collision->penetration = 2.0;
    }
}
