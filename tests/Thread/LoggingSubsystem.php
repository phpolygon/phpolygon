<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Thread;

use PHPolygon\ECS\World;
use PHPolygon\Thread\SubsystemInterface;

/**
 * Test subsystem fixture for {@see ThreadSchedulerHardeningTest}: exercises
 * the `__log` / `__timings` side channel described on
 * {@see \PHPolygon\Thread\SubsystemInterface} — its applyDeltas() records
 * whatever deltas it was actually handed, so a test can assert the reserved
 * keys never reach it.
 */
class LoggingSubsystem implements SubsystemInterface
{
    /** @var array<string, mixed>|null Set by applyDeltas(), read back by the test. */
    public static ?array $lastAppliedDeltas = null;

    public function prepareInput(World $world, float $dt): array
    {
        return ['dt' => $dt];
    }

    public function applyDeltas(World $world, array $deltas): void
    {
        self::$lastAppliedDeltas = $deltas;
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
        return [
            'ok' => true,
            '__log' => [
                ['text' => 'logging subsystem test fixture ran', 'context' => ['n' => 1], 'level' => 'warn'],
            ],
            '__timings' => ['work' => 12_345],
        ];
    }
}
