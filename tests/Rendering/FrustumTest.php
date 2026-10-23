<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Frustum;
use PHPUnit\Framework\TestCase;

final class FrustumTest extends TestCase
{
    /** @return list<array{0: float, 1: float, 2: float, 3: float}> */
    private static function camera(): array
    {
        // At (0, 2, 0) looking down -Z, 60 degree vertical field of view.
        return Frustum::planes(
            Mat4::perspective(M_PI / 3, 16 / 9, 0.1, 100.0),
            Mat4::lookAt(new Vec3(0, 2, 0), new Vec3(0, 2, -10), new Vec3(0, 1, 0)),
        );
    }

    public function testPlanesAreNormalisedAndPointInward(): void
    {
        $planes = self::camera();
        self::assertCount(6, $planes);
        foreach ($planes as $p) {
            self::assertEqualsWithDelta(1.0, sqrt($p[0] ** 2 + $p[1] ** 2 + $p[2] ** 2), 1e-9);
            // A point straight ahead is inside every plane.
            self::assertGreaterThan(0.0, $p[0] * 0.0 + $p[1] * 2.0 + $p[2] * -10.0 + $p[3]);
        }
    }

    public function testSpheresOutsideAndInside(): void
    {
        $planes = self::camera();
        self::assertFalse(Frustum::sphereOutside($planes, 0.0, 2.0, -10.0, 0.5), 'ahead');
        self::assertTrue(Frustum::sphereOutside($planes, 0.0, 2.0, 10.0, 0.5), 'behind');
        self::assertFalse(Frustum::sphereOutside($planes, 0.0, 2.0, 10.0, 11.0), 'behind but reaching past the camera');
        self::assertTrue(Frustum::sphereOutside($planes, 0.0, 2.0, -150.0, 10.0), 'beyond the far plane');
        self::assertTrue(Frustum::sphereOutside($planes, 50.0, 2.0, -10.0, 1.0), 'far to the right');
        self::assertFalse(Frustum::sphereOutside($planes, 50.0, 2.0, -10.0, 60.0), 'large enough to reach in');
    }
}
