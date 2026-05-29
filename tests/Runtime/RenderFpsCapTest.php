<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;

/**
 * The engine floors an "uncapped" (0) FPS preference to the fixed tick rate,
 * because the sky is re-emitted per update tick and presenting faster than the
 * tick rate shows un-skied (flickering) frames. Explicit caps stay verbatim.
 */
class RenderFpsCapTest extends TestCase
{
    private function capFor(float $tickRate, int $preference): int
    {
        $engine = new Engine(new EngineConfig(headless: true, targetTickRate: $tickRate));
        // Private methods are invokable via reflection without setAccessible() since PHP 8.1.
        (new \ReflectionMethod(Engine::class, 'applyRenderFpsCap'))->invoke($engine, $preference);
        return $engine->gameLoop->getFpsCap();
    }

    public function testUncappedPreferenceIsFlooredToTickRate(): void
    {
        $this->assertSame(60, $this->capFor(60.0, 0));
        $this->assertSame(30, $this->capFor(30.0, 0));
    }

    public function testExplicitCapIsHonoured(): void
    {
        $this->assertSame(144, $this->capFor(60.0, 144));
        $this->assertSame(30, $this->capFor(60.0, 30));
    }
}
