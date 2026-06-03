<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

/**
 * Identified thermal characteristic of the host hardware.
 *
 * Drives the initial targetFps ceiling applied by ThermalMonitor when the
 * engine first runs on this machine. Apple Silicon, generic Intel cores,
 * Linux, and Windows fall into Unknown / IntelCore / AppleSilicon and get
 * no ceiling - the universal frametime guard still applies.
 *
 * Only Intel i9 mobile chips known to throttle aggressively get a ceiling.
 * The 2018/2019 15" MBP (i9-8950HK / i9-9980HK) is the worst case and gets
 * the tightest ceiling.
 */
enum ThermalProfile: string
{
    case Unknown             = 'unknown';
    case AppleSilicon        = 'apple_silicon';
    case IntelCore           = 'intel_core';
    case IntelI9             = 'intel_i9';
    case IntelI9_2018_15inch = 'intel_i9_2018';

    /**
     * Maximum targetFps the engine should set at first launch on this profile.
     * Null = no ceiling (full targetFps from GraphicsSettings defaults).
     */
    public function recommendedFpsCeiling(): ?float
    {
        return match ($this) {
            self::IntelI9             => 60.0,
            self::IntelI9_2018_15inch => 50.0,
            default                   => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Unknown             => 'Unknown',
            self::AppleSilicon        => 'Apple Silicon',
            self::IntelCore           => 'Intel Core',
            self::IntelI9             => 'Intel Core i9',
            self::IntelI9_2018_15inch => 'Intel Core i9 (2018/2019 MBP)',
        };
    }
}
