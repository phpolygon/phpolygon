<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Motion;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Motion\MotionView;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\RenderCommandList;

/**
 * MotionView's four documented behaviors, written before its Phase 3
 * refactor (storing a shared ViewVolume instead of a raw eye + planes array)
 * so the refactor cannot silently change any of them:
 *   - capture() with no SetCamera queued keeps the previous camera (does not
 *     unfreeze everything);
 *   - sees() with radius <= 0 is always true ("never freeze" sentinel);
 *   - maxDistance 0.0 (the default) means unlimited, not "within 0 m";
 *   - setEye() is a distance-only mode (no frustum test at all).
 */
final class MotionViewTest extends TestCase
{
    private static function camera(Vec3 $eye, Vec3 $look): SetCamera
    {
        return new SetCamera(
            Mat4::lookAt($eye, $look, new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 500.0),
        );
    }

    public function testFreshInstanceSeesEverything(): void
    {
        $view = new MotionView();
        self::assertNull($view->eye());
        self::assertTrue($view->sees(new Vec3(0.0, 0.0, -1000.0), 1.0));
    }

    public function testSeesTestsTheFrustumAfterCapture(): void
    {
        $view = new MotionView();
        $view->set(
            Mat4::lookAt(new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 500.0),
        );

        self::assertTrue($view->sees(new Vec3(0.0, 0.0, -10.0), 1.0));
        self::assertFalse($view->sees(new Vec3(0.0, 0.0, 10.0), 1.0), 'behind the camera');
    }

    public function testCaptureWithoutASetCameraKeepsTheOldOneInstead(): void
    {
        $view = new MotionView();
        $view->capture(self::commandsWith(self::camera(new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0))));
        self::assertTrue($view->sees(new Vec3(0.0, 0.0, -10.0), 1.0));
        self::assertFalse($view->sees(new Vec3(0.0, 0.0, 10.0), 1.0));

        // A render with no camera queued at all must not unfreeze everything.
        $view->capture(new RenderCommandList());
        self::assertTrue($view->sees(new Vec3(0.0, 0.0, -10.0), 1.0), 'still frozen at the last known camera');
        self::assertFalse($view->sees(new Vec3(0.0, 0.0, 10.0), 1.0), 'still frozen - not unrestricted');
    }

    public function testRadiusZeroOrBelowNeverFreezes(): void
    {
        $view = new MotionView();
        $view->set(
            Mat4::lookAt(new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 500.0),
        );
        self::assertTrue($view->sees(new Vec3(0.0, 0.0, 10.0), 0.0), 'radius 0 - the "never freeze" sentinel');
        self::assertTrue($view->sees(new Vec3(0.0, 0.0, 10.0), -5.0));
    }

    public function testMaxDistanceZeroMeansUnlimitedNotWithinZeroMetres(): void
    {
        $view = new MotionView();
        $view->set(
            Mat4::lookAt(new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 500.0),
        );
        // In the frustum, far from the eye - the default maxDistance (0.0)
        // must not be read as "within 0 m" (which would always fail).
        self::assertTrue($view->sees(new Vec3(0.0, 0.0, -400.0), 1.0));
        self::assertTrue($view->sees(new Vec3(0.0, 0.0, -400.0), 1.0, maxDistance: 0.0));
        self::assertFalse($view->sees(new Vec3(0.0, 0.0, -400.0), 1.0, maxDistance: 50.0), 'an explicit positive limit does apply');
    }

    public function testSetEyeIsDistanceOnlyNoFrustumTest(): void
    {
        $view = new MotionView();
        $view->setEye(new Vec3(0.0, 0.0, 0.0));

        // No frustum at all - "behind the eye" is just as seen as "in front",
        // only the distance (when given) matters.
        self::assertTrue($view->sees(new Vec3(0.0, 0.0, 10.0), 1.0));
        self::assertTrue($view->sees(new Vec3(0.0, 0.0, -10.0), 1.0));
        self::assertFalse($view->sees(new Vec3(0.0, 0.0, 100.0), 1.0, maxDistance: 10.0));
    }

    public function testSetEyeNullAndClearBothUnfreezeEverything(): void
    {
        $view = new MotionView();
        $view->set(
            Mat4::lookAt(new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 500.0),
        );
        self::assertFalse($view->sees(new Vec3(0.0, 0.0, 10.0), 1.0));

        $view->clear();
        self::assertNull($view->eye());
        self::assertTrue($view->sees(new Vec3(0.0, 0.0, 10.0), 1.0));
    }

    private static function commandsWith(SetCamera $camera): RenderCommandList
    {
        $list = new RenderCommandList();
        $list->add($camera);
        return $list;
    }
}
