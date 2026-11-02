<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Effects;

use PHPolygon\Rendering\PostProcess\FullscreenPassRegistry;
use PHPolygon\Rendering\ProcModeShaderRegistry;

/**
 * The list of a game's effects. Nothing here touches a frame: it is the place where
 * budgets, fallbacks and accessibility duties are written down and can be checked.
 * A game's test suite calls {@see assertComplete()}, so no pass or variant ships
 * without an entry, and reads {@see budget()} per tier.
 */
final class EffectRegistry
{
    /** @var array<string, EffectDefinition> */
    private static array $effects = [];

    public static function register(EffectDefinition $effect): void
    {
        if (isset(self::$effects[$effect->name])) {
            throw new \InvalidArgumentException("Effect '{$effect->name}' is registered twice");
        }
        self::$effects[$effect->name] = $effect;
    }

    public static function get(string $name): ?EffectDefinition
    {
        return self::$effects[$name] ?? null;
    }

    /** @return list<EffectDefinition> */
    public static function all(): array
    {
        return array_values(self::$effects);
    }

    /**
     * What runs at a tier: everything from that tier down.
     *
     * @return list<EffectDefinition>
     */
    public static function atTier(EffectTier $tier): array
    {
        return array_values(array_filter(self::$effects, static fn (EffectDefinition $e): bool => $e->tier->value <= $tier->value));
    }

    /**
     * Sum of the measured costs of a tier, and the effects that have none yet: a sum
     * over unmeasured effects says nothing, so they are named. Costs from different
     * adapters or resolutions add up as they are; comparing them is the reader's call.
     *
     * @return array{gpuMs: float, unmeasured: list<string>}
     */
    public static function budget(EffectTier $tier): array
    {
        $sum = 0.0;
        $unmeasured = [];
        foreach (self::atTier($tier) as $effect) {
            if ($effect->cost === null) {
                $unmeasured[] = $effect->name;
            } else {
                $sum += $effect->cost->gpuMs;
            }
        }

        return ['gpuMs' => $sum, 'unmeasured' => $unmeasured];
    }

    /** @return list<EffectDefinition> effects that have to go through the flash limit */
    public static function flashing(): array
    {
        return array_values(array_filter(self::$effects, static fn (EffectDefinition $e): bool => $e->flash === FlashClass::Flashing));
    }

    /**
     * Fullscreen passes and shader variants without an entry.
     *
     * @return list<string>
     */
    public static function missing(): array
    {
        $names = ProcModeShaderRegistry::variants();
        foreach (FullscreenPassRegistry::all() as $pass) {
            $names[] = $pass->name;
        }

        return array_values(array_diff($names, array_keys(self::$effects)));
    }

    /** Throws when a pass or variant has no entry. For a game's test suite or a build step. */
    public static function assertComplete(): void
    {
        $missing = self::missing();
        if ($missing !== []) {
            throw new \RuntimeException('Effects without a register entry: ' . implode(', ', $missing));
        }
    }

    public static function clear(): void
    {
        self::$effects = [];
    }
}
