<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Math\Vec4;
use PHPolygon\Rendering\Reflection\PlanarReflection;

final class PlanarReflectionTest extends TestCase
{
    protected function tearDown(): void
    {
        PlanarReflection::clear();
    }

    public function testTheMirroredViewSeesAPointWhereTheRealViewSeesItsMirrorImage(): void
    {
        $view = Mat4::lookAt(new Vec3(3.0, 10.0, 20.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0));
        $plane = -0.4;
        $mirrored = PlanarReflection::mirroredView($view, $plane);
        $p = new Vec3(2.0, 5.0, -4.0);
        $image = new Vec3(2.0, 2.0 * $plane - 5.0, -4.0);

        $a = $mirrored->transformPoint($p);
        $b = $view->transformPoint($image);
        self::assertEqualsWithDelta($b->x, $a->x, 1e-9);
        self::assertEqualsWithDelta($b->y, $a->y, 1e-9);
        self::assertEqualsWithDelta($b->z, $a->z, 1e-9);
        self::assertEqualsWithDelta(2.0 * $plane - 10.0, PlanarReflection::mirroredEye(new Vec3(3.0, 10.0, 20.0), $plane)->y, 1e-9);
    }

    public function testTheObliqueNearPlaneCutsAtTheMirror(): void
    {
        $eye = new Vec3(0.0, 8.0, 30.0);
        $view = Mat4::lookAt($eye, new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0));
        $projection = Mat4::perspective(deg2rad(60.0), 16.0 / 9.0, 0.1, 500.0);
        $plane = 0.0;
        $mirrored = PlanarReflection::mirroredView($view, $plane);
        $oblique = PlanarReflection::obliqueProjection($projection, $mirrored, $plane);
        $clipVp = $oblique->multiply($mirrored);

        // Above the mirror and in view: inside the depth range.
        $above = $clipVp->multiplyVec4(new Vec4(0.0, 3.0, 0.0, 1.0));
        self::assertGreaterThan(-$above->w, $above->z, 'a point above the mirror stays');
        self::assertLessThan($above->w, $above->z);
        // Below the mirror: in front of the new near plane.
        $below = $clipVp->multiplyVec4(new Vec4(0.0, -3.0, 0.0, 1.0));
        self::assertLessThan(-$below->w, $below->z, 'a point below the mirror is clipped');
        // On the mirror: on the near plane.
        $on = $clipVp->multiplyVec4(new Vec4(4.0, 0.0, -2.0, 1.0));
        self::assertEqualsWithDelta(-$on->w, $on->z, 1e-6 * max(1.0, abs($on->w)));
        // x, y and w of the projection stay as they were.
        $plain = $projection->multiply($mirrored)->multiplyVec4(new Vec4(0.0, 3.0, 0.0, 1.0));
        self::assertEqualsWithDelta($plain->x, $above->x, 1e-9);
        self::assertEqualsWithDelta($plain->y, $above->y, 1e-9);
        self::assertEqualsWithDelta($plain->w, $above->w, 1e-9);
    }

    public function testEnableClampsTheScaleAndActiveNeedsTheRegistration(): void
    {
        PlanarReflection::enable(-0.4, 3.0);
        self::assertSame(1.0, PlanarReflection::scale());
        self::assertFalse(PlanarReflection::active(), 'not registered yet');
        PlanarReflection::enable(-0.4, 0.1);
        self::assertSame(0.25, PlanarReflection::scale());
        PlanarReflection::disable();
        self::assertNull(PlanarReflection::plane());
    }
}
