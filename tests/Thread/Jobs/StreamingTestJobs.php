<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Thread\Jobs;

use PHPolygon\Thread\WorkerCache;

/**
 * Handlers for the AsyncJobPool tests. Static methods of an autoloadable class:
 * a worker thread resolves them by name through the Composer bootstrap.
 */
final class StreamingTestJobs
{
    public static function echo(mixed $payload, WorkerCache $cache): mixed
    {
        return $payload;
    }

    public static function square(mixed $payload, WorkerCache $cache): int
    {
        return (int) $payload * (int) $payload;
    }

    public static function boom(mixed $payload, WorkerCache $cache): never
    {
        throw new \RuntimeException('boom ' . (string) $payload);
    }

    /** Busy-waits (kill() can interrupt a loop, not a sleep) and returns the milliseconds asked for. */
    public static function spin(mixed $payload, WorkerCache $cache): int
    {
        $until = hrtime(true) + (int) $payload * 1_000_000;
        while (hrtime(true) < $until) {
        }

        return (int) $payload;
    }

    /** Allocates this many megabytes in one string. */
    public static function alloc(mixed $payload, WorkerCache $cache): int
    {
        $s = str_repeat('x', (int) $payload * 1024 * 1024);

        return strlen($s);
    }

    public static function memoryLimit(mixed $payload, WorkerCache $cache): string
    {
        return (string) ini_get('memory_limit');
    }

    /** @return array{token: string, hits: int, misses: int} */
    public static function cacheProbe(mixed $payload, WorkerCache $cache): array
    {
        $token = $cache->remember('token', static fn (): string => bin2hex(random_bytes(6)));

        return ['token' => $token] + $cache->stats();
    }

    /** Packs a float stream the way a bake would: one string out. */
    public static function packFloats(mixed $payload, WorkerCache $cache): string
    {
        $n = (int) $payload;
        $f = [];
        for ($i = 0; $i < $n; $i++) {
            $f[] = $i * 0.5;
        }

        return pack('g*', ...$f);
    }
}
