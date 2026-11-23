<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Camera;

use InvalidArgumentException;
use LogicException;
use PHPolygon\Camera\CameraEasing;
use PHPolygon\Camera\CameraFlight;
use PHPolygon\Camera\CameraFlightPlayer;
use PHPolygon\Camera\CameraPose;
use PHPolygon\Math\Vec3;
use PHPUnit\Framework\TestCase;

class CameraFlightTest extends TestCase
{
    /** @return list<Vec3> */
    private function zigzag(): array
    {
        return [new Vec3(0, 5, 0), new Vec3(20, 8, -10), new Vec3(40, 6, 15), new Vec3(70, 12, 0), new Vec3(90, 9, -30)];
    }

    // --- pose math ------------------------------------------------------------------

    public function testYawPitchConventionMatchesQuaternionAndForward(): void
    {
        $cases = [
            [new Vec3(0, 0, -1), 0.0, 0.0],
            [new Vec3(-1, 0, 0), M_PI / 2, 0.0],
            [new Vec3(1, 0, 0), -M_PI / 2, 0.0],
            [new Vec3(0, 0, 1), M_PI, 0.0],
            [new Vec3(0, 1, -1), 0.0, M_PI / 4],
        ];
        foreach ($cases as [$dir, $yaw, $pitch]) {
            [$y, $p] = CameraPose::anglesFor($dir);
            self::assertEqualsWithDelta(abs($yaw), abs($y), 1e-9);
            self::assertEqualsWithDelta($pitch, $p, 1e-9);
            $pose = new CameraPose(Vec3::zero(), $y, $p);
            $n = $dir->normalize();
            self::assertTrue($pose->forward()->equals($n, 1e-9), "forward() for {$dir}");
            $q = $pose->toQuaternion()->rotateVec3(new Vec3(0, 0, -1));
            self::assertTrue($q->equals($n, 1e-9), "quaternion forward for {$dir}");
        }
    }

    public function testLookingAtFacesTarget(): void
    {
        $pose = CameraPose::lookingAt(new Vec3(10, 5, 10), new Vec3(10, 5, -20));
        self::assertEqualsWithDelta(0.0, $pose->yaw, 1e-12);
        self::assertEqualsWithDelta(0.0, $pose->pitch, 1e-12);
        $pose = CameraPose::lookingAt(new Vec3(0, 10, 0), new Vec3(5, 0, 0));
        self::assertTrue($pose->forward()->equals((new Vec3(5, -10, 0))->normalize(), 1e-9));
        self::assertSame(0.0, CameraPose::lookingAt(Vec3::zero(), Vec3::zero())->yaw);
    }

    public function testLerpTakesShortWayAndIsExactAtEnds(): void
    {
        $a = new CameraPose(Vec3::zero(), 3.0, 0.0);
        $b = new CameraPose(new Vec3(2, 0, 0), -3.0, 0.2);
        self::assertSame($a, CameraPose::lerp($a, $b, 0.0));
        self::assertSame($b, CameraPose::lerp($a, $b, 1.0));
        $mid = CameraPose::lerp($a, $b, 0.5);
        // The short way from 3 to -3 passes through PI (6.28 - 6 = 0.28 apart), not through 0.
        self::assertEqualsWithDelta(M_PI, abs(CameraPose::wrapAngle($mid->yaw)), 1e-9);
    }

    // --- path ---------------------------------------------------------------------

    public function testEndpointsAreExact(): void
    {
        $pts = $this->zigzag();
        foreach (CameraEasing::cases() as $easing) {
            $f = new CameraFlight($pts, new Vec3(30, 0, 0), 10.0, $easing);
            self::assertTrue($f->evaluate(0.0)->position->equals($pts[0], 1e-9), $easing->value);
            self::assertTrue($f->evaluate(1.0)->position->equals($pts[4], 1e-9), $easing->value);
            self::assertTrue($f->evaluate(-3.0)->position->equals($pts[0], 1e-9));
            self::assertTrue($f->evaluate(7.0)->position->equals($pts[4], 1e-9));
        }
    }

    public function testPathPassesThroughEveryWaypoint(): void
    {
        $pts = $this->zigzag();
        $f = new CameraFlight($pts, new Vec3(30, 0, 0), 10.0, CameraEasing::Linear);
        foreach ($pts as $i => $p) {
            $best = INF;
            for ($k = 0; $k <= 4000; $k++) {
                $best = min($best, $f->evaluate($k / 4000)->position->sub($p)->length());
            }
            self::assertLessThan(0.05, $best, "waypoint {$i}");
        }
    }

    public function testVelocityIsContinuousThroughWaypoints(): void
    {
        $pts = $this->zigzag();
        $f = new CameraFlight($pts, new Vec3(30, 0, 0), 10.0, CameraEasing::Linear);
        $h = 1e-4;
        // Find the time of each interior waypoint, then compare one-sided velocities.
        for ($i = 1; $i < 4; $i++) {
            $tBest = 0.0;
            $best = INF;
            for ($k = 0; $k <= 20000; $k++) {
                $d = $f->positionAt($k / 20000)->sub($pts[$i])->length();
                if ($d < $best) {
                    $best = $d;
                    $tBest = $k / 20000;
                }
            }
            $vBefore = $f->positionAt($tBest)->sub($f->positionAt($tBest - $h))->div($h);
            $vAfter = $f->positionAt($tBest + $h)->sub($f->positionAt($tBest))->div($h);
            $speed = $vBefore->length();
            self::assertGreaterThan(0.0, $speed);
            // Relative velocity jump across the waypoint; a C0-only path would show an O(1) jump.
            self::assertLessThan(0.03, $vAfter->sub($vBefore)->length() / $speed, "waypoint {$i}");
        }
    }

    public function testLinearEasingFliesAtConstantSpeed(): void
    {
        $f = new CameraFlight($this->zigzag(), new Vec3(30, 0, 0), 10.0, CameraEasing::Linear);
        $h = 1e-3;
        $speeds = [];
        for ($t = 0.01; $t < 0.99; $t += 0.01) {
            $speeds[] = $f->positionAt($t + $h)->sub($f->positionAt($t))->length() / $h;
        }
        $mean = array_sum($speeds) / count($speeds);
        self::assertLessThan(0.02, (max($speeds) - min($speeds)) / $mean);
    }

    public function testNoOvershootLoopsOnSharpTurn(): void
    {
        // A near-U-turn: centripetal parameterisation must not loop or fly far outside the hull.
        $pts = [new Vec3(0, 0, 0), new Vec3(10, 0, 0), new Vec3(10.5, 0, 1), new Vec3(0, 0, 2)];
        $f = new CameraFlight($pts, new Vec3(0, 0, 100), 4.0, CameraEasing::Linear);
        for ($k = 0; $k <= 1000; $k++) {
            $p = $f->positionAt($k / 1000);
            self::assertLessThan(11.5, $p->x);
            self::assertGreaterThan(-1.0, $p->x);
            self::assertGreaterThan(-1.0, $p->z);
            self::assertLessThan(3.0, $p->z);
        }
    }

    public function testEasingsAreMonotonicAndHitEnds(): void
    {
        foreach (CameraEasing::cases() as $e) {
            self::assertSame(0.0, $e->apply(0.0));
            self::assertEqualsWithDelta(1.0, $e->apply(1.0), 1e-12);
            $prev = -1.0;
            for ($k = 0; $k <= 100; $k++) {
                $v = $e->apply($k / 100);
                self::assertGreaterThanOrEqual($prev, $v);
                $prev = $v;
            }
        }
        self::assertEqualsWithDelta(0.5, CameraEasing::EaseInOut->apply(0.5), 1e-12);
    }

    // --- look / yaw -------------------------------------------------------------

    public function testFixedLookKeepsTargetInFrontOfCamera(): void
    {
        $target = new Vec3(30, 2, -5);
        $f = new CameraFlight($this->zigzag(), $target, 10.0);
        foreach ([0.0, 0.2, 0.55, 1.0] as $t) {
            $pose = $f->evaluate($t);
            $dir = $target->sub($pose->position)->normalize();
            self::assertTrue($pose->forward()->equals($dir, 1e-9));
        }
    }

    public function testPerWaypointLookPointsAreHitAtWaypoints(): void
    {
        $pts = [new Vec3(0, 5, 0), new Vec3(30, 5, 0), new Vec3(60, 5, 0)];
        $looks = [new Vec3(0, 5, -50), new Vec3(30, 5, -50), new Vec3(60, 5, 20)];
        $f = new CameraFlight($pts, $looks, 6.0, CameraEasing::Linear);
        $start = $f->evaluate(0.0);
        self::assertTrue($start->forward()->equals(new Vec3(0, 0, -1), 1e-9));
        $mid = $f->evaluate(0.5);
        self::assertTrue($mid->forward()->equals(new Vec3(0, 0, -1), 1e-3));
        $end = $f->evaluate(1.0);
        self::assertTrue($end->forward()->equals(new Vec3(0, 0, 1), 1e-9));
    }

    public function testYawNeverJumpsAcrossFramesEvenCircling(): void
    {
        // 2.5 turns around the target: raw atan2 would wrap several times.
        $f = CameraFlight::orbitArc(new Vec3(0, 0, 0), 30.0, 10.0, 0.0, 5.0 * M_PI, 12.0, null, CameraEasing::Linear);
        $prev = $f->evaluate(0.0)->yaw;
        $first = $prev;
        for ($k = 1; $k <= 2000; $k++) {
            $yaw = $f->evaluate($k / 2000)->yaw;
            self::assertLessThan(0.05, abs($yaw - $prev), "frame {$k}");
            $prev = $yaw;
        }
        self::assertEqualsWithDelta(5.0 * M_PI, abs($prev - $first), 0.05);
    }

    // --- helpers ------------------------------------------------------------------

    public function testOrbitArcStaysOnRadius(): void
    {
        $c = new Vec3(100, 3, -40);
        $f = CameraFlight::orbitArc($c, 25.0, 12.0, 0.7, 2.0, 8.0);
        for ($k = 0; $k <= 400; $k++) {
            $p = $f->evaluate($k / 400)->position;
            $r = hypot($p->x - $c->x, $p->z - $c->z);
            self::assertEqualsWithDelta(25.0, $r, 25.0 * 0.006);
            self::assertEqualsWithDelta(15.0, $p->y, 1e-9);
        }
        $start = $f->evaluate(0.0)->position;
        self::assertEqualsWithDelta($c->x + 25.0 * sin(0.7), $start->x, 1e-9);
        self::assertEqualsWithDelta($c->z + 25.0 * cos(0.7), $start->z, 1e-9);
        // Looks at the centre by default.
        self::assertTrue($f->evaluate(0.3)->forward()->equals($c->sub($f->evaluate(0.3)->position)->normalize(), 1e-9));
    }

    public function testApproachIsStraightAndEndsLookingAtLandmark(): void
    {
        $look = new Vec3(0, 20, -100);
        $f = CameraFlight::approach(new Vec3(0, 30, 50), new Vec3(0, 30, -40), $look, 5.0);
        for ($k = 0; $k <= 50; $k++) {
            $p = $f->evaluate($k / 50)->position;
            self::assertEqualsWithDelta(0.0, $p->x, 1e-9);
            self::assertEqualsWithDelta(30.0, $p->y, 1e-9);
        }
        $end = $f->evaluate(1.0);
        self::assertTrue($end->forward()->equals($look->sub(new Vec3(0, 30, -40))->normalize(), 1e-9));
        self::assertEqualsWithDelta(90.0, $f->pathLength(), 1e-6);
    }

    // --- degenerate input ---------------------------------------------------------

    public function testDegenerateInputThrowsWithClearMessage(): void
    {
        $look = new Vec3(0, 0, 0);
        $bad = [
            'at least 2 waypoints' => fn() => new CameraFlight([new Vec3(1, 1, 1)], $look, 1.0),
            'coincide' => fn() => new CameraFlight([new Vec3(1, 1, 1), new Vec3(1, 1, 1)], $look, 1.0),
            'duration' => fn() => new CameraFlight([new Vec3(0, 0, 0), new Vec3(1, 0, 0)], $look, 0.0),
            'one look point per waypoint' => fn() => new CameraFlight([new Vec3(0, 0, 0), new Vec3(1, 0, 0)], [$look], 1.0),
            'not finite' => fn() => new CameraFlight([new Vec3(0, 0, 0), new Vec3(NAN, 0, 0)], $look, 1.0),
            'radius' => fn() => CameraFlight::orbitArc($look, 0.0, 1.0, 0.0, 1.0, 1.0),
            'sweep' => fn() => CameraFlight::orbitArc($look, 5.0, 1.0, 0.0, 0.0, 1.0),
        ];
        foreach ($bad as $needle => $make) {
            try {
                $make();
                self::fail("expected exception for {$needle}");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString($needle, $e->getMessage());
            }
        }
    }

    public function testNegativeBlendThrows(): void
    {
        $f = CameraFlight::approach(Vec3::zero(), new Vec3(1, 0, 0), new Vec3(0, 0, -5), 1.0);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('blendIn');
        new CameraFlightPlayer($f, new CameraPose(Vec3::zero(), 0.0, 0.0), -0.1);
    }

    public function testInputVectorsAreCopied(): void
    {
        $a = new Vec3(0, 0, 0);
        $f = CameraFlight::approach($a, new Vec3(10, 0, 0), new Vec3(5, 0, -5), 1.0);
        $a->x = 99.0;
        self::assertEqualsWithDelta(0.0, $f->evaluate(0.0)->position->x, 1e-12);
    }

    private static function assertSamePose(CameraPose $expected, CameraPose $actual, string $msg = ""): void
    {
        self::assertSame($expected->position->x, $actual->position->x, $msg);
        self::assertSame($expected->position->y, $actual->position->y, $msg);
        self::assertSame($expected->position->z, $actual->position->z, $msg);
        self::assertSame($expected->yaw, $actual->yaw, $msg);
        self::assertSame($expected->pitch, $actual->pitch, $msg);
    }

    // --- player -------------------------------------------------------------------

    private function player(float $in = 0.6, float $out = 0.6, ?CameraPose $origin = null): CameraFlightPlayer
    {
        $f = CameraFlight::orbitArc(new Vec3(0, 0, 0), 30.0, 10.0, 0.0, 2.0, 4.0);
        return new CameraFlightPlayer($f, $origin ?? new CameraPose(new Vec3(5, 2, 8), 0.4, -0.1), $in, $out);
    }

    public function testBlendsAreExactAtStartAndEnd(): void
    {
        $p = $this->player();
        self::assertEqualsWithDelta(5.2, $p->totalDuration(), 1e-12);
        self::assertTrue($p->poseAt(0.0)->equivalentTo($p->origin(), 1e-12));
        self::assertTrue($p->poseAt(5.2)->equivalentTo($p->origin(), 1e-12));
        self::assertSamePose($p->origin(), $p->poseAt(99.0));
        // End of blend-in equals the flight's first pose (no jump), start of blend-out its last pose.
        $first = $p->poseAt(0.6);
        self::assertTrue($first->position->equals($p->flight()->evaluate(0.0)->position, 1e-9));
        $before = $p->poseAt(0.6 - 1e-6);
        self::assertLessThan(1e-3, $before->position->sub($first->position)->length());
        $last = $p->poseAt(4.6);
        self::assertTrue($last->position->equals($p->flight()->evaluate(1.0)->position, 1e-9));
    }

    public function testNoJumpsAnywhereOnTheTimeline(): void
    {
        foreach ([$this->player(), $this->player(origin: new CameraPose(new Vec3(-50, 0, 20), 7.5, 0.3))] as $p) {
            $dt = 1.0 / 240.0;
            $total = $p->totalDuration();
            $prev = $p->poseAt(0.0);
            $steps = (int) ceil($total / $dt);
            for ($i = 1; $i <= $steps; $i++) {
                $e = min($total, $i * $dt);
                $pose = $p->poseAt($e);
                self::assertLessThan(1.0, $pose->position->sub($prev->position)->length(), "t={$e}");
                self::assertLessThan(0.1, abs(CameraPose::wrapAngle($pose->yaw - $prev->yaw)), "wrapped yaw jump at t={$e}");
                // The last step lands on the verbatim origin yaw (equal modulo 2 PI); every other step is continuous unwrapped.
                if ($e < $total) {
                    self::assertLessThan(0.1, abs($pose->yaw - $prev->yaw), "yaw jump at t={$e}");
                }
                self::assertLessThan(0.1, abs($pose->pitch - $prev->pitch));
                $prev = $pose;
            }
            self::assertSamePose($p->origin(), $prev);
        }
    }

    public function testFinalFrameMayDifferByWholeTurnsButIsTheSameDirection(): void
    {
        // A flight sweeping 5 PI net from yaw 0 ends 2.5 turns on; the origin is yaw 0.
        $f = CameraFlight::orbitArc(Vec3::zero(), 30.0, 10.0, 0.0, 5.0 * M_PI, 6.0);
        $p = new CameraFlightPlayer($f, new CameraPose(new Vec3(0, 10, 30), 0.0, 0.0), 0.0, 0.5);
        $beforeEnd = $p->poseAt($p->totalDuration() - 0.01);
        $end = $p->poseAt($p->totalDuration());
        self::assertGreaterThan(M_PI, abs($beforeEnd->yaw - $end->yaw), 'documented whole-turn step on the last frame');
        self::assertLessThan(0.05, abs(CameraPose::wrapAngle($beforeEnd->yaw - $end->yaw)));
    }

    public function testHandedOutPosesAreCopies(): void
    {
        $p = $this->player();
        $p->start();
        $p->origin()->position->x = 1000.0;
        $p->advance(0.0)->position->y = 1000.0;
        $p->cancel()->position->z = 1000.0;
        self::assertSame(5.0, $p->origin()->position->x);
        self::assertSame(2.0, $p->origin()->position->y);
        self::assertSame(8.0, $p->origin()->position->z);
    }

    public function testBlendInTakesShortWayForFarAwayYaw(): void
    {
        // Origin yaw 7.5 is 1.2 rad past one full turn; the flight's yaw is near 0.
        $origin = new CameraPose(new Vec3(0, 0, 0), 7.5, 0.0);
        $p = $this->player(origin: $origin);
        $mid = $p->poseAt(0.3);
        self::assertGreaterThan(5.5, $mid->yaw);
        self::assertLessThan(8.0, $mid->yaw);
    }

    public function testCancelAndSkipEndExactlyAtOrigin(): void
    {
        foreach (['cancel', 'skip'] as $how) {
            $p = $this->player();
            $p->start();
            $p->advance(2.0);
            self::assertTrue($p->isRunning());
            self::assertGreaterThan(0.0, $p->progress());
            self::assertLessThan(1.0, $p->progress());
            $end = $p->$how();
            self::assertSamePose($p->origin(), $end, $how);
            self::assertFalse($p->isRunning());
            self::assertTrue($p->isFinished());
            self::assertSame(1.0, $p->progress());
            self::assertSamePose($p->origin(), $p->advance(0.016));
            self::assertSamePose($p->origin(), $p->currentPose());
        }
    }

    public function testCancelBeforeStartIsHarmless(): void
    {
        $p = $this->player();
        self::assertSamePose($p->origin(), $p->cancel());
        self::assertFalse($p->isFinished());
        self::assertFalse($p->isRunning());
        self::assertSame(0.0, $p->progress());
        self::assertSamePose($p->origin(), $p->advance(1.0));
    }

    public function testRunsToTheEndAndFinishesAtOrigin(): void
    {
        $p = $this->player();
        $p->start();
        $steps = 0;
        $pose = $p->origin();
        while ($p->isRunning()) {
            $pose = $p->advance(1.0 / 60.0);
            $steps++;
            self::assertLessThan(1000, $steps);
        }
        self::assertTrue($p->isFinished());
        self::assertSamePose($p->origin(), $pose);
        self::assertSame(1.0, $p->progress());
        self::assertEqualsWithDelta(5.2 * 60.0, $steps, 1.0);
    }

    public function testStartWhileRunningThrowsAndRestartAfterFinishWorks(): void
    {
        $p = $this->player();
        $p->start();
        try {
            $p->start();
            self::fail('expected LogicException');
        } catch (LogicException $e) {
            self::assertStringContainsString('running', $e->getMessage());
        }
        $p->cancel();
        $p->start();
        self::assertTrue($p->isRunning());
        self::assertFalse($p->isFinished());
        self::assertSame(0.0, $p->elapsed());
    }

    public function testFrameRateIndependence(): void
    {
        $slow = $this->player();
        $fast = $this->player();
        $slow->start();
        $fast->start();
        $slowPose = $fastPose = null;
        $slowElapsed = $fastElapsed = 0.0;
        for ($i = 0; $i < 120; $i++) { // 2.0 s at 60 fps
            $slowPose = $slow->advance(1.0 / 60.0);
        }
        for ($i = 0; $i < 288; $i++) { // 2.0 s at 144 fps
            $fastPose = $fast->advance(1.0 / 144.0);
        }
        self::assertNotNull($slowPose);
        self::assertNotNull($fastPose);
        self::assertEqualsWithDelta(2.0, $slow->elapsed(), 1e-9);
        self::assertEqualsWithDelta(2.0, $fast->elapsed(), 1e-9);
        self::assertTrue($slowPose->position->equals($fastPose->position, 1e-6));
        self::assertEqualsWithDelta($slowPose->yaw, $fastPose->yaw, 1e-6);
        self::assertEqualsWithDelta($slowPose->pitch, $fastPose->pitch, 1e-6);
        self::assertTrue($slowPose->equivalentTo($slow->poseAt(2.0), 1e-6));
    }

    public function testZeroBlendsCutStraightIntoTheFlight(): void
    {
        $p = $this->player(0.0, 0.0);
        self::assertSame(4.0, $p->totalDuration());
        self::assertTrue($p->poseAt(0.0)->position->equals($p->flight()->evaluate(0.0)->position, 1e-9));
        self::assertTrue($p->poseAt(3.999)->position->sub($p->flight()->evaluate(1.0)->position)->length() < 0.5);
        self::assertSamePose($p->origin(), $p->poseAt(4.0));
    }
}
