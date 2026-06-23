<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * Screen-space ambient-occlusion quality tier.
 *
 * Two AO contributions are layered (see mesh3d.frag.glsl):
 *
 *  1. A per-fragment curvature approximation (analytic AO from screen-space
 *     derivatives of the surface normal), driven by {@see strength()} via the
 *     u_ao_strength uniform. Cheap, always on at Low+; catches fine concavities.
 *  2. Real depth+normal screen-space AO (a G-buffer pass + hemisphere kernel +
 *     blur, multiplied on top in the ambient term). Only the VIO/D3D12 backend
 *     runs it, and only at Medium / High (the {@see usesGbuffer()} gate). Its
 *     look is driven by {@see ssaoIntensity()} / {@see ssaoRadius()} /
 *     {@see ssaoPower()}.
 *
 * Tiers:
 *
 *   Off    -> 0.0   curvature, no G-buffer SSAO  (no AO)
 *   Low    -> 0.4   curvature only, subtle, cheap (no G-buffer pass)
 *   Medium -> 0.7   curvature + G-buffer SSAO (default - "looks grounded")
 *   High   -> 1.0   curvature + stronger/wider G-buffer SSAO
 */
enum ScreenSpaceAO: string
{
    case Off    = 'off';
    case Low    = 'low';
    case Medium = 'medium';
    case High   = 'high';

    public function strength(): float
    {
        return match ($this) {
            self::Off    => 0.0,
            self::Low    => 0.4,
            self::Medium => 0.7,
            self::High   => 1.0,
        };
    }

    /**
     * Whether this tier runs the real depth+normal SSAO (G-buffer + hemisphere
     * + blur). Only Medium and High do; Off/Low stay on the cheap curvature
     * surrogate alone. The renderer additionally gates this on the VIO/D3D12
     * backend and on the AdaptiveTierStack not having downgraded below Medium.
     */
    public function usesGbuffer(): bool
    {
        return $this === self::Medium || $this === self::High;
    }

    /** Occlusion scale fed to ssao.frag's u_intensity. Higher = darker contact. */
    public function ssaoIntensity(): float
    {
        return match ($this) {
            self::Off, self::Low => 0.0,
            self::Medium         => 1.0,
            self::High           => 1.15,
        };
    }

    /**
     * Hemisphere sample radius in view-space (world) units — ssao.frag u_radius.
     * High was 0.7, which scattered AO well past real contact points and read as
     * unnatural smudging; 0.55 keeps the occlusion tight to where geometry
     * actually meets while staying a touch wider than Medium.
     */
    public function ssaoRadius(): float
    {
        return match ($this) {
            self::Off, self::Low => 0.0,
            self::Medium         => 0.5,
            self::High           => 0.55,
        };
    }

    /** Contrast curve applied to the final AO — ssao.frag u_power. */
    public function ssaoPower(): float
    {
        return match ($this) {
            self::Off, self::Low => 1.0,
            self::Medium         => 1.5,
            self::High           => 1.6,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Off    => 'Off',
            self::Low    => 'Low',
            self::Medium => 'Medium',
            self::High   => 'High',
        };
    }
}
