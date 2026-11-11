<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Thread;

use PHPolygon\ECS\World;
use PHPolygon\Thread\SubsystemInterface;

/**
 * Test subsystem fixture for {@see \PHPolygon\Tests\Thread\ThreadSchedulerHardeningTest}:
 * answers exactly ONE request normally, then returns from threadEntry()
 * without ever calling `recv()` again — a worker that dies BETWEEN ticks,
 * after having been perfectly healthy for the first one, as opposed to
 * {@see CrashingSubsystem}/{@see ReturningSubsystem} (dead before their very
 * first `recv()`). This is the case {@see \PHPolygon\Thread\ThreadScheduler::sendAll()}'s
 * own `done()` check must also cover for the SECOND and later ticks, not
 * just the first one boot()'s grace period narrows.
 */
final class DiesAfterOneAnswerSubsystem implements SubsystemInterface
{
    public function prepareInput(World $world, float $dt): array
    {
        return ['dt' => $dt];
    }

    public function applyDeltas(World $world, array $deltas): void
    {
    }

    public static function threadEntry(string $channelPrefix): void
    {
        $in = \parallel\Channel::open("{$channelPrefix}_in");
        $out = \parallel\Channel::open("{$channelPrefix}_out");

        $input = $in->recv();
        if ($input === null) {
            return;
        }
        $out->send(self::compute($input));
        // A short, deliberate delay before returning: `Events::poll()`
        // watches the `_out` channel AND the kept Future together (see
        // ThreadScheduler::pollOne()) - a worker whose Future resolves in
        // the SAME instant it also delivers its last answer races the two
        // events against each other. CONFIRMED, not assumed: removing this
        // line made the FIRST round trip's own delivery fail 9 of 15 runs
        // (this Windows machine, PHP 8.5.5 ZTS, 2026-11-11) - pollOne()
        // sometimes reports the Future-done event instead of the channel's
        // Read event and marks a worker that just answered correctly as
        // dead. This is a pre-existing property of combining addChannel()
        // and addFuture(), unrelated to this change's channel capacities -
        // a real bug, out of scope here, left for whoever owns pollOne()
        // next. This delay gives the channel delivery an unambiguous head
        // start, so THIS fixture tests what it is meant to (a worker dying
        // BETWEEN ticks), not that unrelated race.
        usleep(20_000);
        // Returns here on purpose — never calls recv() again, simulating a
        // worker that crashes/exits cleanly right after answering its first
        // (and only) request, instead of looping back for a second one.
    }

    public static function compute(array $input): array
    {
        return ['answered' => true];
    }
}
