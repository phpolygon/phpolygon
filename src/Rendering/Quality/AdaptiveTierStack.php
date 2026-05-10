<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Quality\ScreenSpaceReflections;

/**
 * Cost-impact-ordered tier stack used by both the GraphicsAutoTuner and
 * the AdaptiveQualityController. downgrade() returns the next-cheaper
 * variant of $current; upgrade() returns the next-richer variant. Both
 * return null when no further step is available.
 *
 * Order (most-expensive-per-pixel first so adaptation buys the largest
 * frame-time recovery for the smallest perceptual change):
 *   1. VolumetricFog   on -> off (8-sample raymarch per fragment)
 *   2. SSR             High -> Low -> Off (24-step world-space raymarch)
 *   3. RenderScale     1.0 -> 0.5 in 0.1 increments
 *   4. ScreenSpaceAO   High -> Medium -> Low -> Off (curvature ALU)
 *   5. ShadowQuality   High -> Medium -> Low -> Off
 *   6. ViewDistance    200 -> 150 -> 100 -> 75
 *   7. AntiAliasing    MSAA4x -> MSAA2x -> FXAA -> Off
 *   8. CloudShadows    on -> off
 *   9. Bloom           on -> off
 *  10. Vignette        > 0 -> 0 (cheap effect, last among in-shader items)
 *  11. ShaderQuality   Full -> Unlit (last because it is highly visible)
 *  12. Anisotropy      16 -> 8 -> 4 -> 2 -> 1
 *
 * TextureQuality, MeshLodTier and ColorGradingPreset are deliberately
 * excluded - the first two have hot-swap cost (texture re-upload, mesh
 * regeneration) that far outweighs any one frame's saving; ColorGrading
 * is a stylistic choice, not a perf knob.
 */
final class AdaptiveTierStack
{
    /** @return list<float> */
    private const RENDER_SCALE_LEVELS = [1.0, 0.9, 0.8, 0.7, 0.6, 0.5];
    /** @return list<float> */
    private const VIEW_DISTANCE_LEVELS = [200.0, 150.0, 100.0, 75.0];
    /** @return list<int> */
    private const ANISOTROPY_LEVELS = [16, 8, 4, 2, 1];

    public static function downgrade(GraphicsSettings $current): ?GraphicsSettings
    {
        // 1. VolumetricFog (8-step raymarch is the most expensive per-fragment cost in the stack)
        if ($current->volumetricFog) {
            return $current->with(volumetricFog: false);
        }

        // 2. SSR (24-step world-space raymarch + composite pass)
        $ssrNext = match ($current->ssr) {
            ScreenSpaceReflections::High => ScreenSpaceReflections::Low,
            ScreenSpaceReflections::Low  => ScreenSpaceReflections::Off,
            ScreenSpaceReflections::Off  => null,
        };
        if ($ssrNext !== null) {
            return $current->with(ssr: $ssrNext);
        }

        // 3. RenderScale
        $idx = self::indexFloat(self::RENDER_SCALE_LEVELS, $current->renderScale);
        if ($idx !== null && $idx + 1 < count(self::RENDER_SCALE_LEVELS)) {
            return $current->with(renderScale: self::RENDER_SCALE_LEVELS[$idx + 1]);
        }

        // 3. ScreenSpaceAO
        $aoNext = match ($current->ambientOcclusion) {
            ScreenSpaceAO::High => ScreenSpaceAO::Medium,
            ScreenSpaceAO::Medium => ScreenSpaceAO::Low,
            ScreenSpaceAO::Low => ScreenSpaceAO::Off,
            ScreenSpaceAO::Off => null,
        };
        if ($aoNext !== null) {
            return $current->with(ambientOcclusion: $aoNext);
        }

        // 4. ShadowQuality
        $next = match ($current->shadowQuality) {
            ShadowQuality::High => ShadowQuality::Medium,
            ShadowQuality::Medium => ShadowQuality::Low,
            ShadowQuality::Low => ShadowQuality::Off,
            ShadowQuality::Off => null,
        };
        if ($next !== null) {
            return $current->with(shadowQuality: $next);
        }

        // 5. ViewDistance
        $idx = self::indexFloat(self::VIEW_DISTANCE_LEVELS, $current->viewDistance);
        if ($idx !== null && $idx + 1 < count(self::VIEW_DISTANCE_LEVELS)) {
            return $current->with(viewDistance: self::VIEW_DISTANCE_LEVELS[$idx + 1]);
        }

        // 6. AntiAliasing
        $aaNext = match ($current->antiAliasing) {
            AntiAliasing::Msaa4x => AntiAliasing::Msaa2x,
            AntiAliasing::Msaa2x => AntiAliasing::Fxaa,
            AntiAliasing::Fxaa => AntiAliasing::Off,
            AntiAliasing::Taa  => AntiAliasing::Fxaa,
            AntiAliasing::Off => null,
        };
        if ($aaNext !== null) {
            return $current->with(antiAliasing: $aaNext);
        }

        // 7. CloudShadows
        if ($current->cloudShadows) {
            return $current->with(cloudShadows: false);
        }

        // 8. Bloom
        if ($current->bloom) {
            return $current->with(bloom: false);
        }

        // 9. Vignette (cheap, but visible - dropped after the heavy hitters)
        if ($current->vignetteIntensity > 0.0) {
            return $current->with(vignetteIntensity: 0.0);
        }

        // 10. ShaderQuality
        if ($current->shaderQuality === ShaderQuality::Full) {
            return $current->with(shaderQuality: ShaderQuality::Unlit);
        }

        // 11. Anisotropy
        $idx = self::indexInt(self::ANISOTROPY_LEVELS, $current->anisotropy);
        if ($idx !== null && $idx + 1 < count(self::ANISOTROPY_LEVELS)) {
            return $current->with(anisotropy: self::ANISOTROPY_LEVELS[$idx + 1]);
        }

        return null;
    }

    public static function upgrade(GraphicsSettings $current): ?GraphicsSettings
    {
        // Walk the same stack in reverse, picking the most-recently-degraded
        // setting first.
        $idx = self::indexInt(self::ANISOTROPY_LEVELS, $current->anisotropy);
        if ($idx !== null && $idx > 0) {
            return $current->with(anisotropy: self::ANISOTROPY_LEVELS[$idx - 1]);
        }

        if ($current->shaderQuality === ShaderQuality::Unlit) {
            return $current->with(shaderQuality: ShaderQuality::Full);
        }

        // Vignette is intentionally not auto-restored: the player may have
        // set it to 0 explicitly, and there is no original-value memory in
        // GraphicsSettings to honour. Games that want the controller to
        // raise the vignette back should call $manager->update() with the
        // preferred value when they detect the upgrade signal.

        if (!$current->bloom) {
            return $current->with(bloom: true);
        }

        if (!$current->cloudShadows) {
            return $current->with(cloudShadows: true);
        }

        $aaNext = match ($current->antiAliasing) {
            AntiAliasing::Off => AntiAliasing::Fxaa,
            AntiAliasing::Fxaa => AntiAliasing::Msaa2x,
            AntiAliasing::Msaa2x => AntiAliasing::Msaa4x,
            AntiAliasing::Msaa4x, AntiAliasing::Taa => null,
        };
        if ($aaNext !== null) {
            return $current->with(antiAliasing: $aaNext);
        }

        $idx = self::indexFloat(self::VIEW_DISTANCE_LEVELS, $current->viewDistance);
        if ($idx !== null && $idx > 0) {
            return $current->with(viewDistance: self::VIEW_DISTANCE_LEVELS[$idx - 1]);
        }

        $next = match ($current->shadowQuality) {
            ShadowQuality::Off => ShadowQuality::Low,
            ShadowQuality::Low => ShadowQuality::Medium,
            ShadowQuality::Medium => ShadowQuality::High,
            ShadowQuality::High => null,
        };
        if ($next !== null) {
            return $current->with(shadowQuality: $next);
        }

        $aoUp = match ($current->ambientOcclusion) {
            ScreenSpaceAO::Off => ScreenSpaceAO::Low,
            ScreenSpaceAO::Low => ScreenSpaceAO::Medium,
            ScreenSpaceAO::Medium => ScreenSpaceAO::High,
            ScreenSpaceAO::High => null,
        };
        if ($aoUp !== null) {
            return $current->with(ambientOcclusion: $aoUp);
        }

        $idx = self::indexFloat(self::RENDER_SCALE_LEVELS, $current->renderScale);
        if ($idx !== null && $idx > 0) {
            return $current->with(renderScale: self::RENDER_SCALE_LEVELS[$idx - 1]);
        }

        $ssrUp = match ($current->ssr) {
            ScreenSpaceReflections::Off  => ScreenSpaceReflections::Low,
            ScreenSpaceReflections::Low  => ScreenSpaceReflections::High,
            ScreenSpaceReflections::High => null,
        };
        if ($ssrUp !== null) {
            return $current->with(ssr: $ssrUp);
        }

        if (!$current->volumetricFog) {
            return $current->with(volumetricFog: true);
        }

        return null;
    }

    /**
     * @param list<float> $levels
     */
    private static function indexFloat(array $levels, float $value): ?int
    {
        $bestIdx = null;
        $bestDist = INF;
        foreach ($levels as $i => $level) {
            $d = abs($level - $value);
            if ($d < $bestDist) {
                $bestDist = $d;
                $bestIdx = $i;
            }
        }
        return $bestIdx;
    }

    /**
     * @param list<int> $levels
     */
    private static function indexInt(array $levels, int $value): ?int
    {
        foreach ($levels as $i => $level) {
            if ($level === $value) {
                return $i;
            }
        }
        return null;
    }
}
