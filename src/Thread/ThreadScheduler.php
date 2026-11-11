<?php

declare(strict_types=1);

namespace PHPolygon\Thread;

use PHPolygon\ECS\World;
use PHPolygon\Log\Log;
use PHPolygon\Runtime\PerfProfiler;

/**
 * Manages parallel Runtime instances and Channel pairs for threaded subsystems.
 *
 * Uses named Channels (Channel::make/Channel::open) to avoid passing Channel objects
 * as closure parameters, which crashes on macOS ARM.
 *
 * Hardening (dead workers, bounded waits): {@see pollAll()} never blocks forever.
 * Each subsystem's output is awaited through a {@see \parallel\Events} instance that
 * watches both the named out-channel and the worker's {@see \parallel\Future} (kept
 * from {@see boot()} instead of discarded — a worker that throws or returns makes its
 * Future `done()`, which is how a dead worker is told apart from one that is merely
 * slow). A subsystem found dead is marked and skipped on every later tick: no more
 * sends, no more waits, and the rest of the frame runs without it rather than hanging.
 *
 * Not tested on macOS ARM: {@see \parallel\Events} non-blocking receive is used here
 * (see class doc above on Channel objects as closure params crashing there — a related
 * but distinct concern). If Events itself turns out to be unsafe on that platform, the
 * fallback is {@see NullThreadScheduler} via {@see ThreadSchedulerFactory}, which this
 * class never touches.
 */
class ThreadScheduler
{
    /**
     * Upper bound for a single poll of one subsystem's output channel, in
     * microseconds (note: {@see \parallel\Events::setTimeout()} takes
     * microseconds, not milliseconds — confirmed by measurement, not assumed).
     * Generous relative to a frame so a merely slow worker is never falsely
     * declared dead, but finite so a crashed/hung one no longer blocks the
     * game forever (the bug this hardening fixes).
     */
    public const int DEFAULT_POLL_TIMEOUT_US = 5_000_000;

    /** @var array<string, SubsystemInterface> */
    private array $subsystems = [];

    /** @var array<string, \parallel\Runtime> */
    private array $runtimes = [];

    /**
     * Kept from {@see \parallel\Runtime::run()} instead of discarded — the only
     * way to tell a crashed/returned worker thread apart from one that is still
     * running its receive loop. See class doc. `run()` itself is typed to
     * possibly return null (no Future could be constructed, e.g. the Runtime
     * could not spawn its thread); {@see boot()} treats that case as an
     * immediately dead worker.
     *
     * @var array<string, \parallel\Future|null>
     */
    private array $futures = [];

    /** @var array<string, true> Subsystems whose worker is known gone; sendAll()/pollAll() skip them. */
    private array $deadSubsystems = [];

    /** @var array<string, true> Subsystems currently mid-stall, so the warning is logged once per episode, not every tick. */
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
            $future = $runtime->run(static function (string $class, string $prefix): void {
                $class::threadEntry($prefix);
            }, [$className, $name]);
            $this->futures[$name] = $future;

            $this->runtimes[$name] = $runtime;

            if ($future === null) {
                // No Future at all means no thread was actually spawned —
                // there is nothing to poll or wait on, so there is no point
                // pretending the worker exists.
                $this->markDead($name, null);
            }
        }

        $this->booted = true;
    }

    /**
     * Send current world state to all subsystem threads. A subsystem already
     * found dead (see {@see pollAll()}) is skipped — nothing left to read it.
     *
     * @return array<string, array<string, mixed>> Prepared inputs keyed by name (for testing/debugging)
     */
    public function sendAll(World $world, float $dt): array
    {
        return PerfProfiler::section('thread.scheduler.send', function () use ($world, $dt): array {
            $inputs = [];
            foreach ($this->subsystems as $name => $subsystem) {
                if (isset($this->deadSubsystems[$name])) {
                    continue;
                }
                $input = $subsystem->prepareInput($world, $dt);
                $inputs[$name] = $input;
                $channel = \parallel\Channel::open("{$name}_in");
                $channel->send($input);
            }
            return $inputs;
        });
    }

    /**
     * Receive from all subsystem threads and apply deltas to World. Bounded by
     * {@see DEFAULT_POLL_TIMEOUT_US} per subsystem — see {@see pollAll()}, which
     * this delegates to. Kept as a separate, void-returning method because it
     * is the existing entry point the game loop calls every tick.
     */
    public function recvAll(World $world): void
    {
        $this->pollAll($world);
    }

    /**
     * Wait for each live subsystem's output, bounded by $timeoutMicros instead
     * of blocking forever. Returns true only if every subsystem (that was not
     * already dead) delivered within the bound this call.
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
                if (isset($this->deadSubsystems[$name])) {
                    continue;
                }
                $ready = PerfProfiler::section(
                    "thread.{$name}.wait",
                    fn (): bool => $this->pollOne($world, $name, $subsystem, $timeoutMicros),
                );
                if (!$ready) {
                    $allReady = false;
                }
            }
            return $allReady;
        });
    }

    /**
     * Wait for one subsystem's output, bounded by $timeoutMicros. Returns true
     * and applies the deltas when the channel delivers in time; returns false
     * when the bound expires (worker still alive, just slow this tick) or the
     * worker is found dead (see {@see markDead()}).
     */
    private function pollOne(World $world, string $name, SubsystemInterface $subsystem, int $timeoutMicros): bool
    {
        $events = new \parallel\Events();
        $events->setBlocking(true);
        // setTimeout() is microseconds, confirmed by measurement against the
        // installed parallel extension — not documented clearly upstream.
        $events->setTimeout(max(1, $timeoutMicros));
        $events->addChannel(\parallel\Channel::open("{$name}_out"));
        if (isset($this->futures[$name])) {
            // Registering the Future alongside the channel means a worker
            // that crashes mid-wait is reported immediately (an Error event),
            // instead of only being discovered once the whole timeout elapses.
            $events->addFuture($name, $this->futures[$name]);
        }

        try {
            $event = $events->poll();
        } catch (\parallel\Events\Error\Timeout) {
            $event = null;
        }

        if ($event === null) {
            // Nothing arrived inside the bound. The worker may simply be
            // slower than this tick's budget — confirm it is not actually
            // dead before giving up on it for good.
            if (isset($this->futures[$name]) && $this->futures[$name]->done()) {
                $this->markDead($name, null);
                return false;
            }
            if (!isset($this->stallLogged[$name])) {
                $this->stallLogged[$name] = true;
                Log::warning('thread worker stalled', ['subsystem' => $name, 'timeout_us' => $timeoutMicros]);
            }
            return false;
        }

        unset($this->stallLogged[$name]);

        if ($event->type === \parallel\Events\Event\Type::Read) {
            $deltas = $event->value;
            if (is_array($deltas)) {
                /** @var array<string, mixed> $deltas */
                $subsystem->applyDeltas($world, $deltas);
            }
            return true;
        }

        // Any other event type here (Error, Close, ...) came from the Future:
        // the worker ended — crashed or returned — without ever sending.
        $reason = $event->value instanceof \Throwable ? $event->value : null;
        $this->markDead($name, $reason);
        return false;
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
        unset($this->stallLogged[$name]);
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
        // dead-worker/stall markers or stale Future handles.
        $this->futures = [];
        $this->deadSubsystems = [];
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
