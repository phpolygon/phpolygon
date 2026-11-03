<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Animation;

use PHPUnit\Framework\TestCase;
use PHPolygon\Animation\Pose;
use PHPolygon\Animation\PoseClip;
use PHPolygon\Animation\PoseRig;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

final class PoseClipTest extends TestCase
{
    private static function angleX(Quaternion $q): float
    {
        // Rotation about X only: angle from the rotated +Y axis.
        $v = $q->rotateVec3(new Vec3(0.0, 1.0, 0.0));
        return atan2($v->z, $v->y);
    }

    public function testRigSolvesAChainInRootSpace(): void
    {
        $rig = (new PoseRig())
            ->joint('hip', null, new Vec3(0.1, 1.0, 0.0))
            ->joint('knee', 'hip', new Vec3(0.0, -0.5, 0.0))
            ->joint('ankle', 'knee', new Vec3(0.0, -0.45, 0.0));

        $joints = $rig->solve(Pose::degrees(['hip' => [90.0], 'knee' => [-90.0]], new Vec3(0.0, -0.1, 0.0)));

        $this->assertTrue($joints['hip'][0]->equals(new Vec3(0.1, 0.9, 0.0), 1e-9));
        $this->assertTrue($joints['knee'][0]->equals(new Vec3(0.1, 0.9, -0.5), 1e-9));
        // The knee undoes the hip: the shin hangs straight down again.
        $this->assertTrue($joints['ankle'][0]->equals(new Vec3(0.1, 0.45, -0.5), 1e-9));
        $this->assertTrue($joints['ankle'][1]->equals(Quaternion::identity(), 1e-9) || $joints['ankle'][1]->equals(new Quaternion(0.0, 0.0, 0.0, -1.0), 1e-9));
    }

    public function testRestOrientationIsUnderThePose(): void
    {
        $rest = Quaternion::fromAxisAngle(new Vec3(0.0, 0.0, 1.0), 0.2);
        $rig = (new PoseRig())->joint('shoulder', null, new Vec3(0.2, 1.4, 0.0), $rest);
        $this->assertTrue($rig->solve(new Pose())['shoulder'][1]->equals($rest, 1e-12));

        $swing = Quaternion::fromAxisAngle(new Vec3(1.0, 0.0, 0.0), 0.5);
        $this->assertTrue($rig->solve(new Pose(['shoulder' => $swing]))['shoulder'][1]->equals($rest->multiply($swing), 1e-12));
    }

    public function testRigRejectsUnknownParentsAndDuplicates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PoseRig())->joint('knee', 'hip', new Vec3(0.0, -0.5, 0.0));
    }

    public function testKeyframesInterpolateLinearOrEased(): void
    {
        $keys = [[0.0, Pose::degrees(['a' => [0.0]])], [1.0, Pose::degrees(['a' => [40.0]])]];
        $linear = PoseClip::keyframes($keys, loop: false, smooth: false);
        $eased = PoseClip::keyframes($keys, loop: false);

        $this->assertEqualsWithDelta(deg2rad(10.0), self::angleX($linear->sample(0.25)->rotation('a')), 1e-9);
        $this->assertEqualsWithDelta(deg2rad(40.0 * 0.15625), self::angleX($eased->sample(0.25)->rotation('a')), 1e-9);
        $this->assertEqualsWithDelta(deg2rad(20.0), self::angleX($eased->sample(0.5)->rotation('a')), 1e-9);
        // Clamped past the end, a joint no key names stays at rest.
        $this->assertEqualsWithDelta(deg2rad(40.0), self::angleX($eased->sample(3.0)->rotation('a')), 1e-9);
        $this->assertTrue($eased->sample(0.5)->rotation('b')->equals(Quaternion::identity()));
    }

    public function testLoopingClipWraps(): void
    {
        $clip = PoseClip::keyframes([
            [0.0, Pose::degrees(['a' => [0.0]])],
            [0.5, Pose::degrees(['a' => [30.0]])],
            [1.0, Pose::degrees(['a' => [0.0]])],
        ], smooth: false);

        $this->assertTrue($clip->loop);
        $this->assertSame(1.0, $clip->duration);
        $this->assertEqualsWithDelta(self::angleX($clip->sample(0.25)->rotation('a')), self::angleX($clip->sample(2.25)->rotation('a')), 1e-12);
        $this->assertEqualsWithDelta(deg2rad(15.0), self::angleX($clip->sample(-0.75)->rotation('a')), 1e-9);
    }

    public function testProceduralClipGetsTheFoldedTime(): void
    {
        $seen = [];
        $clip = PoseClip::procedural(2.0, true, function (float $t) use (&$seen): Pose {
            $seen[] = $t;
            return new Pose(['a' => Quaternion::fromAxisAngle(new Vec3(1.0, 0.0, 0.0), sin($t * M_PI))]);
        });
        $clip->sample(5.5);
        $this->assertEqualsWithDelta(1.5, $seen[0], 1e-12);
    }

    public function testBlendSlerpsJointsAndLerpsTheRoot(): void
    {
        $a = new Pose(['x' => Quaternion::fromAxisAngle(new Vec3(1.0, 0.0, 0.0), 1.0)], new Vec3(0.0, 0.0, 0.0));
        $b = new Pose(['y' => Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), 1.0)], new Vec3(0.0, 1.0, 0.0));
        $mid = Pose::blend($a, $b, 0.5);

        $this->assertEqualsWithDelta(0.5, self::angleX($mid->rotation('x')), 1e-9);
        $this->assertTrue($mid->rotation('y')->equals(Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), 0.5), 1e-9));
        $this->assertNotNull($mid->rootOffset);
        $this->assertEqualsWithDelta(0.5, $mid->rootOffset->y, 1e-12);
        $this->assertSame($a, Pose::blend($a, $b, 0.0));
    }
}
