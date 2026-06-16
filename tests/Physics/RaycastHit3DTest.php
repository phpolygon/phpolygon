<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Physics;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Vec3;
use PHPolygon\Physics\RaycastHit3D;

class RaycastHit3DTest extends TestCase
{
    public function testStoresConstructorValues(): void
    {
        $point = new Vec3(1, 2, 3);
        $normal = new Vec3(0, 0, -1);
        $hit = new RaycastHit3D(42, $point, $normal, 5.5);

        $this->assertSame(42, $hit->entityId);
        $this->assertSame($point, $hit->point);
        $this->assertSame($normal, $hit->normal);
        $this->assertSame(5.5, $hit->distance);
    }

    public function testIsReadonlyImmutable(): void
    {
        $hit = new RaycastHit3D(1, Vec3::zero(), new Vec3(0, 1, 0), 1.0);
        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line intentionally mutating a readonly property */
        $hit->distance = 2.0;
    }
}
