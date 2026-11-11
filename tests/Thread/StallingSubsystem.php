<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Thread;

use PHPolygon\ECS\World;
use PHPolygon\Thread\SubsystemInterface;

/**
 * Test subsystem fixture for {@see ThreadSchedulerHardeningTest}: a worker
 * that is genuinely alive but slower than the caller's poll bound — the case
 * {@see \PHPolygon\Thread\ThreadScheduler::pollAll()} must tell apart from a
 * dead worker (no exception, the Future never becomes done).
 *
 * `prepareInput()`'s signature carries no extra slot for test configuration,
 * so this fixture reuses `$dt` as the delay to sleep before responding, in
 * seconds — test-only convention, never done by a real subsystem.
 */
class StallingSubsystem implements SubsystemInterface
{
    /** How often applyDeltas() actually ran — set by the main thread, read back by tests. */
    public static int $applyCount = 0;

    public function prepareInput(World $world, float $dt): array
    {
        return ['sleepSeconds' => $dt];
    }

    public function applyDeltas(World $world, array $deltas): void
    {
        self::$applyCount++;
    }

    public static function threadEntry(string $channelPrefix): void
    {
        $in = \parallel\Channel::open("{$channelPrefix}_in");
        $out = \parallel\Channel::open("{$channelPrefix}_out");

        while (true) {
            $input = $in->recv();
            if ($input === null) {
                break;
            }
            $out->send(self::compute($input));
        }
    }

    public static function compute(array $input): array
    {
        $sleepSeconds = $input['sleepSeconds'] ?? 0.0;
        if ($sleepSeconds > 0) {
            usleep((int) ($sleepSeconds * 1_000_000));
        }
        return ['stalled' => true];
    }
}
