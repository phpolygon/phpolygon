<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Thread;

use PHPolygon\ECS\World;
use PHPolygon\Thread\SubsystemInterface;

/**
 * Test subsystem fixture for {@see ThreadSchedulerTest}. Lives in its own
 * PSR-4-autoloadable file (not inline in the test) so a worker {@see \parallel\Runtime},
 * bootstrapped with the Composer autoloader, can resolve it by class name.
 */
class PingSubsystem implements SubsystemInterface
{
    public function prepareInput(World $world, float $dt): array
    {
        return ['dt' => $dt, 'entityCount' => $world->entityCount()];
    }

    public function applyDeltas(World $world, array $deltas): void
    {
        // No-op for test — just verify deltas are received
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
        return ['pong' => true, 'dt' => $input['dt'] * 2];
    }
}
