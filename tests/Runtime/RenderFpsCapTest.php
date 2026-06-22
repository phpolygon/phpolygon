<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Rendering\GraphicsSettings;

/**
 * The engine derives the render FPS cap from GraphicsSettings:
 *   - Explicit fpsCap > 0 wins outright.
 *   - Otherwise the render is UNCAPPED (cap 0). It is deliberately NOT clamped
 *     to the sim tick rate: with fixed-timestep + interpolation the render
 *     should run faster than the sim ticks (a 30 Hz sim must still allow 60+ fps
 *     rendering). targetFps drives adaptive quality, not a hard render ceiling.
 *   - A real-hardware thermal ceiling can still lower the cap under genuine heat
 *     (not exercised here — no real sensor in a headless test).
 */
class RenderFpsCapTest extends TestCase
{
    private function capFor(float $tickRate, int $fpsCap, float $targetFps = 60.0): int
    {
        $engine = new Engine(new EngineConfig(headless: true, targetTickRate: $tickRate));
        $settings = (new GraphicsSettings())->with(targetFps: $targetFps, fpsCap: $fpsCap);
        (new \ReflectionMethod(Engine::class, 'applyRenderFpsCap'))->invoke($engine, $settings);
        return $engine->gameLoop->getFpsCap();
    }

    public function testUncappedMeansUncapped(): void
    {
        // fpsCap == 0 is UNCAPPED (cap 0 -> no throttle), regardless of tick rate
        // or targetFps. Critically a 30 Hz sim must NOT cap rendering at 30:
        // interpolation lets the render run faster than the sim ticks.
        $this->assertSame(0, $this->capFor(60.0, 0, 60.0));
        $this->assertSame(0, $this->capFor(30.0, 0, 60.0));
        $this->assertSame(0, $this->capFor(60.0, 0, 45.0));
    }

    public function testExplicitCapIsHonoured(): void
    {
        $this->assertSame(144, $this->capFor(60.0, 144));
        $this->assertSame(30, $this->capFor(60.0, 30));
    }

    public function testExplicitCapOverridesTargetFps(): void
    {
        // Explicit fpsCap is the player's deliberate choice and wins over
        // any soft target.
        $this->assertSame(120, $this->capFor(60.0, 120, 60.0));
    }
}
