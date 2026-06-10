<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * Screen-space reflections quality tier.
 *
 * Two reflection contributions exist (see mesh3d.frag.glsl / ssr.frag.glsl):
 *
 *  1. A per-material WETNESS SURROGATE (the legacy path): up-facing wet/metal
 *     fragments get a smoother + darker pass so they read as polished. Driven by
 *     {@see intensity()} via the u_ssr_intensity uniform. This is the FALLBACK —
 *     it runs only when the real ray-marched pass is OFF (tier Off, or a
 *     non-D3D backend), gated in the shader by u_ssr_enabled == 0.
 *  2. Real screen-space reflections (the VIO/D3D12 path): a dedicated pass
 *     ray-marches the FP16 G-buffer depth+normal against the HDR scene colour
 *     and composites the reflection into the scene before tonemap/bloom. Only
 *     Low / High tiers run it ({@see usesRaymarch()} gate), and only on the
 *     Direct3D backends. Its look is driven by {@see rayMarchSteps()} /
 *     {@see rayThickness()} / {@see maxDistance()} / {@see strength()}.
 *
 * Tiers:
 *   Off  -> 0.0  no real SSR; wetness surrogate alone in the forward shader
 *   Low  -> 0.4  real SSR, cheaper march (fewer steps, shorter reach)
 *   High -> 1.0  real SSR, longer + finer march with binary-search refine
 *
 * When the AdaptiveTierStack downgrades SSR it mutates $settings->ssr in place,
 * so the renderer reading the live tier IS the "downgraded below Low" gate.
 */
enum ScreenSpaceReflections: string
{
    case Off  = 'off';
    case Low  = 'low';
    case High = 'high';

    /**
     * Strength of the reflection contribution. On the real ray-march path this
     * scales the composited reflection (alongside per-pixel reflectivity +
     * Fresnel); on the legacy fallback it scales the wetness surrogate's IBL
     * boost via u_ssr_intensity. Same numbers either way so a tier looks
     * consistent across the hand-off.
     */
    public function intensity(): float
    {
        return match ($this) {
            self::Off  => 0.0,
            self::Low  => 0.4,
            self::High => 1.0,
        };
    }

    /**
     * Whether this tier runs the real ray-marched SSR pass (G-buffer march +
     * composite). Low and High do; Off stays on the cheap wetness surrogate.
     * The renderer additionally gates this on the VIO/D3D12 backend and on the
     * AdaptiveTierStack not having downgraded to Off.
     */
    public function usesRaymarch(): bool
    {
        return $this === self::Low || $this === self::High;
    }

    /**
     * Number of linear ray-march steps. More steps = longer reach / fewer gaps
     * but higher cost. High also enables a binary-search refine (see ssr.frag).
     */
    public function rayMarchSteps(): int
    {
        return match ($this) {
            self::Off  => 0,
            self::Low  => 24,
            self::High => 40,
        };
    }

    /** Binary-search refinement iterations after a coarse hit (0 = none). */
    public function refineSteps(): int
    {
        return match ($this) {
            self::Off, self::Low => 0,
            self::High           => 5,
        };
    }

    /**
     * Intersection thickness in VIEW-space units (world metres). A hit is
     * accepted when the ray passes behind the stored surface by less than this
     * — too small drops valid hits, too large smears reflections through thin
     * geometry. Tuned conservative for calm-water believability.
     */
    public function rayThickness(): float
    {
        return match ($this) {
            self::Off  => 0.0,
            self::Low  => 0.6,
            self::High => 0.4,
        };
    }

    /** Maximum reflection-ray reach in VIEW-space units (world metres). */
    public function maxDistance(): float
    {
        return match ($this) {
            self::Off  => 0.0,
            self::Low  => 24.0,
            self::High => 40.0,
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
