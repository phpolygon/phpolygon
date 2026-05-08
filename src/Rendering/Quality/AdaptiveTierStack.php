<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

use PHPolygon\Rendering\GraphicsSettings;

/**
 * Cost-impact-ordered tier stack used by both the GraphicsAutoTuner and
 * the AdaptiveQualityController. downgrade() returns the next-cheaper
 * variant of $current; upgrade() returns the next-richer variant. Both
 * return null when no further step is available.
 *
 * Order (cheapest hot-swaps first):
 *   1. RenderScale     1.0 -> 0.5 in 0.1 increments
 *   2. ShadowQuality   High -> Medium -> Low -> Off
 *   3. ViewDistance    200 -> 150 -> 100 -> 75
 *   4. AntiAliasing    MSAA4x -> MSAA2x -> FXAA -> Off
 *   5. CloudShadows    on -> off
 *   6. Bloom           on -> off
 *   7. ShaderQuality   Full -> Unlit (last because it is highly visible)
 *   8. Anisotropy      16 -> 8 -> 4 -> 2 -> 1
 *
 * TextureQuality and MeshLodTier are deliberately excluded - their hot-
 * swap cost (texture re-upload, mesh regeneration) far outweighs any one
 * frame's saving.
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
        // 1. RenderScale
        $idx = self::indexFloat(self::RENDER_SCALE_LEVELS, $current->renderScale);
        if ($idx !== null && $idx + 1 < count(self::RENDER_SCALE_LEVELS)) {
            return $current->with(renderScale: self::RENDER_SCALE_LEVELS[$idx + 1]);
        }

        // 2. ShadowQuality
        $next = match ($current->shadowQuality) {
            ShadowQuality::High => ShadowQuality::Medium,
            ShadowQuality::Medium => ShadowQuality::Low,
            ShadowQuality::Low => ShadowQuality::Off,
            ShadowQuality::Off => null,
        };
        if ($next !== null) {
            return $current->with(shadowQuality: $next);
        }

        // 3. ViewDistance
        $idx = self::indexFloat(self::VIEW_DISTANCE_LEVELS, $current->viewDistance);
        if ($idx !== null && $idx + 1 < count(self::VIEW_DISTANCE_LEVELS)) {
            return $current->with(viewDistance: self::VIEW_DISTANCE_LEVELS[$idx + 1]);
        }

        // 4. AntiAliasing
        $aaNext = match ($current->antiAliasing) {
            AntiAliasing::Msaa4x => AntiAliasing::Msaa2x,
            AntiAliasing::Msaa2x => AntiAliasing::Fxaa,
            AntiAliasing::Fxaa => AntiAliasing::Off,
            AntiAliasing::Off => null,
        };
        if ($aaNext !== null) {
            return $current->with(antiAliasing: $aaNext);
        }

        // 5. CloudShadows
        if ($current->cloudShadows) {
            return $current->with(cloudShadows: false);
        }

        // 6. Bloom
        if ($current->bloom) {
            return $current->with(bloom: false);
        }

        // 7. ShaderQuality
        if ($current->shaderQuality === ShaderQuality::Full) {
            return $current->with(shaderQuality: ShaderQuality::Unlit);
        }

        // 8. Anisotropy
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
            AntiAliasing::Msaa4x => null,
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

        $idx = self::indexFloat(self::RENDER_SCALE_LEVELS, $current->renderScale);
        if ($idx !== null && $idx > 0) {
            return $current->with(renderScale: self::RENDER_SCALE_LEVELS[$idx - 1]);
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
