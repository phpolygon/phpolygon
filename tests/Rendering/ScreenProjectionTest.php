<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\ScreenProjection;

final class ScreenProjectionTest extends TestCase
{
    private static function viewProjection(): Mat4
    {
        $view = Mat4::lookAt(new Vec3(0.0, 0.0, 5.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0));

        return Mat4::perspective(deg2rad(90.0), 2.0, 0.1, 100.0)->multiply($view);
    }

    public function testCentreAndAxes(): void
    {
        $vp = self::viewProjection();
        $centre = ScreenProjection::worldToScreen(new Vec3(0.0, 0.0, 0.0), $vp, 800.0, 400.0);
        self::assertNotNull($centre);
        self::assertEqualsWithDelta(400.0, $centre->x, 0.001);
        self::assertEqualsWithDelta(200.0, $centre->y, 0.001);

        // World up is screen up: y falls.
        $up = ScreenProjection::worldToScreen(new Vec3(1.0, 1.0, 0.0), $vp, 800.0, 400.0);
        self::assertNotNull($up);
        self::assertGreaterThan(400.0, $up->x);
        self::assertLessThan(200.0, $up->y);
    }

    public function testBehindTheCameraIsNull(): void
    {
        self::assertNull(ScreenProjection::worldToScreen(new Vec3(0.0, 0.0, 9.0), self::viewProjection(), 800.0, 400.0));
    }

    public function testMarginKeepsPointsJustOffScreen(): void
    {
        $vp = self::viewProjection();
        $outside = new Vec3(11.0, 0.0, 0.0); // ndc x = 1.1
        self::assertNull(ScreenProjection::worldToScreen($outside, $vp, 800.0, 400.0));
        self::assertNotNull(ScreenProjection::worldToScreen($outside, $vp, 800.0, 400.0, 0.2));
    }

    public function testCameraPosition(): void
    {
        $eye = new Vec3(3.0, -2.0, 7.0);
        $view = Mat4::lookAt($eye, new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0));
        self::assertTrue($eye->equals(ScreenProjection::cameraPosition($view), 1e-4));
    }
}
