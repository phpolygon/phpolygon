<?php

declare(strict_types=1);

namespace PHPolygon\Build;

/**
 * How one {@see PrebakeTask} ended: exit code or signal, the parts it settled, its log.
 */
final class PrebakeTaskResult
{
    /**
     * @param int|null $exitCode null when the process never started or was stopped
     * @param int|null $signal the signal that killed it (POSIX only; the OOM killer sends 9)
     * @param int $done parts the worker reported ({@see PrebakeTask::$poll}); all of them
     *                  for a successful task without a poll
     * @param bool $stopped ended by the pool after another task failed ({@see PrebakePool::$failFast})
     */
    public function __construct(
        public readonly PrebakeTask $task,
        public readonly bool $started,
        public readonly ?int $exitCode,
        public readonly ?int $signal,
        public readonly int $done,
        public readonly string $log,
        public readonly bool $stopped = false,
    ) {
    }

    public function failed(): bool
    {
        return !$this->started
            || $this->stopped
            || $this->signal !== null
            || $this->exitCode !== 0
            || $this->done < $this->task->parts;
    }

    /** Parts the task left unsettled. */
    public function missing(): int
    {
        return max(0, $this->task->parts - $this->done);
    }

    /** Why it failed, short: `exit code 255`, `signal 9 – out of memory?`, ...; '' when it did not. */
    public function reason(): string
    {
        return match (true) {
            !$this->started => 'could not start',
            $this->stopped => 'stopped',
            $this->signal !== null => "signal {$this->signal}" . ($this->signal === 9 ? ' – out of memory? run fewer at a time' : ''),
            $this->exitCode !== 0 => "exit code {$this->exitCode}",
            $this->done < $this->task->parts => "ended after {$this->done} of {$this->task->parts}",
            default => '',
        };
    }
}
