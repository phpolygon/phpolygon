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
            // A parallel Runtime starts a fresh thread with NO autoloader, so it
            // can only resolve $class::threadEntry() if bootstrapped with the
            // active Composer autoloader. Without it, get_class() names a class the
            // worker thread cannot load (fatal). Resolve the loaded autoloader's
            // path so this works both standalone and as a Composer dependency.
            $bootstrap = self::autoloadBootstrap();
            $runtime = $bootstrap !== null
                ? new \parallel\Runtime($bootstrap)
                : new \parallel\Runtime();
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
        $this->booted = false;
    }

    /**
     * Path to the active Composer autoloader, used to bootstrap worker Runtimes
     * so they can autoload subsystem classes. Resolved from the loaded
     * {@see \Composer\Autoload\ClassLoader} (vendor/composer/ClassLoader.php →
     * vendor/autoload.php), so it points at the real vendor dir whether the
     * engine runs standalone or as a dependency. Null if no Composer autoloader
     * is present (e.g. a bundled PHAR with a custom loader).
     */
    private static function autoloadBootstrap(): ?string
    {
        if (!class_exists(\Composer\Autoload\ClassLoader::class, false)) {
            return null;
        }

        $file = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();
        if ($file === false) {
            return null;
        }

        $autoload = dirname($file, 2) . '/autoload.php';

        return is_file($autoload) ? $autoload : null;
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
