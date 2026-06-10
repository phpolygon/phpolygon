<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\Season;
use PHPolygon\ECS\World;
use PHPolygon\System\SeasonSystem;

/**
 * Covers the seasonal axialTilt curve in {@see SeasonSystem::update()}.
 *
 * The formula under test is:
 *   axialTilt = cos((yearProgress - 0.25) * 2π) * 15
 * which peaks (+15°) at summer (t=0.25), troughs (-15°) at winter (t=0.75),
 * and crosses zero at spring (t=0) and autumn (t=0.5). This was corrected
 * from an earlier `sin` that peaked in autumn.
 *
 * update() also re-registers vegetation/sand materials via MaterialRegistry;
 * that is a harmless side effect here (the static registry just overwrites
 * a handful of named materials) and does not require any prerequisite setup.
 */
final class SeasonSystemTest extends TestCase
{
    /**
     * Runs the system once on a fresh Season at the given yearProgress and
     * returns the resulting axialTilt. dt is kept tiny so the year barely
     * advances — at speed 0 it does not advance at all.
     */
    private function tiltAt(float $yearProgress): float
    {
        $world = new World();
        $entity = $world->createEntity();
        // speed 0 freezes year advancement so yearProgress stays exactly as set.
        $entity->attach(new Season(yearProgress: $yearProgress, speed: 0.0));

        (new SeasonSystem())->update($world, 0.016);

        return $entity->get(Season::class)->axialTilt;
    }

    public function testSummerTiltIsPlusFifteen(): void
    {
        $this->assertEqualsWithDelta(15.0, $this->tiltAt(0.25), 1e-3);
    }

    public function testWinterTiltIsMinusFifteen(): void
    {
        $this->assertEqualsWithDelta(-15.0, $this->tiltAt(0.75), 1e-3);
    }

    public function testSpringTiltIsZero(): void
    {
        $this->assertEqualsWithDelta(0.0, $this->tiltAt(0.0), 1e-3);
    }

    public function testAutumnTiltIsZero(): void
    {
        $this->assertEqualsWithDelta(0.0, $this->tiltAt(0.5), 1e-3);
    }

    /**
     * Guards against a silent regression back to `sin`: a sin-based curve would
     * read 0 at summer and ±15 at the equinoxes — the exact opposite phase.
     */
    public function testCurveIsCosineNotSine(): void
    {
        // cos curve: summer is the maximum, autumn is a zero crossing.
        $this->assertGreaterThan($this->tiltAt(0.5) + 10.0, $this->tiltAt(0.25));
    }

    public function testYearProgressAdvancesAndWraps(): void
    {
        $world = new World();
        $entity = $world->createEntity();
        // yearDuration == speed*dt accumulation: pick values that wrap past 1.0.
        $season = new Season(yearProgress: 0.99, yearDuration: 1.0, speed: 1.0);
        $entity->attach($season);

        (new SeasonSystem())->update($world, 0.5); // +0.5 of a 1s year -> 1.49 -> wraps to 0.49

        $this->assertGreaterThanOrEqual(0.0, $season->yearProgress);
        $this->assertLessThan(1.0, $season->yearProgress);
        $this->assertEqualsWithDelta(0.49, $season->yearProgress, 1e-6);
    }
}
