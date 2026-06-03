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
 *   - Otherwise the cap is the more restrictive of targetFps and the fixed
 *     tick rate. This keeps the sky / interpolation invariant intact while
 *     letting the ThermalMonitor's targetFps reduction actually throttle
 *     the render loop.
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

    public function testUncappedPreferenceUsesMinOfTargetFpsAndTickRate(): void
    {
        // Equal: 60 tick + 60 target -> 60
        $this->assertSame(60, $this->capFor(60.0, 0, 60.0));
        // Tick limits: 30 tick + 60 target -> 30
        $this->assertSame(30, $this->capFor(30.0, 0, 60.0));
        // targetFps limits: 60 tick + 45 target (thermal) -> 45
        $this->assertSame(45, $this->capFor(60.0, 0, 45.0));
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
