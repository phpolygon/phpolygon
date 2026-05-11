<?php

declare(strict_types=1);

namespace PHPolygon\Event;

/**
 * Fired during GraphicsAutoTuner runs as the tier search progresses.
 * The ratio is in [0.0, 1.0] and reflects calibration completeness, not
 * frame progress within the current tier.
 */
final readonly class GraphicsCalibrationProgress
{
    public function __construct(
        public float $ratio,
        public string $stage = '',
    ) {
    }
}
