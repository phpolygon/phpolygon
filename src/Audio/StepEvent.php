<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

/**
 * What a {@see StepCadence} reports for a frame.
 *
 * - $side: alternates −1 / +1 from step to step (the foot); 0 for a landing.
 * - $speed: the smoothed speed the interval was taken from, for tempo-dependent
 *   level and pitch; 0 when the character landed standing.
 * - $strength: 0..1 how hard a landing was; 0 for a step.
 */
final class StepEvent
{
    public function __construct(
        public readonly StepEventKind $kind,
        public readonly int $side,
        public readonly float $speed,
        public readonly float $strength,
    ) {}
}
