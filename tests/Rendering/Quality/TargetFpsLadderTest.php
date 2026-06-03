<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Quality\TargetFpsLadder;

final class TargetFpsLadderTest extends TestCase
{
    public function testStepDownToFortyFiveFromSixty(): void
    {
        $this->assertSame(45.0, TargetFpsLadder::stepDownTo(60.0, 45.0));
    }

    public function testStepDownToFloorOnHigherCurrent(): void
    {
        // Critical pressure passes floor=30; 144 -> 30 must be allowed.
        $this->assertSame(30.0, TargetFpsLadder::stepDownTo(144.0, 30.0));
    }

    public function testStepDownAlwaysGoesAtLeastOneStep(): void
    {
        // current 60, floor 50 -> 50 (one ladder step below)
        $this->assertSame(50.0, TargetFpsLadder::stepDownTo(60.0, 50.0));
    }

    public function testStepDownReturnsCurrentWhenAlreadyAtFloor(): void
    {
        // Serious pressure repeatedly while targetFps is already 45 must not
        // ratchet further down. Otherwise the monitor would over-apply.
        $this->assertSame(45.0, TargetFpsLadder::stepDownTo(45.0, 45.0));
    }

    public function testStepDownReturnsCurrentWhenBelowFloor(): void
    {
        $this->assertSame(30.0, TargetFpsLadder::stepDownTo(30.0, 45.0));
    }

    public function testStepUpFromFortyFiveCappedAtSixty(): void
    {
        $this->assertSame(50.0, TargetFpsLadder::stepUp(45.0, 60.0));
    }

    public function testStepUpFromFiftyHitsSixty(): void
    {
        $this->assertSame(60.0, TargetFpsLadder::stepUp(50.0, 60.0));
    }

    public function testStepUpNeverExceedsCeiling(): void
    {
        $this->assertSame(60.0, TargetFpsLadder::stepUp(60.0, 60.0));
        $this->assertSame(60.0, TargetFpsLadder::stepUp(75.0, 60.0));
    }

    public function testStepUpUsesHighCeiling(): void
    {
        $this->assertSame(120.0, TargetFpsLadder::stepUp(90.0, 144.0));
    }

    public function testStepUpReachesOneFortyFour(): void
    {
        $this->assertSame(144.0, TargetFpsLadder::stepUp(120.0, 144.0));
    }
}
