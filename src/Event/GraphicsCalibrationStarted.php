<?php

declare(strict_types=1);

namespace PHPolygon\Event;

/**
 * Fired when the GraphicsAutoTuner begins running its benchmark scene.
 * Useful for showing a "Optimizing..." overlay in the UI.
 */
final readonly class GraphicsCalibrationStarted
{
    public function __construct(
        public float $targetFps,
    ) {
    }
}
