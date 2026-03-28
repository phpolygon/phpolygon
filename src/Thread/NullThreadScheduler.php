<?php

declare(strict_types=1);

namespace PHPolygon\Thread;

use PHPolygon\ECS\World;

/**
 * Single-threaded fallback scheduler.
 *
 * Runs all subsystem computation synchronously on the main thread.
 * Used when the parallel extension is not available or threading is disabled.
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
     * Prepare inputs and compute deltas synchronously.
     *
     * @return array<string, array<string, mixed>>
     */
    public function sendAll(World $world, float $dt): array
    {
        $inputs = [];
        foreach ($this->subsystems as $name => $subsystem) {
            $input = $subsystem->prepareInput($world, $dt);
            $inputs[$name] = $input;
        }
        $this->pendingInputs = $inputs;
        return $inputs;
    }

    /**
     * Compute and apply deltas synchronously.
     */
    public function recvAll(World $world): void
    {
        foreach ($this->subsystems as $name => $subsystem) {
            if (!isset($this->pendingInputs[$name])) {
                continue;
            }
            $deltas = $subsystem::compute($this->pendingInputs[$name]);
            $subsystem->applyDeltas($world, $deltas);
        }
        $this->pendingInputs = [];
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
