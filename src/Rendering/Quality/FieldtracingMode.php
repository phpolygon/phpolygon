<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * Fieldtracing (SDF global-illumination) quality tier.
 *
 * Fieldtracing is sphere-tracing a signed distance field for diffuse GI, soft
 * shadows and AO — it *augments* the existing forward stack (shadow maps,
 * volumetric fog, SSAO, SSR, ACES), it does not replace it. The tier is
 * evaluated against renderer capability flags (vio_supports_feature); a backend
 * that cannot satisfy the requested tier silently degrades to the next lower one
 * it can run, down to {@see Off} (the existing forward stack only).
 *
 * Tiers (see PHPOLYGON_FIELDTRACING.md §8):
 *
 *   Off          -> forward stack only (SSAO/SSR/shadows). Floor, all hardware.
 *   ProbesOnly   -> static probe irradiance field. Weak GPUs, iPad.
 *   SdfOcclusion -> + SDF ambient occlusion + soft SDF shadows. Mid desktop.
 *   SdfBounce    -> + 1 bounce diffuse GI via cone-trace. High-end desktop.
 *
 * This tier is a deliberate settings choice, NOT part of the adaptive hot-swap
 * stack — the SDF rebuild/re-bake cost dominates any frame-time gain, same as
 * TextureQuality / MeshLodTier.
 */
enum FieldtracingMode: string
{
    case Off          = 'off';
    case ProbesOnly   = 'probes_only';
    case SdfOcclusion = 'sdf_occlusion';
    case SdfBounce    = 'sdf_bounce';

    /** Whether this tier evaluates anything beyond the forward stack. */
    public function isEnabled(): bool
    {
        return $this !== self::Off;
    }

    /** Whether this tier reads the static probe irradiance field. */
    public function usesProbes(): bool
    {
        return $this !== self::Off;
    }

    /** Whether this tier sphere-traces the SDF (AO + soft shadows). */
    public function tracesSdf(): bool
    {
        return $this === self::SdfOcclusion || $this === self::SdfBounce;
    }

    /** Whether this tier cone-traces a diffuse bounce. */
    public function tracesBounce(): bool
    {
        return $this === self::SdfBounce;
    }

    /**
     * Minimum tier this mode can degrade to while still satisfying its intent.
     * SdfBounce -> SdfOcclusion -> ProbesOnly -> Off. Used by capability-gating
     * in the backends' applySettings() to pick the highest runnable tier.
     */
    public function degraded(): self
    {
        return match ($this) {
            self::SdfBounce    => self::SdfOcclusion,
            self::SdfOcclusion => self::ProbesOnly,
            self::ProbesOnly, self::Off => self::Off,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Off          => 'Off',
            self::ProbesOnly   => 'Probes',
            self::SdfOcclusion => 'SDF Occlusion',
            self::SdfBounce    => 'SDF Bounce',
        };
    }
}
