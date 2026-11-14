<?php

declare(strict_types=1);

namespace PHPolygon\Thread;

/**
 * One queued or running job of an {@see AsyncJobPool}.
 *
 * @internal
 */
final class AsyncJob
{
    public int $attempts = 0;

    /** Set when the job is already on a worker and cannot be taken back: its result is dropped when it arrives. */
    public bool $cancelled = false;

    /**
     * @param callable-string $handler
     */
    public function __construct(
        public readonly int $id,
        public readonly string $key,
        public readonly int $lod,
        public readonly int $epoch,
        public readonly string $handler,
        public readonly mixed $payload,
        public readonly int $priority,
        public readonly int $seq,
        public readonly int $submittedAt,
    ) {}

    /** Identity used to refuse a duplicate submit. */
    public function slot(): string
    {
        return self::slotOf($this->key, $this->lod, $this->epoch);
    }

    public static function slotOf(string $key, int $lod, int $epoch): string
    {
        return $key . "\0" . $lod . "\0" . $epoch;
    }
}
