<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Simulation;

use PHPolygon\ECS\World;
use PHPolygon\Simulation\SimulationKernel;

/**
 * A linear-growth kernel with no randomness: record = {height, rate},
 * step() does height += rate * dt. Good for chunking-invariance checks
 * because the expected result after N fixed steps is exact arithmetic
 * (no accumulated floating error beyond normal float addition - every
 * step adds EXACTLY rate * FIXED_DT, never a differently-sized dt, so two
 * different chunking patterns that reach the same step COUNT land on
 * bit-identical floats).
 */
final class GrowthKernel implements SimulationKernel
{
    /** @var list<list<int>> every step() call's record-id order, for the ordering test */
    public array $callOrders = [];

    /** @var array<int, array<string, bool|int|float|string>> the record each import() call last received, for tests */
    public array $imported = [];

    public function export(World $world, int $entityId): array
    {
        return ['height' => 0.0, 'rate' => 1.0];
    }

    public function step(array $records, float $dt): array
    {
        $this->callOrders[] = array_keys($records);
        foreach ($records as $id => $record) {
            $records[$id]['height'] = $record['height'] + $record['rate'] * $dt;
        }
        return $records;
    }

    public function import(World $world, int $entityId, array $record): void
    {
        $this->imported[$entityId] = $record;
    }
}
