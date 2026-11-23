<?php

declare(strict_types=1);

namespace PHPolygon\Runtime\Warmup;

/**
 * Runs {@see WarmupTask}s in slices of a time budget, so a splash or loading screen
 * keeps moving while caches are baked and devices opened.
 *
 * Pull-based, like {@see \PHPolygon\Runtime\LoadingSequence}: call {@see step()} once
 * per frame shown, read {@see fraction()} and {@see currentTask()} for the bar and
 * the label. The engine drives it behind its own splash ({@see \PHPolygon\Engine::addWarmupTask()})
 * right after `onInit`, so whatever is still pending then is run by the engine. A game
 * that wants its own screen to show the progress drives the same runner
 * ({@see \PHPolygon\Engine::warmup()}) from an `onInit` generator, one `step()` per
 * `yield`: what is finished stays finished.
 *
 * - Order: registration order, with dependencies first (stable: of the tasks ready, the
 *   earliest registered goes first).
 * - Budget: a step runs units (a whole atomic task, or one slice of a generator) until
 *   the budget is used up, and always at least one. A unit cannot be interrupted, so
 *   one that is longer than the budget overruns it; {@see results()} keeps the longest
 *   unit of every task so that this shows.
 * - Isolation: an exception from a task is logged and ends that task only; its
 *   dependents are skipped. A progress listener that throws is logged and dropped.
 * - Progress: the weights of finished and skipped tasks plus the reported share of the
 *   running one, over all weights. It never moves backwards.
 *
 * Nothing here knows a game: ids and label keys are opaque.
 */
final class WarmupRunner
{
    public const string PENDING = 'pending';
    public const string RUNNING = 'running';
    public const string DONE = 'done';
    public const string FAILED = 'failed';
    public const string SKIPPED = 'skipped';

    /** @var list<WarmupTask> registration order */
    private array $tasks = [];

    /** @var array<string, int> id => index into $tasks */
    private array $index = [];

    /** @var array<string, string> */
    private array $status = [];

    /** @var array<string, array{seconds: float, maxUnit: float, error: ?string}> */
    private array $report = [];

    /** @var array<string, string> id => why it cannot run, found when planning */
    private array $blocked = [];

    /** @var list<int> run order, indices into $tasks */
    private array $order = [];

    private bool $planned = false;
    private bool $cancelled = false;
    private bool $summarised = false;
    private int $cursor = 0;
    private float $totalWeight = 0.0;
    private float $doneWeight = 0.0;
    private float $last = 0.0;

    /** @var \Generator<int, mixed, mixed, mixed>|null */
    private ?\Generator $gen = null;
    private bool $genStarted = false;
    private float $genFraction = 0.0;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /** @var (\Closure(float, ?string, ?string): void)|null */
    private ?\Closure $listener = null;

    /**
     * @param (\Closure(): float)|null $clock seconds, for tests; null = hrtime
     * @param (\Closure(string): void)|null $log one line per event (failure, skip, summary)
     */
    public function __construct(
        ?\Closure $clock = null,
        private readonly ?\Closure $log = null,
    ) {
        $this->clock = $clock ?? static fn(): float => hrtime(true) / 1e9;
    }

    /**
     * Register a task. Only before the first {@see step()}: the plan (order, bar) is
     * fixed from then on.
     */
    public function add(WarmupTask $task): void
    {
        if ($this->planned) {
            throw new \LogicException('Warm-up tasks cannot be added once the warm-up has started.');
        }
        $id = $task->id();
        if ($id === '') {
            throw new \InvalidArgumentException('A warm-up task needs an id.');
        }
        if (isset($this->index[$id])) {
            throw new \InvalidArgumentException("Warm-up task '$id' is registered twice.");
        }
        $weight = $task->weight();
        if (!is_finite($weight) || $weight <= 0.0) {
            throw new \InvalidArgumentException("Warm-up task '$id' needs a weight above zero.");
        }
        $this->index[$id] = count($this->tasks);
        $this->tasks[] = $task;
        $this->status[$id] = self::PENDING;
        $this->report[$id] = ['seconds' => 0.0, 'maxUnit' => 0.0, 'error' => null];
        $this->totalWeight += $weight;
    }

    /**
     * Called at the end of every {@see step()} with the bar (0..1), the label key of
     * the task that is next and its id (both null when nothing is left).
     *
     * @param (\Closure(float, ?string, ?string): void)|null $listener
     */
    public function onProgress(?\Closure $listener): void
    {
        $this->listener = $listener;
    }

    /** No task registered. */
    public function isEmpty(): bool
    {
        return $this->tasks === [];
    }

    /** Nothing left to run: all work done, skipped or cancelled. True for no tasks at all. */
    public function finished(): bool
    {
        if ($this->tasks === []) {
            return true;
        }
        return $this->cancelled || ($this->planned && $this->cursor >= count($this->order));
    }

    /**
     * Do one slice of work: units until `$budgetSeconds` is used, at least one.
     * Call once per frame shown.
     *
     * @return bool whether work is left
     */
    public function step(float $budgetSeconds): bool
    {
        if ($this->finished()) {
            return false;
        }
        $this->plan();
        $start = ($this->clock)();
        do {
            $this->unit();
        } while (!$this->finished() && ($this->clock)() - $start < $budgetSeconds);

        $this->notify();
        $this->summarise();
        return !$this->finished();
    }

    /** Run everything that is left, without a budget (headless, no screen). */
    public function drain(): void
    {
        while ($this->step(PHP_FLOAT_MAX)) {
            // step() runs to the end with an unlimited budget
        }
    }

    /**
     * Drop what is left: the running generator is destroyed (its `finally` blocks
     * run), every task not finished counts as skipped. For a window that closes
     * or a game that no longer wants the rest.
     */
    public function cancel(string $reason = 'cancelled'): void
    {
        if ($this->finished()) {
            return;
        }
        $this->releaseGenerator();
        foreach ($this->tasks as $task) {
            $id = $task->id();
            if ($this->status[$id] === self::PENDING || $this->status[$id] === self::RUNNING) {
                $this->status[$id] = self::SKIPPED;
                if ($this->report[$id]['error'] === null) {
                    $this->report[$id]['error'] = $reason;
                }
            }
        }
        $this->cancelled = true;
        $this->summarise();
    }

    /** The bar, 0..1, never backwards. 1.0 when the work ran to its end. */
    public function fraction(): float
    {
        if ($this->tasks === []) {
            return 1.0;
        }
        if ($this->finished() && !$this->cancelled) {
            return $this->last = 1.0;
        }
        if (!$this->planned || $this->totalWeight <= 0.0) {
            return $this->last;
        }
        $running = 0.0;
        if ($this->gen !== null && isset($this->order[$this->cursor])) {
            $running = $this->tasks[$this->order[$this->cursor]]->weight() * $this->genFraction;
        }
        $raw = ($this->doneWeight + $running) / $this->totalWeight;
        // 1.0 is reserved for "all work ran": rounding must not claim it early.
        return $this->last = max($this->last, min(0.999999, $raw));
    }

    /** The task that runs or is next; null when none is left. */
    public function currentTask(): ?WarmupTask
    {
        if ($this->finished()) {
            return null;
        }
        if (!$this->planned) {
            return $this->tasks[0];
        }
        return $this->tasks[$this->order[$this->cursor]] ?? null;
    }

    /** Label key of {@see currentTask()}; null = none, or the engine's default text. */
    public function labelKey(): ?string
    {
        return $this->currentTask()?->labelKey();
    }

    /**
     * What became of every task: status, seconds spent, the longest single unit (the
     * most it held a frame) and the error or reason of a failure or skip.
     *
     * @return array<string, array{status: string, seconds: float, maxUnit: float, error: ?string}>
     */
    public function results(): array
    {
        $out = [];
        foreach ($this->tasks as $task) {
            $id = $task->id();
            $out[$id] = ['status' => $this->status[$id]] + $this->report[$id];
        }
        return $out;
    }

    /** Fix the order: dependencies first, registration order among the ready. */
    private function plan(): void
    {
        if ($this->planned) {
            return;
        }
        $this->planned = true;

        $n = count($this->tasks);
        $waiting = [];
        $dependents = array_fill(0, $n, []);
        foreach ($this->tasks as $i => $task) {
            $waiting[$i] = 0;
            foreach (array_unique($task->dependsOn()) as $dep) {
                if (!isset($this->index[$dep])) {
                    $this->blocked[$task->id()] ??= "unknown dependency '$dep'";
                    continue;
                }
                $waiting[$i]++;
                $dependents[$this->index[$dep]][] = $i;
            }
        }
        $ready = [];
        foreach ($waiting as $i => $count) {
            if ($count === 0) {
                $ready[] = $i;
            }
        }
        $order = [];
        while ($ready !== []) {
            sort($ready);
            $i = array_shift($ready);
            $order[] = $i;
            foreach ($dependents[$i] as $next) {
                if (--$waiting[$next] === 0) {
                    $ready[] = $next;
                }
            }
        }
        for ($i = 0; $i < $n; $i++) {
            if (!in_array($i, $order, true)) {
                $this->blocked[$this->tasks[$i]->id()] ??= 'dependency cycle';
                $order[] = $i;
            }
        }
        $this->order = $order;
        $this->settle();
    }

    /** One unit: the next task as a whole, or one slice of the running generator. */
    private function unit(): void
    {
        if ($this->gen === null) {
            $task = $this->settle();
            if ($task === null) {
                return;
            }
            $id = $task->id();
            $this->status[$id] = self::RUNNING;
            $t0 = ($this->clock)();
            try {
                $gen = $task->run();
            } catch (\Throwable $e) {
                $this->account($id, $t0);
                $this->fail($task, $e);
                return;
            }
            if ($gen === null) {
                $this->account($id, $t0);
                $this->complete($task);
                return;
            }
            $this->gen = $gen;
            $this->genStarted = false;
            $this->genFraction = 0.0;
            $this->slice($task, $t0);
            return;
        }
        $this->slice($this->tasks[$this->order[$this->cursor]], ($this->clock)());
    }

    private function slice(WarmupTask $task, float $t0): void
    {
        $gen = $this->gen;
        if ($gen === null) {
            return;
        }
        try {
            if ($this->genStarted) {
                $gen->next();
            } else {
                $this->genStarted = true;
                $gen->current();
            }
            $alive = $gen->valid();
            $yielded = $alive ? $gen->current() : null;
        } catch (\Throwable $e) {
            $this->account($task->id(), $t0);
            $this->fail($task, $e);
            return;
        }
        $this->account($task->id(), $t0);
        if (!$alive) {
            $this->complete($task);
            return;
        }
        if ((is_float($yielded) || is_int($yielded)) && !is_nan((float) $yielded)) {
            $this->genFraction = max($this->genFraction, min(1.0, (float) $yielded));
        }
    }

    private function account(string $id, float $t0): void
    {
        $dt = max(0.0, ($this->clock)() - $t0);
        $was = $this->report[$id];
        $this->report[$id] = [
            'seconds' => $was['seconds'] + $dt,
            'maxUnit' => max($was['maxUnit'], $dt),
            'error' => $was['error'],
        ];
    }

    private function complete(WarmupTask $task): void
    {
        $this->status[$task->id()] = self::DONE;
        $this->finishTask($task);
    }

    private function fail(WarmupTask $task, \Throwable $e): void
    {
        $id = $task->id();
        $this->status[$id] = self::FAILED;
        $this->report[$id]['error'] = get_class($e) . ': ' . $e->getMessage();
        $this->say("warmup: task '$id' failed: " . $this->report[$id]['error']);
        $this->finishTask($task);
    }

    private function finishTask(WarmupTask $task): void
    {
        $this->releaseGenerator();
        // Failed and skipped weight counts as passed: the bar must reach its end.
        $this->doneWeight += $task->weight();
        $this->cursor++;
        $this->settle();
    }

    /**
     * Skip what cannot run (blocked, or a dependency did not finish) and return the
     * next task that can, leaving the cursor on it; null when none is left.
     */
    private function settle(): ?WarmupTask
    {
        while (isset($this->order[$this->cursor])) {
            $task = $this->tasks[$this->order[$this->cursor]];
            $id = $task->id();
            $why = $this->blocked[$id] ?? $this->unmetDependency($task);
            if ($why === null) {
                return $task;
            }
            $this->status[$id] = self::SKIPPED;
            $this->report[$id]['error'] = $why;
            $this->say("warmup: task '$id' skipped ($why)");
            $this->doneWeight += $task->weight();
            $this->cursor++;
        }
        return null;
    }

    private function unmetDependency(WarmupTask $task): ?string
    {
        foreach ($task->dependsOn() as $dep) {
            if (($this->status[$dep] ?? null) !== self::DONE) {
                return "dependency '$dep' did not finish";
            }
        }
        return null;
    }

    private function releaseGenerator(): void
    {
        $gen = $this->gen;
        $this->gen = null;
        $this->genStarted = false;
        $this->genFraction = 0.0;
        try {
            unset($gen);
        } catch (\Throwable $e) { // @phpstan-ignore catch.neverThrown (a `finally` block of the task may throw as the generator is destroyed)
            $this->say('warmup: a task cleanup failed: ' . $e->getMessage());
        }
    }

    private function notify(): void
    {
        if ($this->listener === null) {
            return;
        }
        $task = $this->currentTask();
        try {
            ($this->listener)($this->fraction(), $task?->labelKey(), $task?->id());
        } catch (\Throwable $e) {
            $this->listener = null;
            $this->say('warmup: progress listener failed and was dropped: ' . $e->getMessage());
        }
    }

    private function summarise(): void
    {
        if ($this->summarised || !$this->finished() || $this->tasks === []) {
            return;
        }
        $this->summarised = true;
        $count = [self::DONE => 0, self::FAILED => 0, self::SKIPPED => 0];
        $seconds = 0.0;
        $longest = 0.0;
        foreach ($this->tasks as $task) {
            $id = $task->id();
            if (isset($count[$this->status[$id]])) {
                $count[$this->status[$id]]++;
            }
            $seconds += $this->report[$id]['seconds'];
            $longest = max($longest, $this->report[$id]['maxUnit']);
        }
        $this->say(sprintf(
            'warmup: %d done, %d failed, %d skipped in %.3f s (longest unit %.1f ms)',
            $count[self::DONE],
            $count[self::FAILED],
            $count[self::SKIPPED],
            $seconds,
            $longest * 1000.0,
        ));
    }

    private function say(string $line): void
    {
        if ($this->log !== null) {
            ($this->log)($line);
        }
    }
}
