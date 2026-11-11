<?php

declare(strict_types=1);

namespace PHPolygon\Thread;

use PHPolygon\ECS\World;
use PHPolygon\Log\Log;
use PHPolygon\Log\LogCategory;
use PHPolygon\Log\LogLevel;
use PHPolygon\Runtime\PerfProfiler;

/**
 * Manages parallel Runtime instances and Channel pairs for threaded subsystems.
 *
 * Uses named Channels (Channel::make/Channel::open) to avoid passing Channel objects
 * as closure parameters, which crashes on macOS ARM.
 *
 * Hardening (dead workers, bounded waits, no lockstep hang): the sentence "a
 * tick is a round trip" used to mean sendAll() always queued a fresh input and
 * recvAll() always blocked on Channel::recv() until an answer arrived — no
 * matter how long that took, or whether anything was ever going to answer.
 * Now:
 *   - {@see boot()} keeps the {@see \parallel\Future} from `Runtime::run()`
 *     instead of discarding it — a worker that throws or returns makes its
 *     Future `done()`, which is the only way to tell it apart from one still
 *     running its receive loop.
 *   - {@see pollOne()} waits through a {@see \parallel\Events} instance that
 *     watches both the subsystem's out-channel and its Future, bounded by a
 *     timeout or not blocking at all (0).
 *   - A subsystem found dead (Future resolved, with or without an exception)
 *     is marked and skipped by {@see sendAll()}/{@see pollAll()}/{@see recvAll()}
 *     from then on.
 *   - A subsystem that is merely slow is never confused with a dead one: a
 *     missed deadline puts it "behind" (tracked per subsystem), which stops
 *     sendAll() from queueing more input onto its `_in` channel while an
 *     answer is still outstanding. {@see recvAll()} — the method the game loop
 *     actually calls every tick — additionally switches to a cheap
 *     non-blocking check for a behind subsystem on every later tick, instead
 *     of paying its bound again and again; {@see pollAll()} always honours
 *     whatever bound its caller explicitly asks for, behind or not, which is
 *     what makes it the right tool to deliberately wait out a known-slow
 *     subsystem's delayed answer. The first missed deadline is logged once;
 *     once the late answer arrives it is applied and the subsystem returns to
 *     being polled with the normal bound.
 *
 * {@see \parallel\Events} itself is not new here: {@see \PHPolygon\Log\ThreadedLogWriter}
 * (`flush()`) already waits on it, blocking with a timeout, and checks the
 * same thread's Future (`write()`, `close()`, `readResult()`) — that pairing
 * has been live on every ZTS platform, including macOS ARM, since the engine
 * log's own thread shipped. What IS new, and not exercised by that code, is
 * the non-blocking variant (`setBlocking(false)`, no timeout — see
 * {@see pollOne()}) used here whenever a subsystem is "behind" under
 * {@see recvAll()}, or a caller passes `timeoutMicros: 0` to {@see pollAll()}.
 * The engine itself registers no subsystem, so this specific path is not
 * reached by a running game yet; a game can reach it through
 * {@see \PHPolygon\Support\Facades\Scheduler} (forwards to whichever scheduler
 * {@see ThreadSchedulerFactory} chose). If non-blocking Events turns out to be
 * unsafe on macOS ARM where the blocking variant is not, the fallback is
 * {@see NullThreadScheduler}, which this class never touches.
 *
 * Unbuffered `_in`, buffered `_out` (fix/unbuffered-scheduler-channels) —
 * read this before assuming it fixes the Phase 6b ~15ms floor in general, it
 * does NOT:
 *
 * {@see boot()}'s `_in` channel is created UNBUFFERED (`Channel::make($name)`,
 * no capacity — capacity `0` throws `TypeError` on this build, "may be -1 for
 * unlimited, or a positive integer"; capacity `1` is still buffered, same as
 * `Infinite`, measured 0.00ms; omitting the argument is the only way to get a
 * true rendezvous `send()`). This DOES remove one specific, narrow cost:
 * measured through the real `sendAll()`/`pollAll()` path, worker always
 * ready, zero compute time, this Windows dev machine, PHP 8.5.5 ZTS,
 * ext-parallel 1.2.13, 2026-11-11, 50-call runs — both channels buffered
 * (the old behaviour) averages ~14.9ms/call; `_in` unbuffered (`_out` left
 * buffered) averages ~7.6ms/call. That is a real, roughly 2x improvement,
 * but for a worker that does ANY real work at all it DISAPPEARS: the same
 * benchmark with the worker spending 1ms or 5ms (CPU-bound, not `usleep()` —
 * see below) before replying measures ~15.1ms/call both before AND after
 * this change — no improvement whatsoever. A standalone probe isolating why
 * (same Windows machine, same date) found the actual floor: a plain blocking
 * `\parallel\Channel::recv()` waiting on a worker busy for 1ms costs ~1.0ms
 * total (no added latency at all, regardless of `_in`'s buffering), while
 * `\parallel\Events::poll()` — what {@see pollOne()} actually uses, for its
 * dead-worker detection — costs ~15ms for the SAME wait, again regardless of
 * buffering. The ~15ms floor lives inside `Events::poll()`'s own blocking
 * wait, not in channel buffering; {@see pollOne()}'s `addFuture()` call adds
 * a further, independent ~15ms chance roughly half the time even when
 * nothing is being waited for (this matches Phase 6b's own secondary,
 * unchased finding, and explains the ~7.6ms *average* above: an even split
 * between calls that pay this and calls that don't). Phase 6b's own report
 * attributed the whole ~15ms to channel buffering; this measurement found
 * that attribution incomplete for anything but a zero-compute worker. The
 * real fix would have to replace `Events::poll()`'s blocking wait (or route
 * around `addFuture()`) for the common case, not change channel capacities —
 * that is NOT done here; this change is kept only for the real, if narrow,
 * win it measurably delivers (an idle/near-idle subsystem, and the first
 * reply after a tick with nothing to do), with no regression found anywhere
 * else. (Confound avoided in the above: `usleep()` for 1-5ms on this Windows
 * build is itself bound by roughly the same ~15ms default timer granularity,
 * so simulating "compute time" with it would have hidden the real result
 * behind an unrelated Windows timer-resolution artefact; a CPU-bound spin via
 * `hrtime()` does not have this problem and is what both benchmarks above
 * use.)
 *
 * `_out` is deliberately left buffered (`Channel::Infinite`, unchanged) —
 * the measurements above found no latency reason to touch it, and making it
 * unbuffered too introduces a real deadlock with no matching benefit: a
 * worker's `$out->send($result)` would then block until something calls
 * `Events::poll()` for it, and {@see shutdown()} calling `Runtime::close()`
 * while a request is still outstanding (worker mid-compute, nobody left
 * polling — see {@see \PHPolygon\Tests\Thread\ThreadSchedulerHardeningTest::testZeroTimeoutPollIsNonBlockingAndDoesNotMarkAliveWorkerDead()})
 * would then join a thread permanently stuck in that send(), hanging
 * shutdown() forever. With `_out` buffered, the same worker's send() returns
 * immediately, it loops back to its own `recv()`, sees the shutdown sentinel,
 * and exits cleanly — exactly like before this change. This is a deliberate
 * deviation from the original brief ("both channels unbuffered").
 *
 * `send()` on the now-unbuffered `_in` channel has no timeout of its own —
 * ext-parallel does not offer a bounded/cancellable send ({@see
 * \parallel\Events::setInput()} was tried as a candidate and rejected: on
 * this build it either ignored its configured `setTimeout()` and returned as
 * a timeout almost instantly even with a worker already parked in `recv()`,
 * or — combined with `addChannel()` for the same channel — ignored the
 * timeout the other way and never returned at all). {@see sendAll()} and
 * {@see shutdown()} each check the target's {@see \parallel\Future::done()}
 * immediately before sending, plus a short one-time grace period in
 * {@see boot()} ({@see waitOutEarlyFailures()}), to avoid blocking forever on
 * a worker that will never `recv()` again; see their own doc for exactly what
 * that does and does not cover — it does NOT cover a worker that hangs (not
 * crashes) doing its own setup before its first `recv()`, which now blocks
 * the first `sendAll()` with no bound at all, a new gap this change opens
 * (see the commit report for the full discussion).
 */
class ThreadScheduler
{
    /**
     * Upper bound for a single "in sync" poll of one subsystem's output
     * channel, in microseconds (note: {@see \parallel\Events::setTimeout()}
     * takes microseconds, not milliseconds — confirmed by measurement, not
     * assumed: see the commit for the probe). Paid at most once per stall
     * episode per subsystem — see class doc — never every tick, so it can
     * afford to be generous: a merely slow worker is never falsely declared
     * dead, while a crashed/hung one no longer blocks the game forever (the
     * bug this hardening fixes).
     */
    public const int DEFAULT_POLL_TIMEOUT_US = 5_000_000;

    /**
     * Upper bound for {@see waitOutEarlyFailures()}'s one-time grace period
     * in {@see boot()}, in microseconds. See that method's own doc for the
     * measurement behind this number and exactly what it narrows (and does
     * not close).
     */
    private const int BOOT_GRACE_PERIOD_US = 10_000;

    /** @var array<string, SubsystemInterface> */
    private array $subsystems = [];

    /** @var array<string, \parallel\Runtime> */
    private array $runtimes = [];

    /**
     * Kept from {@see \parallel\Runtime::run()} instead of discarded — the only
     * way to tell a crashed/returned worker thread apart from one that is still
     * running its receive loop. See class doc. `run()` is typed to possibly
     * return null (no Future could be constructed); when that happens there is
     * nothing to poll for that subsystem, so it is simply never watched for
     * death here — not marked dead outright, which would disable a subsystem
     * that might otherwise work fine on a build where this is routine.
     *
     * @var array<string, \parallel\Future|null>
     */
    private array $futures = [];

    /** @var array<string, true> Subsystems whose worker is known gone; sendAll()/pollAll() skip them. */
    private array $deadSubsystems = [];

    /** @var array<string, true> Subsystems with an input sent and no matching output applied yet. */
    private array $outstanding = [];

    /** @var array<string, true> Subsystems that missed their bounded poll at least once; polled non-blocking until they catch up. */
    private array $behind = [];

    /** @var array<string, true> Subsystems already logged as stalled this episode, so the warning fires once, not every tick. */
    private array $stallLogged = [];

    private bool $booted = false;

    public function __construct(
        private readonly int $coreCount,
    ) {}

    /**
     * Register a subsystem for threaded execution.
     *
     * @param class-string<SubsystemInterface> $subsystemClass
     */
    public function register(string $name, string $subsystemClass): void
    {
        $this->subsystems[$name] = new $subsystemClass();
    }

    /**
     * Spawn Runtime instances and create named Channel pairs for each subsystem.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->subsystems as $name => $subsystem) {
            // `_in` is UNBUFFERED on purpose (no capacity argument — confirmed by
            // measurement to be the only way to get a true rendezvous: capacity 0
            // throws TypeError on this build, "may be -1 for unlimited, or a
            // positive integer"; capacity 1 is still buffered, send() returning
            // before the worker's recv() - measured 0.00ms, same as Infinite).
            // See class doc, "Unbuffered `_in`, buffered `_out`" for the full
            // reasoning and the measurements behind this specific pairing.
            \parallel\Channel::make("{$name}_in");
            \parallel\Channel::make("{$name}_out", \parallel\Channel::Infinite);

            $className = get_class($subsystem);
            // A parallel Runtime starts a fresh thread with NO autoloader, so it
            // can only resolve $class::threadEntry() if bootstrapped with the
            // active Composer autoloader. Without it, get_class() names a class the
            // worker thread cannot load (fatal).
            $bootstrap = ParallelCapability::autoloadBootstrap();
            $runtime = $bootstrap !== null
                ? new \parallel\Runtime($bootstrap)
                : new \parallel\Runtime();
            // The Future is kept, not discarded: it is the only signal that
            // tells a worker that crashed or returned (done() becomes true)
            // apart from one still running its receive loop.
            $this->futures[$name] = $runtime->run(static function (string $class, string $prefix): void {
                $class::threadEntry($prefix);
            }, [$className, $name]);

            $this->runtimes[$name] = $runtime;
        }

        $this->booted = true;
        $this->waitOutEarlyFailures();
    }

    /**
     * A short, bounded, one-time grace period right after spawning every
     * worker, narrowing (not closing — see {@see sendAll()}'s own doc on why
     * it cannot be closed) the race where a worker that will NEVER reach its
     * own `recv()` — crashed, or returned cleanly without ever opening a
     * channel, like the `CrashingSubsystem`/`ReturningSubsystem` test
     * fixtures — has not resolved its {@see \parallel\Future} yet by the time
     * the very first {@see sendAll()} call checks it, which would otherwise
     * send into the unbuffered `_in` channel — a rendezvous nobody will ever
     * complete.
     *
     * Measured (this Windows dev machine, PHP 8.5.5 ZTS, ext-parallel 1.2.13,
     * 2026-11-11): a worker dispatched exactly like {@see boot()} does
     * (Composer autoload bootstrap + `$class::threadEntry($prefix)`) that
     * returns immediately, 30 trials: min 0.566ms, p90 0.645ms, max 0.677ms
     * from `run()` returning to `done()` becoming true. Without any grace
     * period at all, the real `ThreadSchedulerHardeningTest` — a fresh PHP
     * process, PHPUnit's own overhead already paid, not the isolated loop
     * above — still hit this race in roughly 1 of 10 runs of
     * `testWorkerThatReturnsWithoutThrowingIsDetectedAsDead`, hanging on
     * `sendAll()`'s `send()` (see the commit).
     *
     * Deliberately a tight busy-poll on {@see \parallel\Future::done()}, NOT
     * `usleep()` between checks: measured directly (same machine/date),
     * `usleep()` for even a tiny duration is itself bound by Windows' own
     * ~15ms default timer granularity — a single `usleep(200)` between
     * checks measured this method's own cost at ~30ms for a single HEALTHY
     * subsystem (two tick-rounded sleeps stacking past the intended ~10ms
     * bound before the loop's own deadline check could catch it), not
     * "exits on the first iteration" as an earlier version of this comment
     * claimed. Busy-polling instead measures at exactly the intended
     * {@see self::BOOT_GRACE_PERIOD_US} (~10.0-10.02ms over 15 trials) for a
     * healthy subsystem, and well under 1ms for the actual crash fixtures
     * this method exists for. The cost is CPU time on one core, once per
     * {@see boot()} call (not per tick) — accepted as a flat, one-time tax,
     * paid in full whenever nothing crashes (the common case), not an
     * early-exit optimisation. `boot()` as a whole costs more than this
     * (Runtime/thread spawning has its own, pre-existing, unmeasured-by-this-
     * change cost) — this grace period is the part this change adds.
     */
    private function waitOutEarlyFailures(): void
    {
        $pending = array_keys($this->futures);
        $deadlineNs = hrtime(true) + self::BOOT_GRACE_PERIOD_US * 1_000;

        while ($pending !== [] && hrtime(true) < $deadlineNs) {
            foreach ($pending as $index => $name) {
                $future = $this->futures[$name];
                if ($future !== null && $future->done()) {
                    $this->markDead($name, null);
                    unset($pending[$index]);
                }
            }
        }
    }

    /**
     * Send current world state to all subsystem threads. A subsystem already
     * found dead is skipped (nothing left to read it); one with an unanswered
     * input still outstanding (see class doc, "behind") is skipped too — a
     * worker that is not even done with the last tick's work has no use for
     * another one queued behind it on its `_in` channel.
     *
     * `_in` being unbuffered (see {@see boot()}) makes `$channel->send($input)`
     * below a rendezvous: it blocks until the worker's own `recv()` takes it,
     * and {@see \parallel\Channel}'s `send()` takes no timeout — ext-parallel
     * has no API for a bounded/cancellable send (confirmed: capacity 0 is
     * rejected, and `\parallel\Events::setInput()` — the only other candidate
     * — ignored its own `setTimeout()` and returned instantly as if nothing
     * were listening even when a worker WAS already blocked in `recv()`, on
     * this build; not used here). A worker that will NEVER call `recv()`
     * again — crashed or returned, whether before its very first `recv()` or
     * between two ticks — would therefore block this call forever. The
     * `done()` check right before `send()` closes the common case (a worker
     * that already crashed by the time this runs); it is a plain boolean read,
     * not a wait, so it adds no new round trip. It does NOT close the race
     * where a worker dies in the instant between this check and the `send()`
     * call themselves — ext-parallel offers nothing to close that window from
     * here. This IS reachable in practice, not just in theory: the very
     * first version of this check, with no grace period in {@see boot()} at
     * all, hung `testWorkerThatReturnsWithoutThrowingIsDetectedAsDead()`
     * (ReturningSubsystem — returns as its very first statement, no sleep
     * before the test's own `sendAll()`) in roughly 1 of 10 runs — measured,
     * not assumed; see the commit. {@see boot()}'s {@see waitOutEarlyFailures()}
     * exists specifically to narrow that window before it ever reaches this
     * check; even with it, a worker whose own setup hangs (not crashes)
     * before its first `recv()` is not caught by either and blocks this call
     * with no bound — an open risk, not fixed here (see the commit report).
     *
     * @return array<string, array<string, mixed>> Prepared inputs keyed by name (for testing/debugging)
     */
    public function sendAll(World $world, float $dt): array
    {
        return PerfProfiler::section('thread.scheduler.send', function () use ($world, $dt): array {
            $inputs = [];
            foreach ($this->subsystems as $name => $subsystem) {
                if (isset($this->deadSubsystems[$name]) || isset($this->outstanding[$name])) {
                    continue;
                }
                if (isset($this->futures[$name]) && $this->futures[$name]->done()) {
                    // Found dead before ever sending to it — see this method's
                    // own doc. Skips the rendezvous send() entirely instead of
                    // blocking on a channel nobody will ever recv() from.
                    $this->markDead($name, null);
                    continue;
                }
                $input = $subsystem->prepareInput($world, $dt);
                $inputs[$name] = $input;
                $channel = \parallel\Channel::open("{$name}_in");
                $channel->send($input);
                $this->outstanding[$name] = true;
            }
            return $inputs;
        });
    }

    /**
     * Receive from all subsystem threads and apply deltas to World — the
     * existing entry point the game loop calls every tick. Unlike
     * {@see pollAll()} (which always honours the bound its caller explicitly
     * asked for), this chooses the bound itself per subsystem: up to
     * {@see DEFAULT_POLL_TIMEOUT_US} for one currently "in sync", but a cheap
     * non-blocking check for one already "behind" — so a single slow or hung
     * worker never costs a tick more than once, no matter how many ticks it
     * stays behind for.
     */
    public function recvAll(World $world): void
    {
        PerfProfiler::section('thread.scheduler.poll', function () use ($world): void {
            foreach ($this->subsystems as $name => $subsystem) {
                if (isset($this->deadSubsystems[$name]) || !isset($this->outstanding[$name])) {
                    continue;
                }
                $bound = isset($this->behind[$name]) ? 0 : self::DEFAULT_POLL_TIMEOUT_US;
                $this->pollAndTrack($world, $name, $subsystem, $bound);
            }
        });
    }

    /**
     * Wait for each live, outstanding subsystem's output, each given exactly
     * $timeoutMicros — unlike {@see recvAll()}, a subsystem already "behind"
     * is not silently downgraded to non-blocking here: the caller asked for
     * $timeoutMicros and gets it, which is what makes this the right method
     * to explicitly wait out a known-slow subsystem's delayed answer (0 for
     * a genuinely non-blocking check instead). Returns true only if every
     * outstanding subsystem delivered this call.
     *
     * A subsystem whose worker died (its Future resolved with an exception, or
     * returned at all outside shutdown()) is detected here, logged once via
     * {@see Log}, and added to {@see getDeadSubsystems()} — from then on it is
     * skipped by both this method and {@see sendAll()}, so the game keeps
     * running without it rather than hanging on a channel nobody will ever
     * write to again.
     */
    public function pollAll(World $world, int $timeoutMicros = self::DEFAULT_POLL_TIMEOUT_US): bool
    {
        return PerfProfiler::section('thread.scheduler.poll', function () use ($world, $timeoutMicros): bool {
            $allReady = true;
            foreach ($this->subsystems as $name => $subsystem) {
                if (isset($this->deadSubsystems[$name]) || !isset($this->outstanding[$name])) {
                    continue;
                }
                if (!$this->pollAndTrack($world, $name, $subsystem, $timeoutMicros)) {
                    $allReady = false;
                }
            }
            return $allReady;
        });
    }

    /**
     * Shared per-subsystem poll + bookkeeping between {@see recvAll()} and
     * {@see pollAll()}: waits via {@see pollOne()}, then either clears
     * outstanding/behind on success or (for a subsystem not found dead) logs
     * the first missed deadline of a stall episode and marks it "behind".
     */
    private function pollAndTrack(World $world, string $name, SubsystemInterface $subsystem, int $timeoutMicros): bool
    {
        $ready = PerfProfiler::section(
            "thread.{$name}.wait",
            fn (): bool => $this->pollOne($world, $name, $subsystem, $timeoutMicros),
        );
        if ($ready) {
            unset($this->outstanding[$name], $this->behind[$name], $this->stallLogged[$name]);
            return true;
        }
        if (!isset($this->deadSubsystems[$name]) && !isset($this->behind[$name])) {
            $this->behind[$name] = true;
            $this->stallLogged[$name] = true;
            Log::warning('thread worker stalled', ['subsystem' => $name, 'timeout_us' => $timeoutMicros]);
        }
        return false;
    }

    /**
     * Wait for one subsystem's output, bounded by $timeoutMicros (0 = do not
     * block at all — {@see \parallel\Events::setTimeout()} cannot be combined
     * with non-blocking mode, confirmed by measurement). Returns true and
     * applies the deltas when the channel delivers in time; returns false
     * when the bound expires (worker still alive, just slow this tick) or the
     * worker is found dead (see {@see markDead()}).
     */
    private function pollOne(World $world, string $name, SubsystemInterface $subsystem, int $timeoutMicros): bool
    {
        $events = new \parallel\Events();
        if ($timeoutMicros > 0) {
            $events->setBlocking(true);
            // setTimeout() is microseconds, confirmed by measurement against
            // the installed parallel extension — not documented clearly upstream.
            $events->setTimeout($timeoutMicros);
        } else {
            $events->setBlocking(false);
        }
        $events->addChannel(\parallel\Channel::open("{$name}_out"));
        if (isset($this->futures[$name])) {
            // Registering the Future alongside the channel means a worker
            // that crashes mid-wait is reported immediately (an event whose
            // source is $name, not "{$name}_out" — see below), instead of
            // only being discovered once the whole timeout elapses.
            $events->addFuture($name, $this->futures[$name]);
        }

        try {
            $event = $events->poll();
        } catch (\parallel\Events\Error\Timeout) {
            $event = null;
        }

        if ($event === null) {
            // Nothing arrived inside the bound (or, non-blocking, right now).
            // The worker may simply be slower than this tick's budget —
            // confirm it is not actually dead before giving up on it for good.
            if (isset($this->futures[$name]) && $this->futures[$name]->done()) {
                $this->markDead($name, null);
            }
            return false;
        }

        // A Future that completes WITHOUT throwing fires the same Read type
        // as a channel delivery (confirmed by measurement) — its event's
        // source is the name passed to addFuture() ($name itself), while a
        // real delivery's source is the channel's own name ("{$name}_out").
        // Only the latter is an actual result; anything else means the
        // worker ended (crashed or returned) without ever sending one.
        if ($event->type === \parallel\Events\Event\Type::Read && $event->source === "{$name}_out") {
            $deltas = $event->value;
            if (is_array($deltas)) {
                /** @var array<string, mixed> $deltas */
                $subsystem->applyDeltas($world, $this->forwardSideChannel($name, $deltas));
            }
            return true;
        }

        $reason = $event->value instanceof \Throwable ? $event->value : null;
        $this->markDead($name, $reason);
        return false;
    }

    /**
     * Strips the optional `__log` / `__timings` side-channel keys a
     * subsystem's compute() may add to its deltas (see {@see SubsystemInterface}
     * for the contract) and forwards them — log entries to {@see Log}, timings
     * to {@see PerfProfiler} as `thread.<name>.<key>` — before the rest of the
     * deltas reach applyDeltas(). Both reserved keys are optional; a subsystem
     * that never uses them is unaffected.
     *
     * @param array<string, mixed> $deltas
     * @return array<string, mixed>
     */
    private function forwardSideChannel(string $name, array $deltas): array
    {
        $log = $deltas['__log'] ?? null;
        if (is_array($log)) {
            foreach ($log as $entry) {
                if (!is_array($entry) || !is_string($entry['text'] ?? null)) {
                    continue;
                }
                $level = match ($entry['level'] ?? null) {
                    'warn' => LogLevel::Warn,
                    'error' => LogLevel::Error,
                    default => LogLevel::Info,
                };
                $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];
                $context['subsystem'] = $name;
                Log::write(LogCategory::System, $entry['text'], $context, $level);
            }
            unset($deltas['__log']);
        }

        $timings = $deltas['__timings'] ?? null;
        if (is_array($timings)) {
            foreach ($timings as $section => $elapsedNs) {
                if (is_string($section) && is_int($elapsedNs)) {
                    PerfProfiler::record("thread.{$name}.{$section}", $elapsedNs);
                }
            }
            unset($deltas['__timings']);
        }

        return $deltas;
    }

    /**
     * Record a subsystem's worker as gone: logged once, then skipped by
     * {@see sendAll()} and {@see pollAll()} for the rest of this boot().
     */
    private function markDead(string $name, ?\Throwable $reason): void
    {
        if (isset($this->deadSubsystems[$name])) {
            return;
        }
        $this->deadSubsystems[$name] = true;
        unset($this->outstanding[$name], $this->behind[$name], $this->stallLogged[$name]);
        Log::error('thread worker died', [
            'subsystem' => $name,
            'reason' => $reason?->getMessage() ?? 'worker thread exited without a result',
        ]);
    }

    /** Whether $name's worker is still running (false once {@see markDead()} ran, or if unknown). */
    public function isSubsystemAlive(string $name): bool
    {
        return isset($this->subsystems[$name]) && !isset($this->deadSubsystems[$name]);
    }

    /**
     * Names of subsystems whose worker thread has died. Diagnostics/tests —
     * the scheduler itself only needs the internal map.
     *
     * @return list<string>
     */
    public function getDeadSubsystems(): array
    {
        return array_keys($this->deadSubsystems);
    }

    /**
     * Send shutdown signal to all threads and close runtimes.
     *
     * Order matters for a clean process exit:
     *   1. Send the `null` sentinel so each worker loop breaks and returns.
     *   2. Close the Runtimes (joins the underlying threads).
     *   3. Close the named Channels.
     *
     * Step 3 is required on Windows: `Channel::make()` registers the channel —
     * by name, regardless of its capacity (Infinite, unbuffered, or anything
     * else) — in `parallel`'s process-global channel table, where it holds a
     * synchronization monitor. Channels that are never closed are torn down
     * in the extension's MSHUTDOWN instead — and on Windows that teardown can
     * block the process from exiting even after all PHP-level shutdown has run
     * to completion. Closing them here empties the table before MSHUTDOWN.
     *
     * Step 1's `send(null)` has the same rendezvous risk on `_in` that
     * {@see sendAll()} documents on its own `send()` call, for the same reason
     * (unbuffered, no send timeout in ext-parallel) — a subsystem already
     * known dead, or found dead right here via the same `done()` check, is
     * skipped instead of sent to, so shutdown() never blocks on a channel
     * nobody is listening on anymore. A subsystem that is merely busy (still
     * running its last compute(), not back at `recv()` yet) is NOT skipped:
     * send(null) simply waits out its remaining work, same as the open risk
     * below already accepts for `Runtime::close()`.
     *
     * Open risk, not fixed here: `Runtime::close()` joins its thread, so a
     * worker that is hung rather than dead (no exception, Future not done,
     * never checking its input channel for the shutdown sentinel) still makes
     * this call block. Detecting "hung" as a third state distinct from "dead"
     * and "slow" is future work, not part of this hardening.
     */
    public function shutdown(): void
    {
        foreach (array_keys($this->subsystems) as $name) {
            if (isset($this->deadSubsystems[$name])) {
                continue;
            }
            if (isset($this->futures[$name]) && $this->futures[$name]->done()) {
                // Found dead only now (never polled before shutdown was
                // called) — see this method's own doc.
                continue;
            }
            try {
                $channel = \parallel\Channel::open("{$name}_in");
                $channel->send(null);
            } catch (\parallel\Channel\Error\Closed) {
                // Channel already closed — thread exited
            }
        }

        foreach ($this->runtimes as $runtime) {
            try {
                $runtime->close();
            } catch (\parallel\Runtime\Error\Closed) {
                // Already closed
            }
        }

        // Explicitly close the named Channels so parallel's global channel
        // table is empty before the extension's MSHUTDOWN runs. Both the `_in`
        // and `_out` channels are opened by name and closed; a channel that was
        // already reclaimed (e.g. by a crashed worker) throws Closed, which we
        // ignore. close() is idempotent-safe here because each name is closed
        // exactly once per shutdown().
        foreach (array_keys($this->subsystems) as $name) {
            foreach (["{$name}_in", "{$name}_out"] as $channelName) {
                try {
                    \parallel\Channel::open($channelName)->close();
                } catch (\parallel\Channel\Error\Closed) {
                    // Already closed — nothing to do
                } catch (\parallel\Channel\Error\Existence) {
                    // Never created / already reclaimed — nothing to do
                }
            }
        }

        $this->runtimes = [];
        // Cleared so a reboot (same instance, same subsystem names) starts
        // from a clean slate rather than carrying over a previous life's
        // dead-worker/stall/outstanding markers or stale Future handles.
        $this->futures = [];
        $this->deadSubsystems = [];
        $this->outstanding = [];
        $this->behind = [];
        $this->stallLogged = [];
        $this->booted = false;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    public function getCoreCount(): int
    {
        return $this->coreCount;
    }

    /**
     * @return array<string, SubsystemInterface>
     */
    public function getSubsystems(): array
    {
        return $this->subsystems;
    }
}
