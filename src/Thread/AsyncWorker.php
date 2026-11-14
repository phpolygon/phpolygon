<?php

declare(strict_types=1);

namespace PHPolygon\Thread;

/**
 * One worker thread of an {@see AsyncJobPool}: its runtime (spawned on first
 * use) and the jobs handed to it, oldest first. A `parallel\Runtime` runs its
 * tasks in order, so only the head of {@see $flight} can be running.
 *
 * @internal
 */
final class AsyncWorker
{
    public ?\parallel\Runtime $runtime = null;

    /** @var list<array{0: AsyncJob, 1: \parallel\Future}> job, future */
    public array $flight = [];

    /** When the current head job started waiting for the worker, in hrtime nanoseconds (an upper bound of when it started running). */
    public int $headSince = 0;

    /** Spawning failed: this slot is not used again. */
    public bool $broken = false;
}
