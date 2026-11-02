<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Effects;

/**
 * A measured GPU cost, with what makes the number mean anything: on which adapter and
 * backend, at which resolution, when. Comes from the GPU pass times, never an estimate.
 */
final class EffectCost
{
    public function __construct(
        public readonly float $gpuMs,
        public readonly string $adapter,
        public readonly string $backend,
        public readonly int $width,
        public readonly int $height,
        public readonly string $measuredOn,
    ) {
        if ($gpuMs < 0.0) {
            throw new \InvalidArgumentException('A measured cost cannot be negative');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $measuredOn) !== 1) {
            throw new \InvalidArgumentException("Measurement date '{$measuredOn}' must be YYYY-MM-DD");
        }
    }
}
