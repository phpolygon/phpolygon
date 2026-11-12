<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Physics;

use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Physics\OrientedBox;
use PHPolygon\Physics\RampPose;
use PHPUnit\Framework\TestCase;

final class RampPoseTest extends TestCase
{
    public function testTopSurfaceRunsThroughBothEdges(): void
    {
        $low = new Vec3(2.0, 0.0, -1.0);
        $high = new Vec3(2.0 + 3.0, 0.4, -1.0 + 1.0);
        $pose = RampPose::fromEdges($low, $high, 1.5, 0.2);
        $world = Mat4::translation($pose['position']->x, $pose['position']->y, $pose['position']->z)
            ->multiply($pose['rotation']->toRotationMatrix());
        $box = OrientedBox::fromCollider(new BoxCollider3D($pose['size']), $world);

        self::assertEqualsWithDelta(1.5 / 2.0, $box->hx, 1e-9);
        self::assertEqualsWithDelta(0.1, $box->hy, 1e-9);
        self::assertEqualsWithDelta(sqrt(9.0 + 0.16 + 1.0) / 2.0, $box->hz, 1e-9);
        // Top-surface edge midpoints = centre + up * hy -/+ forward * hz.
        foreach ([[-1.0, $low], [1.0, $high]] as [$sign, $edge]) {
            self::assertEqualsWithDelta($edge->x, $box->cx + $box->axisY[0] * $box->hy + $sign * $box->axisZ[0] * $box->hz, 1e-9);
            self::assertEqualsWithDelta($edge->y, $box->cy + $box->axisY[1] * $box->hy + $sign * $box->axisZ[1] * $box->hz, 1e-9);
            self::assertEqualsWithDelta($edge->z, $box->cz + $box->axisY[2] * $box->hy + $sign * $box->axisZ[2] * $box->hz, 1e-9);
        }
        // The slab is not rolled: its width axis stays horizontal.
        self::assertEqualsWithDelta(0.0, $box->axisX[1], 1e-9);
        self::assertGreaterThan(0.9, $box->axisY[1]);
    }

    public function testLevelSlabHasIdentityPoseAndDegenerateInputThrows(): void
    {
        $pose = RampPose::fromEdges(new Vec3(0.0, 1.0, 0.0), new Vec3(0.0, 1.0, 2.0), 1.0, 0.5);
        self::assertEqualsWithDelta(0.75, $pose['position']->y, 1e-9);
        self::assertEqualsWithDelta(1.0, abs($pose['rotation']->w), 1e-9);

        $this->expectException(\InvalidArgumentException::class);
        RampPose::fromEdges(new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 2.0, 0.0), 1.0, 0.2);
    }
}
