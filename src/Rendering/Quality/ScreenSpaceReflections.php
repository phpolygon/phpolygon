<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * Screen-space reflections quality tier.
 *
 * Implementation status: the engine ships infrastructure (setting,
 * uniform routing, intensity scaler) and a forward-renderer fallback
 * that boosts the {@see \PHPolygon\Rendering\Material::$wetness} lobe
 * when SSR is enabled. The full depth-buffer ray-marcher pass is the
 * next iteration's job - it requires the colour FBO's depth attachment
 * to become a sampled texture and a dedicated composite pass after the
 * main render.
 *
 * The setting is exposed today so games can opt into it from settings
 * UI / save files; bumping the implementation does not require changing
 * any caller.
 *
 * Tiers map to a single `u_ssr_intensity` uniform, multiplied into the
 * wetness IBL contribution:
 *   Off  -> 0.0  (wetness lobe alone)
 *   Low  -> 0.4
 *   High -> 1.0
 */
enum ScreenSpaceReflections: string
{
    case Off  = 'off';
    case Low  = 'low';
    case High = 'high';

    public function intensity(): float
    {
        return match ($this) {
            self::Off  => 0.0,
            self::Low  => 0.4,
            self::High => 1.0,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Off  => 'Off',
            self::Low  => 'Low',
            self::High => 'High',
        };
    }
}
