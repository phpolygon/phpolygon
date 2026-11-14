<?php

declare(strict_types=1);

namespace PHPolygon\Thread;

/**
 * Non-blocking job queue over a few worker threads: submit pure-function jobs
 * tagged (key, lod, epoch), collect finished ones from the main loop without
 * ever waiting. Built for streaming glue (bake a cell's geometry, pack its
 * instances) where the result is only useful if it is still current when it
 * arrives, and where the main thread must never stall on a worker.
 *
 * ## Why not the scheduler's channels
 *
 * {@see ThreadScheduler} / {@see \PHPolygon\Simulation\ChannelTransport} carry
 * ONE outstanding request per subsystem and read answers through
 * `parallel\Events::poll()`, whose blocking path costs ~15 ms per wait on the
 * development machine and whose non-blocking path is flagged unexercised on
 * macOS ARM (see ThreadScheduler's class doc). This pool uses the plainest
 * `parallel` primitives instead: `Runtime::run()` queues a task on a worker,
 * `Future::done()` is a boolean read (no Events, no blocking), `Runtime::kill()`
 * ends a hung worker. Worker spawning and the Composer bootstrap are shared
 * with {@see RuntimePool} through {@see ParallelCapability::autoloadBootstrap()}.
 * What it adds over ChannelTransport is not latency (both answer a trivial job
 * in well under a millisecond when polled hot) but several jobs in flight,
 * priorities, cancellation and hang recovery.
 *
 * ## Model
 *
 *  - The pending queue lives on the MAIN thread, ordered by (priority, submit
 *    order); lower priority number = sooner (distance to the player, say). A
 *    worker only ever holds `$inFlightPerWorker` jobs, so cancelling a job that
 *    has not been handed out is dropping an array entry, and a nearer cell
 *    submitted late still overtakes a far one.
 *  - A job is `handler name + payload`. Handlers are registered by name with
 *    {@see register()} as callable STRINGS (`'Class::method'` or a function
 *    name) - a closure cannot cross the thread boundary, a class name can,
 *    because the worker autoloads it. Signature:
 *    `static function (mixed $payload, WorkerCache $cache): mixed`. Payload and
 *    result must be what `parallel` can copy: scalars, strings, arrays of
 *    those. Pack bulk data ({@see \PHPolygon\Geometry\PackedMeshData::pack()},
 *    `pack('g*')`) into strings: one memcpy instead of per-element copies.
 *  - Each worker keeps a {@see WorkerCache} between jobs.
 *  - Every worker sets `memory_limit` to the pool's limit at the start of
 *    each job: `parallel` workers start with the php.ini default, which is
 *    128 MB on a stock install and too small for a baked tile.
 *  - Results carry back the caller's (key, lod, epoch). {@see cancelOlderThan()}
 *    raises an epoch floor: pending jobs below it are dropped, running ones
 *    are cancelled if they have not started and otherwise discarded when they
 *    finish, and {@see collect()} never returns a result below the floor.
 *
 * ## Failure handling
 *
 *  - A handler that throws: `AsyncJobStatus::Error`, the worker lives on.
 *  - A fatal inside a worker (memory limit): the escaped error makes the
 *    pool recycle that worker (kill + respawn on demand) rather than trust
 *    it; the job is reported as Error, other jobs queued on that worker are
 *    requeued once.
 *  - A job running longer than `$hungAfterSeconds`: reported TimedOut,
 *    its worker killed (`Runtime::kill()` interrupts a PHP loop within a few
 *    ms; measured 16 ms) and replaced. This is the only path that costs the
 *    main thread real time, once per hang.
 *  - No `parallel` (or 0 workers, or every spawn failing): jobs run INLINE in
 *    {@see collect()}, in priority order, at most `$inlineJobsPerCollect`
 *    per call. Same results, deterministic, usable in headless tests.
 */
final class AsyncJobPool
{
    /** @var array<string, callable-string> name => callable string */
    private array $handlers = [];

    /** @var array<int, AsyncJob> by job id */
    private array $pending = [];

    private bool $pendingSorted = true;

    /** @var array<string, int> job slot => job id, for pending and in-flight jobs */
    private array $index = [];

    /** @var list<AsyncWorker> */
    private array $workers = [];

    /** @var list<AsyncJobResult> */
    private array $ready = [];

    private int $epochFloor = PHP_INT_MIN;

    private int $nextId = 0;

    private int $nextSeq = 0;

    private bool $inline;

    /** @var array<string, int> */
    private array $stats = [
        'submitted' => 0,
        'completed' => 0,
        'failed' => 0,
        'timedOut' => 0,
        'cancelled' => 0,
        'stale' => 0,
        'recycled' => 0,
        'requeued' => 0,
        'spawned' => 0,
    ];

    /**
     * @param int    $workers            worker threads; -1 = {@see ParallelCapability::getRecommendedThreadCount()}, 0 = inline
     * @param string $memoryLimit        `memory_limit` each worker applies to every job ('512M', '1G', '-1')
     * @param float  $hungAfterSeconds   a job running longer than this is declared hung and its worker killed
     * @param int    $inFlightPerWorker  jobs handed to a worker at once (1 = it idles between a result and the next
     *                                   collect(); 2 hides that gap at the price of one job that cannot be reordered)
     * @param int    $inlineJobsPerCollect cap per collect() call in inline mode (a deterministic throughput model)
     */
    public function __construct(
        int $workers = -1,
        private readonly string $memoryLimit = '512M',
        private readonly float $hungAfterSeconds = 30.0,
        private readonly int $inFlightPerWorker = 2,
        private readonly int $inlineJobsPerCollect = PHP_INT_MAX,
    ) {
        if ($inFlightPerWorker < 1) {
            throw new \InvalidArgumentException('AsyncJobPool: inFlightPerWorker must be at least 1.');
        }
        $count = $workers < 0 ? max(1, ParallelCapability::getRecommendedThreadCount()) : $workers;
        if (!ParallelCapability::isAvailable()) {
            $count = 0;
        }
        $this->inline = $count === 0;
        for ($i = 0; $i < $count; $i++) {
            $this->workers[] = new AsyncWorker();
        }
    }

    public function __destruct()
    {
        $this->shutdown();
    }

    /**
     * Register a handler under a name. $callable is a string the worker can
     * resolve by autoloading: `'Vendor\Class::method'` (static) or a function name.
     *
     * @throws \InvalidArgumentException when it is not a callable string
     */
    public function register(string $name, string $callable): void
    {
        if (!is_callable($callable)) {
            throw new \InvalidArgumentException("AsyncJobPool: '{$callable}' is not a callable string (static 'Class::method' or a function name).");
        }
        $this->handlers[$name] = $callable;
    }

    /**
     * Queue a job. Returns false (and queues nothing) when a job with the same
     * (key, lod, epoch) is already pending or running, or the epoch is below
     * the floor: asking twice for the same thing is not an error, it is a no-op.
     *
     * @param int $priority lower runs sooner among pending jobs
     */
    public function submit(string $key, int $lod, int $epoch, string $handler, mixed $payload, int $priority = 0): bool
    {
        $callable = $this->handlers[$handler] ?? null;
        if ($callable === null) {
            throw new \InvalidArgumentException("AsyncJobPool: no handler registered as '{$handler}'.");
        }
        if ($epoch < $this->epochFloor) {
            return false;
        }
        $slot = AsyncJob::slotOf($key, $lod, $epoch);
        if (isset($this->index[$slot])) {
            return false;
        }
        $id = $this->nextId++;
        $this->pending[$id] = new AsyncJob($id, $key, $lod, $epoch, $callable, $payload, $priority, $this->nextSeq++, hrtime(true));
        $this->index[$slot] = $id;
        $this->pendingSorted = false;
        $this->stats['submitted']++;

        return true;
    }

    /**
     * Finished results, without waiting. Also hands queued jobs to idle
     * workers and notices hung ones, so call it every frame (the work it does
     * when nothing finished is a few boolean reads).
     *
     * @param int $max most results to return this call
     * @return list<AsyncJobResult>
     */
    public function collect(int $max = PHP_INT_MAX): array
    {
        if ($this->inline) {
            $this->runInline();
        } else {
            $this->pump();
        }

        return $this->takeReady($max);
    }

    /**
     * Block until nothing is pending or running, or $timeoutSeconds passed.
     * For shutdown, save points and tests; never from a frame. Busy-waits.
     *
     * @return list<AsyncJobResult>
     */
    public function drain(float $timeoutSeconds = 30.0): array
    {
        $out = [];
        $deadline = hrtime(true) + (int) ($timeoutSeconds * 1e9);
        do {
            array_push($out, ...$this->collect());
            if ($this->pending === [] && $this->inFlight() === 0) {
                break;
            }
            // No sleep: usleep() rounds up to the ~15 ms timer tick on Windows,
            // which would turn every round trip into 15 ms. Spins on the caller.
        } while (hrtime(true) < $deadline);

        return $out;
    }

    /**
     * Drop every job below $epoch and refuse new ones below it. Returns how
     * many were cancelled (pending + in flight; results already collected are
     * the caller's to judge by their epoch).
     */
    public function cancelOlderThan(int $epoch): int
    {
        $this->epochFloor = max($this->epochFloor, $epoch);
        $floor = $this->epochFloor;

        return $this->cancelWhere(static fn (AsyncJob $job): bool => $job->epoch < $floor);
    }

    /** Cancel the jobs of one key (every lod, or just $lod). */
    public function cancel(string $key, ?int $lod = null): int
    {
        return $this->cancelWhere(static fn (AsyncJob $job): bool => $job->key === $key && ($lod === null || $job->lod === $lod));
    }

    public function pendingCount(): int
    {
        return count($this->pending);
    }

    public function inFlight(): int
    {
        $n = 0;
        foreach ($this->workers as $w) {
            $n += count($w->flight);
        }

        return $n;
    }

    /** Whether jobs run on the calling thread (no workers). */
    public function isInline(): bool
    {
        return $this->inline;
    }

    /** Worker threads currently spawned. */
    public function workerCount(): int
    {
        $n = 0;
        foreach ($this->workers as $w) {
            if ($w->runtime !== null) {
                $n++;
            }
        }

        return $n;
    }

    /** @return array<string, int> submitted, completed, failed, timedOut, cancelled, stale, recycled, requeued, spawned */
    public function stats(): array
    {
        return $this->stats;
    }

    /** Stop every worker: idle ones are closed (joined), busy ones killed. Safe to call twice. */
    public function shutdown(): void
    {
        foreach ($this->workers as $w) {
            $rt = $w->runtime;
            if ($rt === null) {
                continue;
            }
            try {
                if ($w->flight === []) {
                    $rt->close();
                } else {
                    $rt->kill();
                }
            } catch (\Throwable) {
                // Already gone.
            }
            $w->runtime = null;
            $w->flight = [];
        }
        $this->pending = [];
        $this->index = [];
    }

    // ------------------------------------------------------------------

    /**
     * @param \Closure(AsyncJob): bool $matches
     */
    private function cancelWhere(\Closure $matches): int
    {
        $n = 0;
        foreach ($this->pending as $id => $job) {
            if ($matches($job)) {
                unset($this->pending[$id], $this->index[$job->slot()]);
                $n++;
            }
        }
        foreach ($this->workers as $w) {
            $keep = [];
            $oldHead = $w->flight[0][0] ?? null;
            foreach ($w->flight as $entry) {
                [$job, $future] = $entry;
                if (!$matches($job)) {
                    $keep[] = $entry;
                    continue;
                }
                $n++;
                // cancel() succeeds for a task that has not started; a running
                // one cannot be interrupted safely, so it stays and is dropped
                // when it finishes.
                if (!$future->cancel()) {
                    $job->cancelled = true;
                    $keep[] = $entry;
                    continue;
                }
                unset($this->index[$job->slot()]);
            }
            // The hang timer restarts only when the head itself changed: cancelling a job queued BEHIND a hung
            // head must not give the hung one a fresh deadline.
            if (($keep[0][0] ?? null) !== $oldHead) {
                $w->headSince = hrtime(true);
            }
            $w->flight = $keep;
        }
        $this->stats['cancelled'] += $n;

        return $n;
    }

    /** @return list<AsyncJobResult> */
    private function takeReady(int $max): array
    {
        $out = [];
        while ($this->ready !== [] && count($out) < $max) {
            $r = array_shift($this->ready);
            if ($r->epoch < $this->epochFloor) {
                $this->stats['stale']++;
                continue;
            }
            $out[] = $r;
        }

        return $out;
    }

    private function sortPending(): void
    {
        if ($this->pendingSorted) {
            return;
        }
        uasort($this->pending, static fn (AsyncJob $a, AsyncJob $b): int => [$a->priority, $a->seq] <=> [$b->priority, $b->seq]);
        $this->pendingSorted = true;
    }

    private function runInline(): void
    {
        $this->sortPending();
        $ran = 0;
        foreach ($this->pending as $id => $job) {
            if ($ran >= $this->inlineJobsPerCollect) {
                break;
            }
            unset($this->pending[$id], $this->index[$job->slot()]);
            $ran++;
            $t = hrtime(true);
            try {
                $value = ($job->handler)($job->payload, WorkerCache::instance());
                $this->finish($job, AsyncJobStatus::Ok, $value, null, hrtime(true) - $t);
            } catch (\Throwable $e) {
                $this->finish($job, AsyncJobStatus::Error, null, $e::class . ': ' . $e->getMessage(), hrtime(true) - $t);
            }
        }
    }

    private function finish(AsyncJob $job, AsyncJobStatus $status, mixed $value, ?string $error, int $workerNs): void
    {
        match ($status) {
            AsyncJobStatus::Ok => $this->stats['completed']++,
            AsyncJobStatus::Error => $this->stats['failed']++,
            AsyncJobStatus::TimedOut => $this->stats['timedOut']++,
        };
        $this->ready[] = new AsyncJobResult(
            $job->key,
            $job->lod,
            $job->epoch,
            $status,
            $value,
            $error,
            $workerNs,
            hrtime(true) - $job->submittedAt,
        );
    }

    private function pump(): void
    {
        $now = hrtime(true);
        foreach ($this->workers as $w) {
            $this->reap($w, $now);
        }
        $this->dispatch();
        if ($this->inline) {
            // Every spawn failed: carry on without threads.
            $this->runInline();
        }
    }

    private function reap(AsyncWorker $w, int $now): void
    {
        while ($w->flight !== []) {
            [$job, $future] = $w->flight[0];
            if (!$future->done()) {
                if ($now - $w->headSince > (int) ($this->hungAfterSeconds * 1e9)) {
                    array_shift($w->flight);
                    unset($this->index[$job->slot()]);
                    $this->finish($job, AsyncJobStatus::TimedOut, null, "job exceeded {$this->hungAfterSeconds} s", 0);
                    $this->recycle($w);
                }

                return;
            }
            array_shift($w->flight);
            $w->headSince = $now;
            unset($this->index[$job->slot()]);
            if ($job->cancelled || $future->cancelled()) {
                continue;
            }
            try {
                $answer = $future->value();
            } catch (\Throwable $e) {
                // Escaped the handler wrapper: a fatal in the worker, or the thread was killed.
                $this->finish($job, AsyncJobStatus::Error, null, 'worker died running the job (fatal error such as memory_limit): ' . $e::class . ': ' . $e->getMessage(), 0);
                $this->recycle($w);

                return;
            }
            if (!is_array($answer) || !isset($answer[0], $answer[2]) || !is_int($answer[2]) || !array_key_exists(1, $answer)) {
                $this->finish($job, AsyncJobStatus::Error, null, 'worker returned no answer', 0);
                continue;
            }
            if ($answer[0] === true) {
                $this->finish($job, AsyncJobStatus::Ok, $answer[1], null, $answer[2]);
            } else {
                $this->finish($job, AsyncJobStatus::Error, null, is_string($answer[1]) ? $answer[1] : 'job failed', $answer[2]);
            }
        }
    }

    /** Kill the worker, give back its other jobs (once), let the next dispatch respawn it. */
    private function recycle(AsyncWorker $w): void
    {
        $rt = $w->runtime;
        $flight = $w->flight;
        $w->runtime = null;
        $w->flight = [];
        $this->stats['recycled']++;
        try {
            $rt?->kill();
        } catch (\Throwable) {
            // Already dead.
        }
        foreach ($flight as [$job]) {
            if ($job->cancelled) {
                unset($this->index[$job->slot()]);
                continue;
            }
            $job->attempts++;
            if ($job->attempts > 1) {
                unset($this->index[$job->slot()]);
                $this->finish($job, AsyncJobStatus::Error, null, 'worker lost twice', 0);
                continue;
            }
            $this->stats['requeued']++;
            $this->pending[$job->id] = $job;
            $this->pendingSorted = false;
        }
    }

    private function dispatch(): void
    {
        if ($this->pending === []) {
            return;
        }
        $this->sortPending();
        foreach ($this->pending as $id => $job) {
            $target = $this->leastLoadedWorker();
            if ($target === null) {
                return;
            }
            $rt = $this->runtime($target);
            if ($rt === null) {
                continue;
            }
            try {
                $future = $rt->run(self::task(), [$job->handler, $job->payload, $this->memoryLimit]);
            } catch (\parallel\Runtime\Error\IllegalParameter $e) {
                unset($this->pending[$id], $this->index[$job->slot()]);
                $this->finish($job, AsyncJobStatus::Error, null, 'payload cannot cross a thread: ' . $e->getMessage(), 0);
                continue;
            } catch (\Throwable) {
                $this->recycle($target);
                continue;
            }
            if ($future === null) {
                $this->recycle($target);
                continue;
            }
            unset($this->pending[$id]);
            if ($target->flight === []) {
                $target->headSince = hrtime(true);
            }
            $target->flight[] = [$job, $future];
        }
    }

    private function leastLoadedWorker(): ?AsyncWorker
    {
        $best = null;
        $bestLoad = $this->inFlightPerWorker;
        foreach ($this->workers as $w) {
            if ($w->broken) {
                continue;
            }
            $load = count($w->flight);
            if ($load < $bestLoad) {
                $best = $w;
                $bestLoad = $load;
            }
        }

        return $best;
    }

    private function runtime(AsyncWorker $w): ?\parallel\Runtime
    {
        if ($w->runtime !== null) {
            return $w->runtime;
        }
        try {
            $bootstrap = ParallelCapability::autoloadBootstrap();
            $rt = $bootstrap !== null ? new \parallel\Runtime($bootstrap) : new \parallel\Runtime();
        } catch (\Throwable) {
            $w->broken = true;
            $this->inline = true;
            foreach ($this->workers as $other) {
                if (!$other->broken) {
                    $this->inline = false;
                    break;
                }
            }

            return null;
        }
        $this->stats['spawned']++;

        return $w->runtime = $rt;
    }

    /**
     * The function every worker runs for every job. Static, no captured state;
     * an exception inside the handler is an answer (`false, message`), not a
     * crash, so only a fatal or a kill makes the Future throw.
     */
    private static function task(): \Closure
    {
        return static function (string $handler, mixed $payload, string $memoryLimit): array {
            ini_set('memory_limit', $memoryLimit);
            $t = hrtime(true);
            if (!is_callable($handler)) {
                return [false, "handler '{$handler}' is not callable in the worker", 0];
            }
            try {
                $value = $handler($payload, \PHPolygon\Thread\WorkerCache::instance());

                return [true, $value, hrtime(true) - $t];
            } catch (\Throwable $e) {
                return [false, $e::class . ': ' . $e->getMessage(), hrtime(true) - $t];
            }
        };
    }
}
