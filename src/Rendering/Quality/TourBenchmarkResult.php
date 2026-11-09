<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

use PHPolygon\Rendering\GraphicsSettings;

/** What a {@see TourBenchmark} found: the settings and the frame time each station held with them. */
final readonly class TourBenchmarkResult
{
    /**
     * @param array<string, float> $stationMs label => frame time at the percentile, last measurement
     * @param list<string> $changes what the run changed, in order (adaptive step labels)
     */
    public function __construct(
        public float $targetFps,
        public float $percentile,
        public GraphicsSettings $startSettings,
        public GraphicsSettings $finalSettings,
        public array $stationMs,
        public array $changes,
        public bool $aborted = false,
    ) {
    }

    /** Frame time of the slowest station (ms). */
    public function worstMs(): float
    {
        return $this->stationMs === [] ? 0.0 : max($this->stationMs);
    }

    /** The FPS the slowest station holds at the percentile ("1 % low" for 0.99). */
    public function worstFps(): float
    {
        $ms = $this->worstMs();

        return $ms > 0.0 ? 1000.0 / $ms : 0.0;
    }

    public function metTarget(): bool
    {
        return !$this->aborted && $this->stationMs !== [] && $this->worstFps() >= $this->targetFps;
    }
}
