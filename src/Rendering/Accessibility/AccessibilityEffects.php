<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Accessibility;

use PHPolygon\Rendering\Effects\EffectCost;
use PHPolygon\Rendering\Effects\EffectDefinition;
use PHPolygon\Rendering\Effects\EffectFallback;
use PHPolygon\Rendering\Effects\EffectRegistry;
use PHPolygon\Rendering\Effects\EffectTier;
use PHPolygon\Rendering\Effects\FlashClass;
use PHPolygon\Rendering\Effects\ReducedMotion;

/**
 * Register entries of the engine's own accessibility options. Unlike a game's
 * effects they run at every tier (a player who needs one needs it on any machine),
 * draw the same under ShaderQuality::Unlit (they work on the finished image) and
 * do not exist on the GL family. The Engine registers them at start-up.
 */
final class AccessibilityEffects
{
    /**
     * Costs: GPU frame time with the option against without, median of five runs of 300 frames each,
     * RTX 2080 (driver 621.01), D3D12, php-vio 2.40.3, 1920x1080, 201 draws, FXAA + bloom, GPU otherwise
     * idle, 2026-11-03. Frame without option 0.73-0.75 ms. Vulkan, same setup: colour vision +0.076,
     * saturation +0.084, contrast +0.104, flash limit +0.136, night boost +0.141, vignette +0.081,
     * focus +0.078, reduced motion +0.003 ms. Every option at once: +0.155 ms (D3D12), +0.177 ms (Vulkan).
     *
     * @return list<EffectDefinition>
     */
    public static function definitions(): array
    {
        return [
            new EffectDefinition('a11y_color_vision', EffectTier::Low, self::cost(0.092), EffectFallback::Same, EffectFallback::Off, ReducedMotion::Static, FlashClass::None),
            new EffectDefinition('a11y_comfort_vignette', EffectTier::Low, self::cost(0.086), EffectFallback::Same, EffectFallback::Off, ReducedMotion::Static, FlashClass::Gentle),
            new EffectDefinition('a11y_contrast', EffectTier::Low, self::cost(0.113), EffectFallback::Same, EffectFallback::Off, ReducedMotion::Static, FlashClass::None),
            new EffectDefinition('a11y_flash_limit', EffectTier::Low, self::cost(0.114), EffectFallback::Same, EffectFallback::Off, ReducedMotion::Static, FlashClass::None),
            new EffectDefinition('a11y_focus_dim', EffectTier::Low, self::cost(0.086), EffectFallback::Same, EffectFallback::Off, ReducedMotion::Static, FlashClass::None),
            new EffectDefinition('a11y_night_boost', EffectTier::Low, self::cost(0.119), EffectFallback::Same, EffectFallback::Off, ReducedMotion::Static, FlashClass::None),
            // No pass of its own, it only changes which time other effects see.
            new EffectDefinition('a11y_reduced_motion', EffectTier::Low, self::cost(0.001), EffectFallback::Same, EffectFallback::Off, ReducedMotion::Static, FlashClass::None),
            new EffectDefinition('a11y_world_saturation', EffectTier::Low, self::cost(0.084), EffectFallback::Same, EffectFallback::Off, ReducedMotion::Static, FlashClass::None),
        ];
    }

    /** Adds the entries a register does not hold yet; a game may register its own under the same name first. */
    public static function register(): void
    {
        foreach (self::definitions() as $definition) {
            if (EffectRegistry::get($definition->name) === null) {
                EffectRegistry::register($definition);
            }
        }
    }

    private static function cost(?float $gpuMs): ?EffectCost
    {
        return $gpuMs === null ? null : new EffectCost($gpuMs, 'NVIDIA GeForce RTX 2080', 'd3d12', 1920, 1080, '2026-11-03');
    }
}
