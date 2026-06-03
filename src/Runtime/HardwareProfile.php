<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

/**
 * Snapshot of the host CPU as the engine sees it at startup.
 *
 * Cheap to compute (one sysctl call on macOS, no-op elsewhere) and immutable
 * for the life of the process. Used by ThermalMonitor to pick an initial
 * targetFps ceiling on known throttle-prone Macs.
 */
final readonly class HardwareProfile
{
    public function __construct(
        public string $vendor,
        public string $cpuBrand,
        public string $osFamily,
        public ThermalProfile $thermalProfile,
    ) {
    }

    public function targetFpsCeiling(): ?float
    {
        return $this->thermalProfile->recommendedFpsCeiling();
    }

    public function describe(): string
    {
        $brand = $this->cpuBrand !== '' ? $this->cpuBrand : '(unknown)';
        return sprintf(
            'vendor=%s, cpu=%s, os=%s, profile=%s',
            $this->vendor,
            $brand,
            $this->osFamily,
            $this->thermalProfile->value,
        );
    }
}
