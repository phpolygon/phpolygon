<?php

declare(strict_types=1);

namespace PHPolygon\Thread;

use PHPolygon\ECS\World;
use PHPolygon\Log\Log;
use PHPolygon\Runtime\PerfProfiler;

/**
 * Single-threaded fallback scheduler.
 *
 * Runs all subsystem computation synchronously on the main thread.
 * Used when the parallel extension is not available or threading is disabled.
 *
 * Mirrors {@see ThreadScheduler}'s hardening for API parity (both are used
 * through the `ThreadScheduler|NullThreadScheduler` union in Engine): a
 * subsystem whose compute() throws here has no separate worker thread to die
 * independently, but it is still caught, logged, and marked dead rather than
 * taking the whole synchronous game loop down with it.
 */
class NullThreadScheduler
{
    /** @var array<string, SubsystemInterface> */
    private array $subsystems = [];

    /** @var array<string, array<string, mixed>> Buffered inputs from sendAll */
    private array $pendingInputs = [];

    /** @var array<string, true> Subsystems whose compute() threw; skipped from then on. */
    private array $deadSubsystems = [];

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
     * Prepare inputs and compute deltas synchronously. A subsystem already
     * found dead (see {@see recvAll()}) is skipped.
     *
     * @return array<string, array<string, mixed>>
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
            }
            $this->pendingInputs = $inputs;
            return $inputs;
        });
    }

    /**
     * Compute and apply deltas synchronously. Delegates to {@see pollAll()}
     * so the two schedulers share the same dead-subsystem bookkeeping; the
     * return value is not needed on the entry point the game loop calls.
     */
    public function recvAll(World $world): void
    {
        $this->pollAll($world);
    }

    /**
     * Synchronous counterpart to {@see ThreadScheduler::pollAll()}: there is
     * no worker thread and no bounded wait here (everything already ran by
     * the time this returns), so $timeoutMicros is accepted for interface
     * parity but unused. A subsystem whose compute() throws is caught,
     * logged once, and marked dead — it is skipped by this and by
     * {@see sendAll()} from then on, instead of the exception propagating
     * out of the game loop.
     */
    public function pollAll(World $world, int $timeoutMicros = ThreadScheduler::DEFAULT_POLL_TIMEOUT_US): bool
    {
        return PerfProfiler::section('thread.scheduler.poll', function () use ($world): bool {
            $allReady = true;
            foreach ($this->subsystems as $name => $subsystem) {
                if (isset($this->deadSubsystems[$name])) {
                    continue;
                }
                if (!isset($this->pendingInputs[$name])) {
                    continue;
                }
                $ready = PerfProfiler::section("thread.{$name}.wait", function () use ($world, $name, $subsystem): bool {
                    try {
                        $deltas = $subsystem::compute($this->pendingInputs[$name]);
                    } catch (\Throwable $e) {
                        $this->deadSubsystems[$name] = true;
                        Log::error('thread worker died', ['subsystem' => $name, 'reason' => $e->getMessage()]);
                        return false;
                    }
                    $subsystem->applyDeltas($world, $deltas);
                    return true;
                });
                if (!$ready) {
                    $allReady = false;
                }
            }
            $this->pendingInputs = [];
            return $allReady;
        });
    }

    /** Whether $name's subsystem is still being computed (false once its compute() has thrown, or if unknown). */
    public function isSubsystemAlive(string $name): bool
    {
        return isset($this->subsystems[$name]) && !isset($this->deadSubsystems[$name]);
    }

    /**
     * Names of subsystems whose compute() has thrown. Diagnostics/tests.
     *
     * @return list<string>
     */
    public function getDeadSubsystems(): array
    {
        return array_keys($this->deadSubsystems);
    }

    public function shutdown(): void
    {
        $this->subsystems = [];
        $this->pendingInputs = [];
        $this->deadSubsystems = [];
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
