<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Thread;

use PHPolygon\ECS\World;
use PHPolygon\Thread\SubsystemInterface;

/**
 * Test subsystem fixture for {@see ThreadSchedulerHardeningTest}: its worker
 * thread (and its compute() under {@see \PHPolygon\Thread\NullThreadScheduler})
 * crashes immediately instead of ever sending a result — simulating the "dead
 * worker" case the scheduler hardening must detect instead of hanging on.
 *
 * Lives in its own PSR-4-autoloadable file, like {@see PingSubsystem}, so a
 * worker {@see \parallel\Runtime} bootstrapped with the Composer autoloader
 * can resolve it by class name.
 */
class CrashingSubsystem implements SubsystemInterface
{
    public function prepareInput(World $world, float $dt): array
    {
        return ['dt' => $dt];
    }

    public function applyDeltas(World $world, array $deltas): void
    {
        // Never reached — compute() always throws.
    }

    public static function threadEntry(string $channelPrefix): void
    {
        throw new \RuntimeException('CrashingSubsystem: worker crashed on purpose');
    }

    public static function compute(array $input): array
    {
        throw new \RuntimeException('CrashingSubsystem: compute() crashed on purpose');
    }
}
