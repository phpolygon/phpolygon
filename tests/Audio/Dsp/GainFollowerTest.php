<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Dsp;

use PHPolygon\Audio\Dsp\GainFollower;
use PHPolygon\Audio\Dsp\Pcm;
use PHPUnit\Framework\TestCase;

final class GainFollowerTest extends TestCase
{
    public function testFullSwingDownTakesDownSeconds(): void
    {
        $f = new GainFollower(downSeconds: 0.3, upSeconds: 0.6, swingDb: -8.0);
        $low = Pcm::db(-8.0);

        $f->step(0.15, -8.0);
        self::assertEqualsWithDelta(1.0 - (1.0 - $low) / 2.0, $f->gain(), 1e-12);
        self::assertFalse($f->isSettled());

        $f->step(0.15);
        self::assertEqualsWithDelta($low, $f->gain(), 1e-12);
        self::assertTrue($f->isSettled());

        // Never overshoots.
        $f->step(1.0);
        self::assertEqualsWithDelta($low, $f->gain(), 1e-12);
        self::assertEqualsWithDelta(-8.0, $f->db(), 1e-9);
    }

    public function testFullSwingUpTakesUpSeconds(): void
    {
        $f = new GainFollower(0.3, 0.6, -8.0, initialDb: -8.0);
        $low = Pcm::db(-8.0);

        $f->step(0.3, 0.0);
        self::assertEqualsWithDelta($low + (1.0 - $low) / 2.0, $f->gain(), 1e-12);
        $f->step(0.3);
        self::assertSame(1.0, $f->gain());
    }

    public function testTargetChangeMidRampContinuesFromCurrentGain(): void
    {
        $f = new GainFollower(0.3, 0.6, -8.0);
        $rateDown = (1.0 - Pcm::db(-8.0)) / 0.3;
        $rateUp = (1.0 - Pcm::db(-8.0)) / 0.6;

        $f->step(0.1, -8.0);
        $mid = $f->gain();
        self::assertEqualsWithDelta(1.0 - $rateDown * 0.1, $mid, 1e-12);

        // Turn around: rises from $mid at the UP rate, no jump.
        $f->step(0.05, 0.0);
        self::assertEqualsWithDelta($mid + $rateUp * 0.05, $f->gain(), 1e-12);

        // Back up all the way takes (1 − mid) / rateUp in total.
        $f->step((1.0 - $mid) / $rateUp);
        self::assertEqualsWithDelta(1.0, $f->gain(), 1e-12);
    }

    public function testShallowerTargetIsReachedSoonerAtTheSameRate(): void
    {
        $f = new GainFollower(0.3, 0.6, -8.0);
        $target = Pcm::db(-3.0);
        $rateDown = (1.0 - Pcm::db(-8.0)) / 0.3;

        $f->step((1.0 - $target) / $rateDown, -3.0);
        self::assertEqualsWithDelta($target, $f->gain(), 1e-12);
    }

    public function testZeroTimesJumpAndFloorSilences(): void
    {
        $f = new GainFollower(0.0, 0.0, -12.0);
        self::assertSame(0.0, $f->step(0.0, -90.0));
        self::assertSame(GainFollower::FLOOR_DB, $f->db());
        self::assertSame(1.0, $f->step(0.0, 0.0));
        self::assertSame(1.0, $f->step(0.1, 6.0)); // positive = 0 dB
    }

    public function testEnvelopeMatchesSteppingAndShowsChangesOneBlockLater(): void
    {
        $sr = 1000;
        $block = 10;
        $target = static fn (float $t): float => $t >= 0.05 && $t < 0.2 ? -8.0 : 0.0;

        $env = (new GainFollower(0.03, 0.06, -8.0))->envelope(300, $sr, $target, $block);
        self::assertCount(31, $env);

        $manual = new GainFollower(0.03, 0.06, -8.0);
        foreach ($env as $b => $g) {
            self::assertEqualsWithDelta($manual->gain(), $g, 1e-12);
            $manual->step($block / $sr, $target($b * $block / $sr));
        }
        self::assertSame(1.0, $env[5]);       // target changed at 0.05 s …
        self::assertLessThan(1.0, $env[6]);   // … the gain moves one block later
        self::assertEqualsWithDelta(Pcm::db(-8.0), $env[10], 1e-12);
    }

    public function testExpandInterpolatesPerSample(): void
    {
        $gains = GainFollower::expand([1.0, 0.5, 0.5], 25, 10);
        self::assertCount(25, $gains);
        self::assertSame(1.0, $gains[0]);
        self::assertEqualsWithDelta(0.75, $gains[5], 1e-12);
        self::assertSame(0.5, $gains[10]);
        self::assertSame(0.5, $gains[24]);
    }

    public function testFreshKeepsSpeeds(): void
    {
        $f = (new GainFollower(0.2, 0.4, -6.0))->fresh(-6.0);
        self::assertEqualsWithDelta(Pcm::db(-6.0), $f->gain(), 1e-12);
        self::assertSame(0.4, $f->upSeconds);
    }

    public function testRejectsNonNegativeSwing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GainFollower(0.1, 0.1, 0.0);
    }
}
