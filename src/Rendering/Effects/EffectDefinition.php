<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Effects;

/**
 * What a shipped visual effect has to answer for: where it runs, what it costs, what
 * stands in for it where it cannot run, and how it treats motion and flashing light.
 * `$name` is the name of its fullscreen pass or shader variant, if it has one. A game
 * registers one entry per effect; the engine ships none.
 */
final class EffectDefinition
{
    /**
     * @param EffectTier     $tier          lowest graphics tier the effect runs at
     * @param EffectCost|null $cost         last measurement; null = not measured yet
     * @param EffectFallback $unlitFallback what ShaderQuality::Unlit shows instead
     * @param EffectFallback $glFallback    what the php-glfw renderer shows instead
     */
    public function __construct(
        public readonly string $name,
        public readonly EffectTier $tier,
        public readonly ?EffectCost $cost,
        public readonly EffectFallback $unlitFallback,
        public readonly EffectFallback $glFallback,
        public readonly ReducedMotion $reducedMotion,
        public readonly FlashClass $flash,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("Effect name '{$name}' must be lower snake case");
        }
    }
}
