<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\PostProcess;

use PHPolygon\Rendering\Effects\EffectCost;
use PHPolygon\Rendering\Effects\EffectDefinition;
use PHPolygon\Rendering\Effects\EffectFallback;
use PHPolygon\Rendering\Effects\EffectRegistry;
use PHPolygon\Rendering\Effects\EffectTier;
use PHPolygon\Rendering\Effects\FlashClass;
use PHPolygon\Rendering\Effects\ReducedMotion;

/**
 * Register entries of the image chain's parts ({@see ImageLook}). They work on the finished
 * image, so ShaderQuality::Unlit shows them the same; the GL family has none. The tier is the
 * lowest at which a game would normally switch the part on; the look decides, not the tier.
 * The Engine registers them at start-up; a game entry of the same name wins.
 */
final class ImageChainEffects
{
    /**
     * Costs: GPU marks of the parts (`bloom`, `image.exposure`, `image.finish`, `image.sharpen`), median of
     * {@see MEASURED} - null where nothing was measured.
     *
     * @return list<EffectDefinition>
     */
    public static function definitions(): array
    {
        return [
            new EffectDefinition('image_bloom_chain', EffectTier::Medium, self::cost(self::MEASURED['image_bloom_chain']), EffectFallback::Same, EffectFallback::Off, ReducedMotion::Static, FlashClass::None),
            // Adapts over seconds; a flash limit after it sees the adapted image, so it never adds a flash.
            new EffectDefinition('image_auto_exposure', EffectTier::Medium, self::cost(self::MEASURED['image_auto_exposure']), EffectFallback::Same, EffectFallback::Off, ReducedMotion::Static, FlashClass::Gentle),
            new EffectDefinition('image_finish', EffectTier::Low, self::cost(self::MEASURED['image_finish']), EffectFallback::Same, EffectFallback::Off, ReducedMotion::Static, FlashClass::None),
            new EffectDefinition('image_lut', EffectTier::Low, null, EffectFallback::Same, EffectFallback::Off, ReducedMotion::Static, FlashClass::None),
            new EffectDefinition('image_deband', EffectTier::Low, null, EffectFallback::Same, EffectFallback::Off, ReducedMotion::Static, FlashClass::None),
            // A saturation change at constant luminance; dropped under reduced motion and lowered world saturation.
            new EffectDefinition('image_saturation_pulse', EffectTier::Low, null, EffectFallback::Same, EffectFallback::Off, ReducedMotion::Off, FlashClass::None),
            new EffectDefinition(VioImageChain::GRAIN_EFFECT, EffectTier::High, null, EffectFallback::Same, EffectFallback::Off, ReducedMotion::Frozen, FlashClass::None),
            new EffectDefinition('image_chromatic_aberration', EffectTier::High, null, EffectFallback::Same, EffectFallback::Off, ReducedMotion::Static, FlashClass::None),
            new EffectDefinition('image_sharpen', EffectTier::Low, self::cost(self::MEASURED['image_sharpen']), EffectFallback::Same, EffectFallback::Off, ReducedMotion::Static, FlashClass::None),
        ];
    }

    /** GPU ms of the parts that have a mark of their own; filled from measurements, null until measured. */
    public const array MEASURED = [
        'image_bloom_chain' => null,
        'image_auto_exposure' => null,
        'image_finish' => null,
        'image_sharpen' => null,
    ];

    /** Adds the entries a register does not hold yet. */
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
        return $gpuMs === null ? null : new EffectCost($gpuMs, 'NVIDIA GeForce RTX 2080', 'd3d12', 1920, 1080, '2026-11-06');
    }
}
