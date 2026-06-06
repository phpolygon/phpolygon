<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

use PHPolygon\Rendering\Quality\PressureSignal;

/**
 * Real hardware thermal level read via php-vio's vio_thermal_state(): macOS
 * NSProcessInfo.thermalState, Linux sysfs thermal zones, Windows NVIDIA NVAPI
 * (with an ACPI-WMI fallback). Platforms/GPUs without a readable sensor report
 * Unknown. The level names map 1:1 to NSProcessInfoThermalState.
 */
enum ThermalState: string
{
    case Nominal  = 'nominal';
    case Fair     = 'fair';
    case Serious  = 'serious';
    case Critical = 'critical';
    case Unknown  = 'unknown';

    /**
     * Query the active vio extension. Returns Unknown when vio_thermal_state()
     * isn't available (older php-vio build, vio not loaded, no sensor).
     *
     * PHPOLYGON_THERMAL_FORCE (nominal|fair|serious|critical) overrides the read
     * for development/testing of the thermal response without heating the device.
     */
    public static function fromVio(): self
    {
        $forced = getenv('PHPOLYGON_THERMAL_FORCE');
        if ($forced !== false) {
            $state = self::tryFrom($forced);
            if ($state !== null) {
                return $state;
            }
        }
        if (!function_exists('vio_thermal_state')) {
            return self::Unknown;
        }
        return self::tryFrom(vio_thermal_state()) ?? self::Unknown;
    }

    public function toPressureSignal(): PressureSignal
    {
        return match ($this) {
            self::Nominal  => PressureSignal::Nominal,
            self::Fair     => PressureSignal::Fair,
            self::Serious  => PressureSignal::Serious,
            self::Critical => PressureSignal::Critical,
            self::Unknown  => PressureSignal::Unknown,
        };
    }
}
