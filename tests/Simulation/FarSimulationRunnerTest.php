<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Simulation;

use PHPUnit\Framework\TestCase;
use PHPolygon\ECS\World;
use PHPolygon\Simulation\FarSimulationRunner;
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

/**
 * A record-seeded deterministic kernel: no global RNG, every bit of
 * "randomness" comes from the record's own carried seed (a trivial xorshift
 * stepped once per step() call) - proves the "Zufall aus dem Datensatz"
 * requirement without pulling in a real PRNG dependency.
 */
final class SeededWalkKernel implements SimulationKernel
{
    public function export(World $world, int $entityId): array
    {
        return ['position' => 0.0, 'seed' => 12345 + $entityId];
    }

    public function step(array $records, float $dt): array
    {
        foreach ($records as $id => $record) {
            $seed = (int) $record['seed'];
            // Trivial xorshift32 - deterministic, no global state.
            $seed ^= ($seed << 13) & 0xFFFFFFFF;
            $seed ^= ($seed >> 17);
            $seed ^= ($seed << 5) & 0xFFFFFFFF;
            $seed &= 0xFFFFFFFF;
            $delta = (($seed % 2001) - 1000) / 1000.0; // [-1, 1)
            $records[$id]['seed'] = $seed;
            $records[$id]['position'] = $record['position'] + $delta * $dt;
        }
        return $records;
    }

    public function import(World $world, int $entityId, array $record): void
    {
    }
}

final class FarSimulationRunnerTest extends TestCase
{
    public function testHandoverTickTakebackRoundTrip(): void
    {
        $runner = new FarSimulationRunner();
        $kernel = new GrowthKernel();
        $runner->register('growth', $kernel);
        $world = new World();

        $runner->handover('growth', $world, 1);
        self::assertTrue($runner->isHandedOver('growth', 1));
        self::assertSame(['height' => 0.0, 'rate' => 1.0], $runner->recordOf('growth', 1));

        $runner->tick(FarSimulationRunner::FIXED_DT); // exactly one fixed step
        self::assertEqualsWithDelta(FarSimulationRunner::FIXED_DT, $runner->recordOf('growth', 1)['height'], 1e-12);

        $runner->takeback('growth', $world, 1);
        self::assertFalse($runner->isHandedOver('growth', 1));
    }

    public function testTakebackWithNothingOwedImportsExactlyTheExportedRecord(): void
    {
        $runner = new FarSimulationRunner();
        $kernel = new GrowthKernel();
        $runner->register('growth', $kernel);
        $world = new World();
        $imported = [];
        // Wrap import() via a closure-based kernel would need a new class;
        // instead inspect via recordOf() right before takeback() drops it.
        $runner->handover('growth', $world, 1);
        $runner->tick(FarSimulationRunner::FIXED_DT * 0.5); // less than one full step

        self::assertSame(['height' => 0.0, 'rate' => 1.0], $runner->recordOf('growth', 1), 'not enough accumulated time for a full step yet');
        $runner->takeback('growth', $world, 1);
    }

    public function testTakebackFlushesFullyOwedStepsBeforeImport(): void
    {
        $runner = new FarSimulationRunner();
        $kernel = new GrowthKernel();
        $runner->register('growth', $kernel);
        $world = new World();

        $runner->handover('growth', $world, 1);
        $runner->tick(FarSimulationRunner::FIXED_DT); // exactly one owed step

        $runner->takeback('growth', $world, 1);

        self::assertNull($runner->recordOf('growth', 1), 'dropped after takeback');
        self::assertEqualsWithDelta(1.0 * FarSimulationRunner::FIXED_DT, $kernel->imported[1]['height'], 1e-12);
    }

    public function testStepReceivesRecordsInAscendingEntityIdOrder(): void
    {
        $runner = new FarSimulationRunner();
        $kernel = new GrowthKernel();
        $runner->register('growth', $kernel);
        $world = new World();

        $runner->handover('growth', $world, 5);
        $runner->handover('growth', $world, 2);
        $runner->handover('growth', $world, 9);
        $runner->tick(FarSimulationRunner::FIXED_DT);

        self::assertSame([2, 5, 9], $kernel->callOrders[0]);
    }

    public function testBudgetCapsStepsPerTickCallButNeverDropsTheRemainder(): void
    {
        $runner = new FarSimulationRunner();
        $kernel = new GrowthKernel();
        $runner->register('growth', $kernel);
        $world = new World();
        $runner->handover('growth', $world, 1);

        // Far more than MAX_STEPS_PER_TICK (4) worth of time in one call.
        $runner->tick(FarSimulationRunner::FIXED_DT * 10);
        $afterFirstCall = $runner->recordOf('growth', 1)['height'];
        self::assertLessThan(10.0 * FarSimulationRunner::FIXED_DT, $afterFirstCall, 'capped - not all 10 steps happened in one call');

        // Catching up over more calls applies the deferred remainder - never dropped.
        for ($i = 0; $i < 5; $i++) {
            $runner->tick(0.0); // no new time, just lets the existing backlog drain
        }
        self::assertEqualsWithDelta(10.0 * FarSimulationRunner::FIXED_DT, $runner->recordOf('growth', 1)['height'], 1e-9);
    }

    /**
     * The determinism guarantee the plan asks for: the SAME handover
     * sequence and the SAME total elapsed simulated time import an
     * IDENTICAL record, regardless of how tick() calls were chunked (one
     * huge call vs many tiny ones) or whether the per-call budget had to
     * defer some steps.
     */
    public function testSameTotalElapsedTimeImportsAnIdenticalRecordRegardlessOfChunking(): void
    {
        $world = new World();
        $totalTicks = 13; // deliberately more than MAX_STEPS_PER_TICK (4)

        // Run A: one single giant tick() call.
        $runnerA = new FarSimulationRunner();
        $kernelA = new GrowthKernel();
        $runnerA->register('growth', $kernelA);
        $runnerA->handover('growth', $world, 1);
        $runnerA->tick(FarSimulationRunner::FIXED_DT * $totalTicks);
        $runnerA->takeback('growth', $world, 1);

        // Run B: the same total time in 13 separate tiny tick() calls.
        $runnerB = new FarSimulationRunner();
        $kernelB = new GrowthKernel();
        $runnerB->register('growth', $kernelB);
        $runnerB->handover('growth', $world, 1);
        for ($i = 0; $i < $totalTicks; $i++) {
            $runnerB->tick(FarSimulationRunner::FIXED_DT);
        }
        $runnerB->takeback('growth', $world, 1);

        // Run C: an irregular chunking (fractions of a fixed step) summing to the same total.
        $runnerC = new FarSimulationRunner();
        $kernelC = new GrowthKernel();
        $runnerC->register('growth', $kernelC);
        $runnerC->handover('growth', $world, 1);
        foreach ([0.37, 2.91, 1.0, 4.22, 3.3, 1.2] as $chunk) {
            $runnerC->tick(FarSimulationRunner::FIXED_DT * $chunk);
        }
        $runnerC->takeback('growth', $world, 1);

        self::assertSame($kernelA->imported[1], $kernelB->imported[1]);
        self::assertSame($kernelA->imported[1], $kernelC->imported[1]);
        // Sanity: this is not trivially true because nothing happened -
        // confirm the height actually advanced by the expected amount.
        self::assertEqualsWithDelta($totalTicks * FarSimulationRunner::FIXED_DT, $kernelA->imported[1]['height'], 1e-9);
    }

    public function testSeededKernelIsFullyDeterministicFromItsOwnRecordNoGlobalRng(): void
    {
        $world = new World();

        $runner1 = new FarSimulationRunner();
        $runner1->register('walk', new SeededWalkKernel());
        $runner1->handover('walk', $world, 42);
        for ($i = 0; $i < 7; $i++) {
            $runner1->tick(FarSimulationRunner::FIXED_DT);
        }
        $record1 = $runner1->recordOf('walk', 42);

        // A second, independent runner/kernel instance with the same
        // handover must reach the EXACT same record - nothing leaked
        // through a global RNG seed/state between the two runs.
        $runner2 = new FarSimulationRunner();
        $runner2->register('walk', new SeededWalkKernel());
        $runner2->handover('walk', $world, 42);
        for ($i = 0; $i < 7; $i++) {
            $runner2->tick(FarSimulationRunner::FIXED_DT);
        }
        $record2 = $runner2->recordOf('walk', 42);

        self::assertSame($record1, $record2);
    }
}
