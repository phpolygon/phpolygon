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
            \parallel\Channel::make("{$name}_in", \parallel\Channel::Infinite);
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
    }

    /**
     * Send current world state to all subsystem threads. A subsystem already
     * found dead is skipped (nothing left to read it); one with an unanswered
     * input still outstanding (see class doc, "behind") is skipped too — a
     * worker that is not even done with the last tick's work has no use for
     * another one queued behind it on its `_in` channel.
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
     * Step 3 is required on Windows: `Channel::make(..., Infinite)` registers
     * the channel in `parallel`'s process-global channel table, where it holds
     * a synchronization monitor. Channels that are never closed are torn down
     * in the extension's MSHUTDOWN instead — and on Windows that teardown can
     * block the process from exiting even after all PHP-level shutdown has run
     * to completion. Closing them here empties the table before MSHUTDOWN.
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
