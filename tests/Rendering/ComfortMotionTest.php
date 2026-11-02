<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Accessibility\ComfortMotion;

final class ComfortMotionTest extends TestCase
{
    private static function view(float $yawDegrees, float $x = 0.0): Mat4
    {
        return Mat4::rotationY(deg2rad($yawDegrees))->multiply(Mat4::translation(-$x, 0.0, 0.0));
    }

    public function testClosingFollowsTurnRateAndSpeed(): void
    {
        $dt = 1 / 60;
        self::assertSame(0.0, ComfortMotion::closing(self::view(0.0), self::view(0.0), $dt), 'still');
        self::assertEqualsWithDelta(0.0, ComfortMotion::closing(self::view(0.0), self::view(20.0 * $dt), $dt), 1e-6, 'a slow look-around stays clear');
        self::assertEqualsWithDelta(2 / 3, ComfortMotion::closing(self::view(0.0), self::view(90.0 * $dt), $dt), 1e-3, '90 deg/s');
        self::assertEqualsWithDelta(1.0, ComfortMotion::closing(self::view(0.0), self::view(200.0 * $dt), $dt), 1e-6, 'fast turn');
        self::assertEqualsWithDelta(0.5, ComfortMotion::closing(self::view(0.0), self::view(0.0, 6.5 * $dt), $dt), 1e-3, '6.5 m/s');
        self::assertEqualsWithDelta(0.0, ComfortMotion::closing(self::view(0.0), self::view(0.0, 2.0 * $dt), $dt), 1e-6, 'a walk stays clear');
    }

    public function testRisesFastFadesSlowlyAndIgnoresCuts(): void
    {
        $m = new ComfortMotion();
        $dt = 1 / 60;
        $yaw = 0.0;
        $m->update(self::view($yaw), $dt);
        for ($i = 0; $i < 30; $i++) {
            $yaw += 200.0 * $dt;
            $level = $m->update(self::view($yaw), $dt);
        }
        self::assertGreaterThan(0.99, $level, 'half a second of a fast turn closes it');
        for ($i = 0; $i < 12; $i++) {
            $level = $m->update(self::view($yaw), $dt);
        }
        self::assertGreaterThan(0.5, $level, 'still closing a fifth of a second after the turn');
        self::assertSame($level, $m->update(self::view($yaw + 90.0), 0.0), 'a pinned clock changes nothing');

        $m->reset();
        $m->update(self::view(0.0), $dt);
        self::assertSame(0.0, $m->update(self::view(0.0, 50.0), $dt, 0.0, true), 'a cut is no motion');
        self::assertSame(0.4, $m->update(self::view(0.0, 50.0), $dt, 0.4), 'the game floor');
    }
}
