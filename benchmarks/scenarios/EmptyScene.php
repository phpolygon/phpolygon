<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Scenarios;

use PHPolygon\Benchmarks\Scenario;
use PHPolygon\Engine;
use PHPolygon\System\Renderer3DSystem;

/**
 * Baseline. No entities, no systems beyond the renderer scaffolding.
 * Whatever this scenario takes is engine overhead - it sets the floor
 * that other scenarios should be measured against.
 */
final class EmptyScene implements Scenario
{
    public function name(): string
    {
        return 'empty-scene';
    }

    public function setUp(Engine $engine): void
    {
        if ($engine->renderer3D !== null && $engine->commandList3D !== null) {
            $engine->world->addSystem(new Renderer3DSystem(
                $engine->renderer3D,
                $engine->commandList3D,
            ));
        }
    }

    public function tickFrame(Engine $engine, int $frame, float $dt): void
    {
    }
}
