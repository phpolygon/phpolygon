<?php

declare(strict_types=1);

namespace PHPolygon\Thread;

use PHPolygon\ECS\World;
use PHPolygon\Log\Log;
use PHPolygon\Log\LogCategory;
use PHPolygon\Log\LogLevel;
use PHPolygon\Runtime\PerfProfiler;

/**
 * Single-threaded fallback scheduler.
 *
 * Runs all subsystem computation synchronously on the main thread.
 * Used when the parallel extension is not available or threading is disabled.
 *
 * Deliberately NOT a mirror of {@see ThreadScheduler}'s dead-worker handling:
 * there is no separate worker thread here to die independently of the game
 * loop, so a subsystem whose compute() throws behaves exactly as before this
 * hardening — the exception propagates. `pollAll()`, `isSubsystemAlive()` and
 * `getDeadSubsystems()` exist only so code written against the
 * `ThreadScheduler|NullThreadScheduler` union (as {@see \PHPolygon\Engine}
 * does) can call either without an instanceof check; under this scheduler
 * every registered subsystem is always "alive" and the dead list is always
 * empty. In practice Engine only pipelines through sendAll()/recvAll() when
 * the scheduler is a real {@see ThreadScheduler} with subsystems registered,
 * so this class's own send/recv do not run during a game at all today.
 */
class NullThreadScheduler
{
    /** @var array<string, SubsystemInterface> */
    private array $subsystems = [];

    /** @var array<string, array<string, mixed>> Buffered inputs from sendAll */
    private array $pendingInputs = [];

    /**
     * @param class-string<SubsystemInterface> $subsystemClass
     */
    public function register(string $name, string $subsystemClass): void
    {
        $this->subsystems[$name] = new $subsystemClass();
    }

    public function boot(): void
    {
        // No-op — nothing to spawn in single-threaded mode
    }

    /**
     * Prepare inputs synchronously.
     *
     * @return array<string, array<string, mixed>>
     */
    public function sendAll(World $world, float $dt): array
    {
        return PerfProfiler::section('thread.scheduler.send', function () use ($world, $dt): array {
            $inputs = [];
            foreach ($this->subsystems as $name => $subsystem) {
                $input = $subsystem->prepareInput($world, $dt);
                $inputs[$name] = $input;
            }
            $this->pendingInputs = $inputs;
            return $inputs;
        });
    }

    /**
     * Compute and apply deltas synchronously. Delegates to {@see pollAll()}
     * for the `thread.*` profiler sections and the `__log`/`__timings`
     * side-channel forwarding described on {@see SubsystemInterface}; the
     * return value is not needed on the entry point the game loop calls.
     */
    public function recvAll(World $world): void
    {
        $this->pollAll($world);
    }

    /**
     * Synchronous counterpart to {@see ThreadScheduler::pollAll()}: everything
     * has already run by the time this returns (there is no thread to wait
     * on), so $timeoutMicros is accepted only for interface parity and is
     * unused, and this always returns true. A subsystem's compute() throwing
     * is NOT caught here — see class doc.
     */
    public function pollAll(World $world, int $timeoutMicros = ThreadScheduler::DEFAULT_POLL_TIMEOUT_US): bool
    {
        return PerfProfiler::section('thread.scheduler.poll', function () use ($world): bool {
            foreach ($this->subsystems as $name => $subsystem) {
                if (!isset($this->pendingInputs[$name])) {
                    continue;
                }
                PerfProfiler::section("thread.{$name}.wait", function () use ($world, $name, $subsystem): void {
                    $deltas = $subsystem::compute($this->pendingInputs[$name]);
                    $subsystem->applyDeltas($world, $this->forwardSideChannel($name, $deltas));
                });
            }
            $this->pendingInputs = [];
            return true;
        });
    }

    /**
     * Strips the optional `__log` / `__timings` side-channel keys a
     * subsystem's compute() may add to its deltas (see {@see SubsystemInterface})
     * and forwards them — log entries to {@see Log}, timings to
     * {@see PerfProfiler} as `thread.<name>.<key>` — before the rest of the
     * deltas reach applyDeltas(). Mirrors {@see ThreadScheduler}'s identically
     * named method so both schedulers honour the same contract.
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

    /** Always true for a registered name — see class doc. */
    public function isSubsystemAlive(string $name): bool
    {
        return isset($this->subsystems[$name]);
    }

    /** Always empty — see class doc.
     *
     * @return list<string>
     */
    public function getDeadSubsystems(): array
    {
        return [];
    }

    public function shutdown(): void
    {
        $this->subsystems = [];
        $this->pendingInputs = [];
    }

    public function isBooted(): bool
    {
        return true; // Always "ready" in synchronous mode
    }

    public function getCoreCount(): int
    {
        return 1;
    }

    /**
     * @return array<string, SubsystemInterface>
     */
    public function getSubsystems(): array
    {
        return $this->subsystems;
    }
}
