<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Simulation;

use PHPolygon\ECS\World;
use PHPolygon\Simulation\SimulationKernel;

/**
 * Test fixture for the Phase 6 Inline-vs-Channel parity test. Lives in its
 * own PSR-4-autoloadable file (not inlined in the test class) so a worker
 * {@see \parallel\Runtime}, bootstrapped with the Composer autoloader, can
 * resolve it by class name - the same reason {@see \PHPolygon\Tests\Thread\PingSubsystem}
 * is its own file. No-argument constructor, as {@see \PHPolygon\Simulation\FarSimulationSubsystem}
 * requires.
 */
final class GrowthTestKernel implements SimulationKernel
{
    public function export(World $world, int $entityId): array
    {
        return ['height' => 0.0, 'rate' => 1.0];
    }

    public function step(array $records, float $dt): array
    {
        foreach ($records as $id => $record) {
            $records[$id]['height'] = $record['height'] + $record['rate'] * $dt;
        }
        return $records;
    }

    public function import(World $world, int $entityId, array $record): void
    {
    }
}
