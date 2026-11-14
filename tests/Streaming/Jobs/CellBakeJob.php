<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Streaming\Jobs;

use PHPolygon\Thread\WorkerCache;

/** Stand-in for a cell bake: a header naming the cell, padded to a size that shrinks with the LOD. */
final class CellBakeJob
{
    public const int FULL_BYTES = 4000;

    /** @param array{0: int, 1: int, 2: int, 3: int} $payload cx, cz, lod, epoch */
    public static function bake(mixed $payload, WorkerCache $cache): string
    {
        [$cx, $cz, $lod, $epoch] = $payload;
        $head = "{$cx}:{$cz}:{$lod}:{$epoch}|";

        return $head . str_repeat('x', max(0, (self::FULL_BYTES >> $lod) - strlen($head)));
    }

    /**
     * Two strings per cell (a mesh part and a collider part), the shape a real bake returns.
     *
     * @param array{0: int, 1: int, 2: int, 3: int} $payload
     * @return array{0: string, 1: string}
     */
    public static function parts(mixed $payload, WorkerCache $cache): array
    {
        return [str_repeat('m', 3000), str_repeat('c', 1000)];
    }

    /** Like bake(), but the cell (1, 1) always fails. */
    public static function flaky(mixed $payload, WorkerCache $cache): string
    {
        if ($payload[0] === 1 && $payload[1] === 1) {
            throw new \RuntimeException('cell 1:1 cannot be baked');
        }

        return self::bake($payload, $cache);
    }
}
