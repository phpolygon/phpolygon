<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Physics;

use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Physics\OrientedBox;
use PHPUnit\Framework\TestCase;

final class OrientedBoxTest extends TestCase
{
    private static function box(Vec3 $center, Vec3 $size, float $yaw = 0.0, float $roll = 0.0, ?Vec3 $scale = null): OrientedBox
    {
        $transform = new Transform3D(position: $center, rotation: Quaternion::fromEuler(0.0, $yaw, $roll), scale: $scale ?? new Vec3(1.0, 1.0, 1.0));
        $collider = new BoxCollider3D(size: $size);

        return OrientedBox::fromCollider($collider, $transform->getWorldMatrix());
    }

    public function testAnUnturnedBoxPushesLikeItsFaces(): void
    {
        $box = self::box(new Vec3(0, 0, 0), new Vec3(2, 2, 2));

        $push = $box->capsulePush(new Vec3(1.2, -0.5, 0), new Vec3(1.2, 0.5, 0), 0.3);

        $this->assertNotNull($push);
        $this->assertEqualsWithDelta(0.1, $push->x, 1e-9);
        $this->assertEqualsWithDelta(0.0, $push->y, 1e-9);
    }

    public function testACapsuleBeyondItsRadiusIsNotTouched(): void
    {
        $box = self::box(new Vec3(0, 0, 0), new Vec3(2, 2, 2), deg2rad(30.0));

        $this->assertNull($box->capsulePush(new Vec3(3, -1, 0), new Vec3(3, 1, 0), 0.3));
    }

    public function testACornerPushesAwayFromTheCorner(): void
    {
        $box = self::box(new Vec3(0, 0, 0), new Vec3(2, 2, 2));

        $push = $box->capsulePush(new Vec3(1.1, 1.1, 1.1), new Vec3(1.1, 1.1, 1.1), 0.3);

        $this->assertNotNull($push);
        $expected = 0.3 - sqrt(3.0) * 0.1;
        $this->assertEqualsWithDelta($expected, $push->length(), 1e-9);
        $this->assertEqualsWithDelta($push->x, $push->y, 1e-9);
    }

    public function testAFaceOfATurnedBoxPushesAlongItsNormal(): void
    {
        $yaw = deg2rad(30.0);
        $box = self::box(new Vec3(0, 0, 0), new Vec3(2, 2, 2), $yaw);
        // Local +X in world space, whatever the sign of the rotation.
        $n = (new Transform3D(rotation: Quaternion::fromEuler(0.0, $yaw, 0.0)))->rotation->rotateVec3(new Vec3(1, 0, 0));
        $at = $n->mul(1.2);

        $push = $box->capsulePush($at->add(new Vec3(0, -0.5, 0)), $at->add(new Vec3(0, 0.5, 0)), 0.3);

        $this->assertNotNull($push);
        $this->assertEqualsWithDelta(0.1 * $n->x, $push->x, 1e-9);
        $this->assertEqualsWithDelta(0.1 * $n->z, $push->z, 1e-9);
    }

    public function testASegmentInsideTakesTheShortestWayOut(): void
    {
        $box = self::box(new Vec3(0, 0, 0), new Vec3(10, 10, 10));

        $push = $box->capsulePush(new Vec3(4.5, -1, 0), new Vec3(4.5, 1, 0), 0.3);

        $this->assertNotNull($push);
        $this->assertEqualsWithDelta(0.8, $push->x, 1e-9);
        $this->assertEqualsWithDelta(0.0, $push->y, 1e-9);
    }

    public function testASlightlyTurnedThickBoxStillTakesTheShortestWayOut(): void
    {
        $box = self::box(new Vec3(3, 3, 0), new Vec3(6, 6, 30), deg2rad(2.0));

        $push = $box->capsulePush(new Vec3(0.3, 0.4, 0), new Vec3(0.3, 1.4, 0), 0.4);

        $this->assertNotNull($push);
        $this->assertLessThan(-0.5, $push->x);
        $this->assertLessThan(1.0, $push->length());
    }

    public function testASegmentCrossingAThinBoxIsPushedOutAlongTheThinAxis(): void
    {
        $box = self::box(new Vec3(0, 0, 0), new Vec3(10, 0.2, 10));

        $push = $box->capsulePush(new Vec3(0, -0.5, 0), new Vec3(0, 0.05, 0), 0.3);

        $this->assertNotNull($push);
        $this->assertEqualsWithDelta(0.0, $push->x, 1e-9);
        $this->assertEqualsWithDelta(-0.45, $push->y, 1e-9);
    }

    public function testScaleCountsAndOffsetIsHonoured(): void
    {
        $transform = new Transform3D(position: new Vec3(1, 0, 0), scale: new Vec3(2, 1, 1), rotation: Quaternion::fromEuler(0.0, 0.5, 0.0));
        $collider = new BoxCollider3D(size: new Vec3(1, 1, 1), offset: new Vec3(0, 3, 0));
        $box = OrientedBox::fromCollider($collider, $transform->getWorldMatrix());

        $this->assertEqualsWithDelta(3.0, $box->cy, 1e-9);
        $this->assertEqualsWithDelta(1.0, $box->hx, 1e-9);
        $this->assertEqualsWithDelta(0.5, $box->hy, 1e-9);
    }

    public function testTheTopSurfaceFollowsATilt(): void
    {
        $box = self::box(new Vec3(0, 0, 0), new Vec3(10, 1, 10), 0.0, deg2rad(10.0));

        $here = $box->surfaceAt(0.0, 0.0);
        $right = $box->surfaceAt(2.0, 0.0);

        $this->assertNotNull($here);
        $this->assertNotNull($right);
        $this->assertEqualsWithDelta(0.5 / cos(deg2rad(10.0)), $here, 1e-9);
        $this->assertEqualsWithDelta(abs(2.0 * tan(deg2rad(10.0))), abs($right - $here), 1e-9);
    }

    public function testNoSurfaceOutsideTheFootprint(): void
    {
        $box = self::box(new Vec3(0, 0, 0), new Vec3(2, 1, 2), deg2rad(10.0));

        $this->assertNull($box->surfaceAt(5.0, 0.0));
    }

    public function testTheTopNearAPointClampsToTheFootprint(): void
    {
        $box = self::box(new Vec3(0, 0, 0), new Vec3(4, 0.2, 4), 0.0, deg2rad(5.0));

        $near = $box->topNear(-2.3, 0.0);
        $edge = $box->surfaceAt(-1.99, 0.0);

        $this->assertNotNull($edge);
        $this->assertEqualsWithDelta($edge, $near, 0.02);
        $this->assertLessThan($box->max->y - 0.1, $near, 'the low end, not the top of the hull');
    }
}
