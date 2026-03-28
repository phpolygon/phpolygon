<?php

declare(strict_types=1);

namespace PHPolygon\Thread;

use PHPolygon\ECS\World;

/**
 * Manages parallel Runtime instances and Channel pairs for threaded subsystems.
 *
 * Uses named Channels (Channel::make/Channel::open) to avoid passing Channel objects
 * as closure parameters, which crashes on macOS ARM.
 */
class ThreadScheduler
{
    /** @var array<string, SubsystemInterface> */
    private array $subsystems = [];

    /** @var array<string, \parallel\Runtime> */
    private array $runtimes = [];

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
            $runtime = new \parallel\Runtime();
            $runtime->run(static function (string $class, string $prefix): void {
                $class::threadEntry($prefix);
            }, [$className, $name]);

            $this->runtimes[$name] = $runtime;
        }

        $this->booted = true;
    }

    /**
     * Send current world state to all subsystem threads.
     *
     * @return array<string, array<string, mixed>> Prepared inputs keyed by name (for testing/debugging)
     */
    public function sendAll(World $world, float $dt): array
    {
        $inputs = [];
        foreach ($this->subsystems as $name => $subsystem) {
            $input = $subsystem->prepareInput($world, $dt);
            $inputs[$name] = $input;
            $channel = \parallel\Channel::open("{$name}_in");
            $channel->send($input);
        }
        return $inputs;
    }

    /**
     * Blocking receive from all subsystem threads. Apply deltas to World.
     */
    public function recvAll(World $world): void
    {
        foreach ($this->subsystems as $name => $subsystem) {
            $channel = \parallel\Channel::open("{$name}_out");
            $deltas = $channel->recv();
            if (is_array($deltas)) {
                /** @var array<string, mixed> $deltas */
                $subsystem->applyDeltas($world, $deltas);
            }
        }
    }

    /**
     * Send shutdown signal to all threads and close runtimes.
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

        $this->runtimes = [];
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
