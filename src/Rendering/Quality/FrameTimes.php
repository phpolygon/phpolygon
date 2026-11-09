<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/** Percentiles of frame-time samples (ms), shared by the auto-tuner and the tour benchmark. */
final class FrameTimes
{
    /**
     * The sample at share $p of the sorted samples (0.99 = the 1 % slowest frames
     * start here, so 1000 / result = the "1 % low" FPS). 0 for no samples.
     *
     * @param list<float> $samples
     */
    public static function percentile(array $samples, float $p): float
    {
        if ($samples === []) {
            return 0.0;
        }
        sort($samples);
        $idx = (int) floor(count($samples) * $p);

        return $samples[max(0, min(count($samples) - 1, $idx))];
    }
}
