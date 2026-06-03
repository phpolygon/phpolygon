<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

use PHPolygon\Rendering\Quality\PressureSignal;

/**
 * Raw value returned by the OS-level thermal API on macOS via vio_thermal_state().
 * Maps 1:1 to NSProcessInfoThermalState. Other platforms always report Unknown.
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
     * isn't available (older php-vio build, vio not loaded, non-macOS host).
     */
    public static function fromVio(): self
    {
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
