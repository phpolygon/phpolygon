<?php

declare(strict_types=1);

namespace PHPolygon\Build;

use Closure;

/**
 * One worker process of a {@see PrebakePool}: a command whose STDOUT and STDERR go to a log
 * file (never to pipes - stream_select() on pipes does not work on Windows).
 *
 * A task covers {@see $parts} parts of the pre-bake (clips, images); one part by default.
 * A worker that settles many parts reports how far it is through {@see $poll} - typically by
 * reading a result file it rewrites after every part. Without $poll, the parts count as
 * settled when the process ends.
 */
final class PrebakeTask
{
    /**
     * @param string $name for messages, e.g. `worker 3` or `encode 1f2e...`
     * @param list<string> $command program and arguments, started without a shell
     * @param string $log file for the worker's STDOUT + STDERR; deleted after a success
     * @param array<string, string> $env variables set over the parent's environment
     * @param (Closure(): ?int)|null $poll parts settled so far; null = unknown (keeps the
     *        last count, e.g. while the worker rewrites its result file). Also asked once
     *        after the process ended: a worker that exits 0 below $parts ended early.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $command,
        public readonly string $log,
        public readonly array $env = [],
        public readonly int $parts = 1,
        public readonly ?Closure $poll = null,
        public readonly ?string $cwd = null,
    ) {
    }
}
