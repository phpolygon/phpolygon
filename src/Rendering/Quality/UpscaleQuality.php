<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * Render-scale preset of an upscaler, using the per-axis ratios the vendor
 * upscalers share (FSR 2/3 and DLSS): Quality renders at 1/1.5, Balanced at
 * 1/1.7, Performance at 1/2 and Ultra Performance at 1/3 of the display
 * resolution. NativeAa renders at full resolution and only uses the upscaler's
 * temporal anti-aliasing (DLAA / FSR native AA). Custom leaves the render scale
 * to {@see \PHPolygon\Rendering\GraphicsSettings::$renderScale}.
 */
enum UpscaleQuality: string
{
    case NativeAa = 'native_aa';
    case Quality = 'quality';
    case Balanced = 'balanced';
    case Performance = 'performance';
    case UltraPerformance = 'ultra_performance';
    case Custom = 'custom';

    /** Per-axis render scale, or null when the render-scale slider decides (Custom). */
    public function renderScale(): ?float
    {
        return match ($this) {
            self::NativeAa => 1.0,
            self::Quality => 1.0 / 1.5,
            self::Balanced => 1.0 / 1.7,
            self::Performance => 0.5,
            self::UltraPerformance => 1.0 / 3.0,
            self::Custom => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::NativeAa => 'Native AA',
            self::Quality => 'Quality',
            self::Balanced => 'Balanced',
            self::Performance => 'Performance',
            self::UltraPerformance => 'Ultra Performance',
            self::Custom => 'Custom',
        };
    }
}
