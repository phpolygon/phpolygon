<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Lighting\LightGridGate;

final class LightGridGateTest extends TestCase
{
    public function testTheFirstFrameWithFewLightsNeedsNoGrid(): void
    {
        $gate = new LightGridGate();
        self::assertFalse($gate->needsGrid(0));
    }

    public function testTheFirstFrameWithManyLightsNeedsTheGridAtOnce(): void
    {
        $gate = new LightGridGate();
        self::assertTrue($gate->needsGrid(LightGridGate::UNIFORM_LIGHTS + 1));
    }

    public function testNineLightsSwitchToTheGridInTheSameFrame(): void
    {
        $gate = new LightGridGate();
        self::assertFalse($gate->needsGrid(8));
        self::assertTrue($gate->needsGrid(9), 'a ninth light must not vanish for a few frames');
    }

    public function testTheGridIsHeldUntilTheLightsHaveBeenFewForTheHoldTime(): void
    {
        $gate = new LightGridGate(holdFrames: 5);
        self::assertTrue($gate->needsGrid(12));
        for ($i = 0; $i < 4; $i++) {
            self::assertTrue($gate->needsGrid(3), 'frame ' . $i . ' of the hold');
        }
        self::assertFalse($gate->needsGrid(3), 'the fifth quiet frame leaves the grid');
    }

    public function testAnIntermediateSpikeRestartsTheHold(): void
    {
        $gate = new LightGridGate(holdFrames: 3);
        $gate->needsGrid(12);
        $gate->needsGrid(2);
        $gate->needsGrid(2);
        self::assertTrue($gate->needsGrid(9), 'back above eight: grid, hold counter reset');
        self::assertTrue($gate->needsGrid(2));
        self::assertTrue($gate->needsGrid(2));
        self::assertFalse($gate->needsGrid(2));
    }

    public function testALightCountHoveringAroundTheLimitDoesNotFlipTheProgramEveryFrame(): void
    {
        $gate = new LightGridGate(holdFrames: 30);
        $flips = 0;
        $previous = $gate->needsGrid(8);
        for ($i = 0; $i < 200; $i++) {
            $now = $gate->needsGrid($i % 2 === 0 ? 9 : 8);
            if ($now !== $previous) {
                $flips++;
            }
            $previous = $now;
        }
        self::assertLessThanOrEqual(1, $flips);
    }
}
