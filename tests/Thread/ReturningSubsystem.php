<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Thread;

use PHPolygon\ECS\World;
use PHPolygon\Thread\SubsystemInterface;

/**
 * Test subsystem fixture for {@see ThreadSchedulerHardeningTest}: its worker
 * thread returns normally — no exception — instead of running threadEntry()'s
 * usual infinite receive loop. A {@see \parallel\Future} that completes this
 * way still fires a `Read`-typed event (confirmed by measurement, not
 * assumed), with its `source` set to the name passed to `addFuture()` rather
 * than the channel's own name — this fixture is what proves
 * {@see \PHPolygon\Thread\ThreadScheduler::pollOne()} tells the two apart by
 * `source`, not by `type` alone.
 */
class ReturningSubsystem implements SubsystemInterface
{
    public function prepareInput(World $world, float $dt): array
    {
        return ['dt' => $dt];
    }

    public function applyDeltas(World $world, array $deltas): void
    {
        // Must never be reached — this worker never sends a result.
    }

    public static function threadEntry(string $channelPrefix): void
    {
        // Returns immediately — no loop, no send — simulating a worker that
        // exited cleanly without ever answering (as opposed to crashing).
    }

    public static function compute(array $input): array
    {
        return [];
    }
}
