<?php

declare(strict_types=1);

namespace PHPolygon\Thread\Subsystem;

use PHPolygon\ECS\World;
use PHPolygon\Thread\SubsystemInterface;

/**
 * Generic worker pool for game-level background parallel work.
 *
 * N workers share a job queue. Main thread enqueues jobs, workers dequeue
 * and process them. Results are collected each frame.
 *
 * This is an engine-level extension point. Game-specific pools (e.g.
 * Netrunner's city ticking, NEXUS runtime) extend or compose this class
 * in the game repository.
 *
 * Job format: ['id' => string, 'type' => string, 'data' => array]
 * Result format: ['id' => string, 'result' => array]
 */
class WorkerPoolSubsystem implements SubsystemInterface
{
    /** @var list<array{id: string, type: string, data: array<string, mixed>}> */
    private array $pendingJobs = [];

    /** @var (callable(array<string, mixed>): array<string, mixed>)|null */
    private static $jobProcessor = null;

    /**
     * Enqueue a job for background processing.
     *
     * @param array<string, mixed> $data
     */
    public function enqueue(string $id, string $type, array $data): void
    {
        $this->pendingJobs[] = ['id' => $id, 'type' => $type, 'data' => $data];
    }

    /**
     * Set the static job processor used by worker threads.
     * Must be set before boot() and must be a static callable (no object captures).
     *
     * @param callable(array<string, mixed>): array<string, mixed> $processor
     */
    public static function setJobProcessor(callable $processor): void
    {
        self::$jobProcessor = $processor;
    }

    public function prepareInput(World $world, float $dt): array
    {
        $jobs = $this->pendingJobs;
        $this->pendingJobs = [];
        return ['jobs' => $jobs];
    }

    public function applyDeltas(World $world, array $deltas): void
    {
        // Game code overrides or hooks into this to process results.
        // Results are available via getResults().
    }

    /**
     * Extract results from deltas for game code consumption.
     *
     * @param array<string, mixed> $deltas
     * @return list<array{id: string, result: array<string, mixed>}>
     */
    public function getResults(array $deltas): array
    {
        /** @var list<array{id: string, result: array<string, mixed>}> */
        return $deltas['results'] ?? [];
    }

    public static function threadEntry(string $channelPrefix): void
    {
        $in = \parallel\Channel::open("{$channelPrefix}_in");
        $out = \parallel\Channel::open("{$channelPrefix}_out");

        while (true) {
            $input = $in->recv();
            if (!is_array($input)) {
                break;
            }
            /** @var array<string, mixed> $input */
            $out->send(self::compute($input));
        }
    }

    public static function compute(array $input): array
    {
        /** @var list<array{id: string, type: string, data: array<string, mixed>}> $jobs */
        $jobs = $input['jobs'] ?? [];
        $results = [];

        foreach ($jobs as $job) {
            if (self::$jobProcessor !== null) {
                $result = (self::$jobProcessor)($job['data']);
            } else {
                $result = self::defaultProcess($job);
            }
            $results[] = ['id' => $job['id'], 'result' => $result];
        }

        return ['results' => $results];
    }

    /**
     * Default job processor — returns input unchanged.
     * Override via setJobProcessor() for game-specific logic.
     *
     * @param array{id: string, type: string, data: array<string, mixed>} $job
     * @return array<string, mixed>
     */
    private static function defaultProcess(array $job): array
    {
        return ['type' => $job['type'], 'processed' => true, 'data' => $job['data']];
    }
}
