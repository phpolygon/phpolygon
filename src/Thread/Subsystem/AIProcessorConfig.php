<?php

declare(strict_types=1);

namespace PHPolygon\Thread\Subsystem;

/**
 * Configuration for AI thread internal rate-splitting.
 */
final class AIProcessorConfig
{
    public function __construct(
        public readonly float $perceptionRate = 60.0,
        public readonly float $pathfindingRate = 15.0,
        public readonly float $thinkRate = 15.0,
    ) {}

    public function pathfindingIntervalNs(): int
    {
        return (int) (1_000_000_000 / $this->pathfindingRate);
    }

    public function thinkIntervalNs(): int
    {
        return (int) (1_000_000_000 / $this->thinkRate);
    }
}
