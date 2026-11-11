<?php

declare(strict_types=1);

namespace PHPolygon\Simulation;

use PHPolygon\ECS\World;
use PHPolygon\Thread\ThreadScheduler;

/**
 * Runs a {@see SimulationKernel}'s step() on a {@see \PHPolygon\Thread\ThreadScheduler}
 * worker thread via {@see FarSimulationSubsystem} - Phase 6 of the
 * visibility plan, the threaded transport Phase 4 built {@see InlineTransport}
 * ahead of. Still SYNCHRONOUS from {@see FarSimulationRunner}'s point of
 * view (step() blocks until the worker answers): this is scaffolding, not
 * an overlap-with-the-main-thread optimisation - see the plan's own line
 * "Lohnt erst mit nennenswerter Fern-Simulation" and this class's own
 * measured section in CLAUDE.md. No system in this engine registers a
 * SimulationKernel yet, so the real benefit (actual overlap, once a kernel
 * exists heavy enough to be worth threading) is unproven, not claimed.
 *
 * REQUIRES a $scheduler with ONLY a {@see FarSimulationSubsystem} registered
 * (already `register()`+`boot()`-ed) - `ThreadScheduler::sendAll()`/
 * `pollAll()` act on every registered subsystem, not a single one by name,
 * so sharing the scheduler with an unrelated subsystem (AI, physics, audio)
 * would send/poll THAT subsystem too on every step() call here. A game that
 * wants both gets its own dedicated ThreadScheduler for far-simulation.
 *
 * Blocking, not non-blocking: uses `pollAll($timeoutMicros)` with
 * $timeoutMicros > 0 by default (ThreadScheduler::DEFAULT_POLL_TIMEOUT_US),
 * the SAME blocking Events path the engine log's own worker thread already
 * runs in production on every ZTS platform including macOS ARM. Passing 0
 * (a genuinely non-blocking poll) switches to the OTHER Events code path
 * ThreadScheduler's own class doc flags as the real open risk there
 * (unexercised by any shipped code) - do not pass 0 unless that has been
 * separately verified safe on the platforms this runs on.
 */
final class ChannelTransport implements SimulationTransport
{
    private readonly FarSimulationSubsystem $subsystem;

    private int $nextJobId = 0;

    public function __construct(
        private readonly ThreadScheduler $scheduler,
        string $subsystemName,
        private readonly World $world,
        private readonly int $timeoutMicros = ThreadScheduler::DEFAULT_POLL_TIMEOUT_US,
    ) {
        $subsystem = $scheduler->getSubsystems()[$subsystemName] ?? null;
        if (!$subsystem instanceof FarSimulationSubsystem) {
            throw new \InvalidArgumentException(
                "ChannelTransport: '{$subsystemName}' is not a registered FarSimulationSubsystem - "
                . 'register() and boot() the scheduler with FarSimulationSubsystem::class under that name first.',
            );
        }
        $this->subsystem = $subsystem;
    }

    public function step(SimulationKernel $kernel, array $records, float $dt): array
    {
        $jobId = 'job-' . $this->nextJobId++;
        $this->subsystem->queueJob($jobId, $kernel::class, $records, $dt);

        // $this->world is never read by FarSimulationSubsystem::prepareInput()
        // - it exists only because ThreadScheduler's sendAll()/pollAll()
        // signatures require a World (most of its OTHER subsystem uses do
        // read live ECS state; this one deliberately does not, per
        // SimulationKernel::step()'s own "no World access" contract).
        $this->scheduler->sendAll($this->world, $dt);
        $delivered = $this->scheduler->pollAll($this->world, $this->timeoutMicros);

        $result = $this->subsystem->takeResult($jobId);
        if ($result === null) {
            throw new \RuntimeException($delivered
                ? "ChannelTransport: worker answered but job '{$jobId}' was not among its results"
                : "ChannelTransport: worker did not answer within {$this->timeoutMicros} microseconds");
        }
        return $result;
    }
}
