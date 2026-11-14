<?php

declare(strict_types=1);

namespace PHPolygon\Thread;

/**
 * What {@see AsyncJobPool::collect()} hands back for a finished job. The
 * (key, lod, epoch) triple is the caller's own: the pool does not interpret it,
 * it only returns it so the caller can tell a current result from a stale one.
 */
final readonly class AsyncJobResult
{
    /**
     * @param int $workerNs time the handler itself ran, measured in the worker (0 when it never returned)
     * @param int $latencyNs submit() to the moment the pool noticed the job was done
     */
    public function __construct(
        public string $key,
        public int $lod,
        public int $epoch,
        public AsyncJobStatus $status,
        public mixed $value,
        public ?string $error,
        public int $workerNs,
        public int $latencyNs,
    ) {}

    public function isOk(): bool
    {
        return $this->status === AsyncJobStatus::Ok;
    }
}
