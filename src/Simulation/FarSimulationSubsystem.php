<?php

declare(strict_types=1);

namespace PHPolygon\Simulation;

use PHPolygon\ECS\World;
use PHPolygon\Thread\SubsystemInterface;

/**
 * The {@see \PHPolygon\Thread\SubsystemInterface} bridge {@see ChannelTransport}
 * drives - Phase 6 of the visibility plan, the threaded counterpart of Phase
 * 4's {@see InlineTransport}. Register it on a {@see \PHPolygon\Thread\ThreadScheduler}
 * exactly like any other subsystem (`$scheduler->register($name,
 * FarSimulationSubsystem::class)`); {@see ChannelTransport} then resolves the
 * SAME instance back out via `getSubsystems()` (ThreadScheduler constructs
 * subsystems itself with no constructor arguments, so there is no other way
 * to reach it) and drives it through the normal sendAll()/pollAll() cycle.
 *
 * Carries no ECS object and no live World reference across the channel -
 * only the kernel's class name (a string) and its records (scalar arrays),
 * matching the plan's "keine ECS-Objekte und kein voller Weltzustand über
 * Kanäle". A kernel used here must have a no-argument constructor: the
 * worker thread reconstructs a fresh instance per job from the class name
 * alone, the same way {@see \PHPolygon\Thread\ThreadScheduler::boot()}
 * already instantiates every registered SubsystemInterface with `new
 * $class()`.
 *
 * One instance can carry several queued jobs in the same tick (keyed by an
 * arbitrary caller-chosen job id) - useful if more than one
 * {@see FarSimulationRunner}/kernel shares this subsystem's single worker
 * thread, though {@see ChannelTransport} itself only ever queues one job per
 * step() call.
 */
final class FarSimulationSubsystem implements SubsystemInterface
{
    /**
     * @var array<string, array{kernelClass: class-string<SimulationKernel>, records: array<int, array<string, bool|int|float|string>>, dt: float}>
     */
    private array $pendingJobs = [];

    /** @var array<string, array<int, array<string, bool|int|float|string>>> job id => result records, from the last applyDeltas() */
    private array $results = [];

    /**
     * Queues a step for the next prepareInput() - called from the main
     * thread by {@see ChannelTransport} before it calls
     * `ThreadScheduler::sendAll()`.
     *
     * @param class-string<SimulationKernel> $kernelClass
     * @param array<int, array<string, bool|int|float|string>> $records
     */
    public function queueJob(string $jobId, string $kernelClass, array $records, float $dt): void
    {
        $this->pendingJobs[$jobId] = ['kernelClass' => $kernelClass, 'records' => $records, 'dt' => $dt];
    }

    /**
     * Reads back (and forgets) one job's result after it was applied via
     * applyDeltas() - null if that job never completed (not yet polled, or
     * never queued).
     *
     * @return array<int, array<string, bool|int|float|string>>|null
     */
    public function takeResult(string $jobId): ?array
    {
        $result = $this->results[$jobId] ?? null;
        unset($this->results[$jobId]);
        return $result;
    }

    public function prepareInput(World $world, float $dt): array
    {
        // $world and the fixed-tick $dt are both unused - FarSimulationRunner
        // already resolved its own real dt per job before queueJob(); this
        // subsystem never touches live ECS state at all (step() is pure, by
        // SimulationKernel's own contract).
        $jobs = $this->pendingJobs;
        $this->pendingJobs = [];
        return ['jobs' => $jobs];
    }

    public function applyDeltas(World $world, array $deltas): void
    {
        $jobs = $deltas['jobs'] ?? null;
        if (!is_array($jobs)) {
            return;
        }
        foreach ($jobs as $jobId => $records) {
            if (!is_string($jobId)) {
                continue;
            }
            $map = self::asRecordMap($records);
            if ($map !== null) {
                $this->results[$jobId] = $map;
            }
        }
    }

    public static function compute(array $input): array
    {
        $jobs = $input['jobs'] ?? null;
        $out = [];
        if (is_array($jobs)) {
            foreach ($jobs as $jobId => $job) {
                if (!is_array($job) || !is_string($jobId)) {
                    continue;
                }
                $kernelClass = $job['kernelClass'] ?? null;
                $dt = $job['dt'] ?? null;
                $records = self::asRecordMap($job['records'] ?? null);
                if (!is_string($kernelClass) || !class_exists($kernelClass) || !is_float($dt) || $records === null) {
                    continue;
                }
                /** @var SimulationKernel $kernel */
                $kernel = new $kernelClass();
                // Same determinism contract InlineTransport's own step() uses.
                ksort($records);
                $out[$jobId] = $kernel->step($records, $dt);
            }
        }
        return ['jobs' => $out];
    }

    public static function threadEntry(string $channelPrefix): void
    {
        $in = \parallel\Channel::open("{$channelPrefix}_in");
        $out = \parallel\Channel::open("{$channelPrefix}_out");

        while (true) {
            $input = $in->recv();
            if ($input === null) {
                break;
            }
            if (is_array($input)) {
                /** @var array<string, mixed> $input */
                $out->send(self::compute($input));
            }
        }
    }

    /**
     * Structurally validates and narrows an arbitrary received value to a
     * record map (entityId => scalar-only record) - everything crossing the
     * channel arrives typed `mixed`, and the plan's "nur Skalar-Arrays"
     * requirement is exactly what this checks rather than assumes.
     *
     * @return array<int, array<string, bool|int|float|string>>|null
     */
    private static function asRecordMap(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $id => $record) {
            if (!is_int($id) || !is_array($record)) {
                return null;
            }
            $fields = [];
            foreach ($record as $key => $field) {
                if (!is_string($key) || !(is_bool($field) || is_int($field) || is_float($field) || is_string($field))) {
                    return null;
                }
                $fields[$key] = $field;
            }
            $out[$id] = $fields;
        }
        return $out;
    }
}
