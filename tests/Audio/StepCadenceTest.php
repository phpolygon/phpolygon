<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio;

use InvalidArgumentException;
use PHPolygon\Audio\StepCadence;
use PHPolygon\Audio\StepEvent;
use PHPolygon\Audio\StepEventKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StepCadenceTest extends TestCase
{
    private const float DT = 1.0 / 60.0;

    private static function cadence(): StepCadence
    {
        return new StepCadence(
            referenceInterval: 0.45,
            referenceSpeed: 5.0,
            teleportSpeed: 15.0,
            exponent: 0.55,
            minInterval: 0.30,
            maxInterval: 0.55,
        );
    }

    /**
     * Run frames at a constant speed on the ground; returns the times of the steps.
     *
     * @return list<float>
     */
    private static function walk(StepCadence $cadence, float $speed, float $seconds, float $dt = self::DT): array
    {
        $times = [];
        $frames = (int) round($seconds / $dt);
        for ($i = 0; $i < $frames; $i++) {
            $event = $cadence->update($dt, $speed, true);
            if ($event !== null) {
                $times[] = $i * $dt;
            }
        }
        return $times;
    }

    /** One grounded frame, so that landings are armed. */
    private static function ground(StepCadence $cadence): void
    {
        $cadence->update(self::DT, 0.0, true);
    }

    /**
     * Fall for $seconds with a final speed of $fall, then touch down at $speed.
     * The physics zeroes the velocity on contact, as in a real tick.
     */
    private static function fallAndLand(StepCadence $cadence, float $seconds, float $fall, float $speed = 0.0): ?StepEvent
    {
        $frames = (int) round($seconds / self::DT);
        for ($i = 1; $i <= $frames; $i++) {
            self::assertNull($cadence->update(self::DT, $speed, false, -$fall * $i / $frames));
        }
        return $cadence->update(self::DT, $speed, true, 0.0);
    }

    /** @return iterable<string, array{float, float}> */
    public static function tempi(): iterable
    {
        yield 'reference speed' => [5.0, 0.45];
        yield 'running' => [8.5, 0.336];
    }

    #[DataProvider('tempi')]
    public function testStepsComeAtTheIntervalOfTheSpeed(float $speed, float $interval): void
    {
        $times = self::walk(self::cadence(), $speed, 20.0);

        $this->assertGreaterThan(10, count($times));
        for ($i = 1, $n = count($times); $i < $n; $i++) {
            $this->assertEqualsWithDelta($interval, $times[$i] - $times[$i - 1], self::DT + 0.002);
        }
        $this->assertEqualsWithDelta($interval, ($times[$n - 1] - $times[0]) / ($n - 1), 0.002);
    }

    public function testTheIntervalIsClamped(): void
    {
        $cadence = self::cadence();

        $this->assertSame(0.55, $cadence->intervalFor(2.0));
        $this->assertSame(0.30, $cadence->intervalFor(14.0));
    }

    public function testTheFirstStepComesAfterHalfAnInterval(): void
    {
        $times = self::walk(self::cadence(), 5.0, 1.0);

        $this->assertEqualsWithDelta(0.225, $times[0], self::DT + 0.002);
    }

    public function testStandingStillPutsTheNextStepBackToHalfAnInterval(): void
    {
        $cadence = self::cadence();
        self::walk($cadence, 5.0, 0.6);
        self::walk($cadence, 0.0, 0.2);

        $times = self::walk($cadence, 5.0, 1.0);

        $this->assertEqualsWithDelta(0.225, $times[0], self::DT + 0.002);
    }

    /**
     * Physics at 60 Hz under a faster render loop: frames without a tick measure no
     * movement and the next one measures a whole tick in one frame.
     *
     * @return iterable<string, array{int}>
     */
    public static function frameRates(): iterable
    {
        yield '60 fps' => [60];
        yield '144 fps' => [144];
        yield '240 fps' => [240];
    }

    #[DataProvider('frameRates')]
    public function testTheTempoDoesNotDependOnTheFrameRate(int $fps): void
    {
        $cadence = new StepCadence(0.5, 4.0, 50.0);
        $dt = 1.0 / $fps;
        $pending = 0.0;
        $steps = 0;
        for ($i = 0; $i < $fps * 10; $i++) {
            $pending += $dt;
            $ticks = 0;
            while ($pending >= 1.0 / 60.0) {
                $pending -= 1.0 / 60.0;
                $ticks++;
            }
            if ($cadence->update($dt, $ticks / 60.0 * 4.0 / $dt, true) !== null) {
                $steps++;
            }
        }

        $this->assertEqualsWithDelta(20, $steps, 1);
    }

    public function testASingleFrameDropoutKeepsWalking(): void
    {
        $cadence = new StepCadence(0.5, 4.0, 50.0);
        $steps = 0;
        for ($i = 0; $i < 600; $i++) {
            if ($cadence->update(self::DT, $i % 12 === 0 ? 0.0 : 4.0, true) !== null) {
                $steps++;
            }
        }

        // The mean speed is 11/12 of 4 m/s, so a little under 20 steps.
        $this->assertEqualsWithDelta(19, $steps, 1.5);
    }

    public function testNoStepTrailsAfterStopping(): void
    {
        // Stop just before the next step falls due: it must not fire in the grace.
        $cadence = self::cadence();
        self::walk($cadence, 5.0, 2.0);
        while ($cadence->update(self::DT, 5.0, true) === null);
        for ($i = 0; $i < 26; $i++) {
            $this->assertNull($cadence->update(self::DT, 5.0, true));
        }

        for ($i = 0; $i < 120; $i++) {
            $this->assertNull($cadence->update(self::DT, 0.0, true), "frame {$i} after stopping");
        }
    }

    public function testAStopLongerThanTheGraceRestartsTheRhythm(): void
    {
        $cadence = new StepCadence(0.45, 5.0, 15.0, stopGraceSeconds: 0.1);
        self::walk($cadence, 5.0, 0.6);
        self::walk($cadence, 0.0, 0.2);

        $times = self::walk($cadence, 5.0, 1.0);

        $this->assertEqualsWithDelta(0.225, $times[0], self::DT + 0.002);
    }

    public function testAPauseShorterThanTheGraceDoesNotRestartTheRhythm(): void
    {
        $cadence = new StepCadence(0.45, 5.0, 15.0, stopGraceSeconds: 0.1);
        self::walk($cadence, 5.0, 0.6); // steps at 0.225 and 0.675: most of an interval gone
        self::walk($cadence, 0.0, 0.05);

        $times = self::walk($cadence, 5.0, 1.0);

        // A restart would need half an interval, 0.225 s.
        $this->assertLessThan(0.15, $times[0]);
    }

    public function testNoGraceStopsOnTheFirstSlowFrame(): void
    {
        $cadence = new StepCadence(0.45, 5.0, 15.0, stopGraceSeconds: 0.0);
        self::walk($cadence, 5.0, 0.3);
        $cadence->update(self::DT, 0.0, true);

        $times = self::walk($cadence, 5.0, 1.0);

        $this->assertEqualsWithDelta(0.225, $times[0], self::DT + 0.002);
    }

    public function testResetForgetsTheFoot(): void
    {
        $firstSide = static function (StepCadence $cadence): int {
            for ($i = 0; $i < 120; $i++) {
                $event = $cadence->update(self::DT, 5.0, true);
                if ($event !== null) {
                    return $event->side;
                }
            }
            self::fail('no step');
        };
        $cadence = self::cadence();
        $fresh = $firstSide($cadence);

        $cadence->reset();

        $this->assertSame($fresh, $firstSide($cadence));
    }

    public function testNegativeGraceIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StepCadence(0.45, 5.0, 15.0, stopGraceSeconds: -0.1);
    }

    public function testNoStepsBelowTheMinimumSpeed(): void
    {
        $this->assertSame([], self::walk(self::cadence(), 0.9, 5.0));
    }

    public function testFeetAlternate(): void
    {
        $cadence = self::cadence();
        $sides = [];
        for ($i = 0; $i < 600; $i++) {
            $event = $cadence->update(self::DT, 5.0, true);
            if ($event !== null) {
                $sides[] = $event->side;
            }
        }

        $this->assertGreaterThan(8, count($sides));
        for ($i = 1, $n = count($sides); $i < $n; $i++) {
            $this->assertSame(-$sides[$i - 1], $sides[$i]);
        }
    }

    public function testASpeedJoltDoesNotJoltTheRhythm(): void
    {
        $cadence = self::cadence();
        self::walk($cadence, 5.0, 2.0);

        // One frame at full sprint inside a steady walk.
        $cadence->update(self::DT, 12.0, true);
        $times = self::walk($cadence, 5.0, 3.0);

        for ($i = 1, $n = count($times); $i < $n; $i++) {
            $this->assertEqualsWithDelta(0.45, $times[$i] - $times[$i - 1], 0.03);
        }
    }

    public function testNoStepInTheAir(): void
    {
        $cadence = self::cadence();
        self::walk($cadence, 5.0, 1.0);

        for ($i = 0; $i < 120; $i++) {
            $this->assertNull($cadence->update(self::DT, 5.0, false));
        }
    }

    public function testAGapInTheGroundedFlagKeepsTheTempo(): void
    {
        // Every other frame airborne: downhill or a surface the flag flickers on.
        $cadence = self::cadence();
        $times = [];
        for ($i = 0; $i < 1200; $i++) {
            if ($cadence->update(self::DT, 5.0, $i % 2 === 0) !== null) {
                $times[] = $i * self::DT;
            }
        }

        $n = count($times);
        $this->assertEqualsWithDelta(0.45, ($times[$n - 1] - $times[0]) / ($n - 1), 0.02);
    }

    public function testATeleportIsSilentAndRestartsTheRhythm(): void
    {
        $cadence = self::cadence();
        self::walk($cadence, 5.0, 0.4);

        $this->assertNull($cadence->update(self::DT, 200.0, true));

        $times = self::walk($cadence, 5.0, 1.0);
        $this->assertEqualsWithDelta(0.225, $times[0], self::DT + 0.002);
    }

    public function testATeleportOfTheFirstFrameMakesNoSound(): void
    {
        // The speed from a position delta is huge on the first frame after a load.
        $this->assertNull(self::cadence()->update(self::DT, 5000.0, true));
    }

    public function testDisabledFramesMakeNoSound(): void
    {
        $cadence = self::cadence();
        for ($i = 0; $i < 300; $i++) {
            $this->assertNull($cadence->update(self::DT, 5.0, true, 0.0, false));
        }
    }

    public function testAZeroLengthFrameChangesNothing(): void
    {
        $this->assertNull(self::cadence()->update(0.0, 5.0, true));
    }

    public function testAJumpLandsOnceWithItsStrength(): void
    {
        $cadence = self::cadence();
        self::ground($cadence);

        $event = self::fallAndLand($cadence, 0.8, 8.0);

        $this->assertNotNull($event);
        $this->assertSame(StepEventKind::Landing, $event->kind);
        $this->assertEqualsWithDelta(5.0 / 9.0, $event->strength, 1e-9);
        $this->assertSame(0, $event->side);
        for ($i = 0; $i < 120; $i++) {
            $this->assertNull($cadence->update(self::DT, 0.0, true));
        }
    }

    public function testAHardFallIsFullStrength(): void
    {
        $cadence = self::cadence();
        self::ground($cadence);

        $event = self::fallAndLand($cadence, 1.5, 30.0);

        $this->assertNotNull($event);
        $this->assertSame(1.0, $event->strength);
    }

    public function testAShortHopDoesNotLand(): void
    {
        $cadence = self::cadence();
        self::ground($cadence);

        $this->assertNull(self::fallAndLand($cadence, 0.1, 8.0));
    }

    public function testASlowDescentDoesNotLand(): void
    {
        $cadence = self::cadence();
        self::ground($cadence);

        $this->assertNull(self::fallAndLand($cadence, 0.8, 2.5));
    }

    public function testTheSpawnDropDoesNotLand(): void
    {
        $this->assertNull(self::fallAndLand(self::cadence(), 0.8, 8.0));
    }

    public function testTheFirstDropArmsTheNextLanding(): void
    {
        $cadence = self::cadence();
        self::fallAndLand($cadence, 0.8, 8.0);

        $this->assertNotNull(self::fallAndLand($cadence, 0.8, 8.0));
    }

    public function testATeleportInTheAirDoesNotLand(): void
    {
        $cadence = self::cadence();
        self::ground($cadence);
        for ($i = 0; $i < 30; $i++) {
            $cadence->update(self::DT, 0.0, false, -6.0);
        }

        $cadence->update(self::DT, 300.0, false, -6.0);

        $this->assertNull(self::fallAndLand($cadence, 0.3, 8.0));
    }

    public function testFallingIntoSomethingThatIsNotGroundDoesNotLand(): void
    {
        $cadence = self::cadence();
        self::ground($cadence);
        for ($i = 0; $i < 30; $i++) {
            $cadence->update(self::DT, 0.0, false, -8.0);
        }

        $cadence->update(self::DT, 0.0, false, -8.0, false); // water, a seat, a vehicle

        $this->assertNull($cadence->update(self::DT, 0.0, true));
    }

    public function testTheNextStepWaitsAfterALanding(): void
    {
        $cadence = self::cadence();
        self::walk($cadence, 5.0, 1.0);

        $event = self::fallAndLand($cadence, 0.8, 8.0, 5.0);

        $this->assertNotNull($event);
        $this->assertSame(StepEventKind::Landing, $event->kind);
        $this->assertEqualsWithDelta(5.0, $event->speed, 1e-9);
        $times = self::walk($cadence, 5.0, 1.0);
        $this->assertEqualsWithDelta(0.6 * 0.45, $times[0], self::DT + 0.002);
    }

    public function testASameInputRunIsRepeatable(): void
    {
        $run = static function (): array {
            $cadence = self::cadence();
            $events = [];
            for ($i = 0; $i < 900; $i++) {
                $speed = 5.0 + 3.0 * sin($i * 0.01);
                $events[] = $cadence->update(self::DT, $speed, $i % 97 > 20, $i % 97 > 20 ? 0.0 : -5.0);
            }
            return $events;
        };

        $this->assertEquals($run(), $run());
    }

    public function testInvalidParametersAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StepCadence(0.45, 5.0, 0.5);
    }

    public function testAnEmptyIntervalRangeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StepCadence(0.45, 5.0, 15.0, minInterval: 0.6, maxInterval: 0.5);
    }
}
