<?php

declare(strict_types=1);

namespace PHPolygon\Event;

use PHPolygon\Rendering\Quality\BenchmarkResult;

/**
 * Fired when GraphicsAutoTuner has finished. Carries the chosen settings
 * and the achieved p95 frame time so callers can decide whether to display
 * a "we picked X" notification to the player.
 */
final readonly class GraphicsCalibrationCompleted
{
    public function __construct(
        public BenchmarkResult $result,
    ) {
    }
}
