<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Effects;

use PHPolygon\Rendering\Effect2D\Effect2DRegistry;
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

    private static bool $reducedMotion = false;
    private static float $motionScale = 0.0;
    private static bool $flashLimit = false;

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
     * Fullscreen passes, shader variants and 2D effects without an entry.
     *
     * @return list<string>
     */
    public static function missing(): array
    {
        $names = ProcModeShaderRegistry::variants();
        foreach (FullscreenPassRegistry::all() as $pass) {
            $names[] = $pass->name;
        }
        foreach (Effect2DRegistry::all() as $effect) {
            $names[] = $effect->name;
        }

        return array_values(array_unique(array_diff($names, array_keys(self::$effects))));
    }

    /**
     * The player's reduced-motion choice. `GraphicsSettingsManager` sets it from
     * `AccessibilitySettings::$reducedMotion` whenever it applies settings; call it
     * directly only without a manager (tools, tests). The 2D effects follow it through
     * their {@see ReducedMotion} entry: `Frozen` holds `u_time` at 0, `Reduced` hands
     * `$motionScale` to the shader as `u_motion`, `Off` draws the fallback instead. The
     * 3D renderer reads {@see effectTime()} for its fullscreen passes and shader variants.
     */
    public static function setReducedMotion(bool $reduced, float $motionScale = 0.0): void
    {
        self::$reducedMotion = $reduced;
        self::$motionScale = max(0.0, min(1.0, $motionScale));
    }

    public static function reducedMotion(): bool
    {
        return self::$reducedMotion;
    }

    /** `u_motion` of an effect: 1, or under reduced motion the game's scale for a `Reduced` effect. */
    public static function motionScale(?EffectDefinition $effect): float
    {
        return self::$reducedMotion && $effect?->reducedMotion === ReducedMotion::Reduced ? self::$motionScale : 1.0;
    }

    /** Whether reduced motion holds an effect's time still. */
    public static function timeFrozen(?EffectDefinition $effect): bool
    {
        return self::$reducedMotion && $effect?->reducedMotion === ReducedMotion::Frozen;
    }

    /**
     * Shader time (`u_time`) of an effect at engine time $time: 0 while held still (by the
     * flash limit, or by reduced motion: a 3D effect cannot be taken out, so `Off` holds
     * it too), scaled for a `Reduced` effect,
     * $time itself otherwise and for a name without an entry. The 3D renderer gives every
     * fullscreen pass and shader variant its own, by the name of its entry; the base mesh
     * program has no name, so water, plants and every look in it keep the engine time.
     */
    public static function effectTime(?EffectDefinition $effect, float $time): float
    {
        if ($effect === null) {
            return $time;
        }
        if (self::flashLimited($effect) || self::timeFrozen($effect) || self::motionSwitchesOff($effect)) {
            return 0.0;
        }
        return self::$reducedMotion && $effect->reducedMotion === ReducedMotion::Reduced ? $time * self::$motionScale : $time;
    }

    /**
     * The player's flash limit (`AccessibilitySettings::$flashLimit`, set by
     * `GraphicsSettingsManager`). While it is on, every effect registered as
     * {@see FlashClass::Flashing} has its shader time held at 0 (2D effects, fullscreen
     * passes, shader variants), and the 3D renderer limits how fast the brightness of
     * the whole image may change.
     */
    public static function setFlashLimit(bool $on): void
    {
        self::$flashLimit = $on;
    }

    public static function flashLimit(): bool
    {
        return self::$flashLimit;
    }

    /** Whether the flash limit holds this effect still. */
    public static function flashLimited(?EffectDefinition $effect): bool
    {
        return self::$flashLimit && $effect?->flash === FlashClass::Flashing;
    }

    /** Whether reduced motion takes an effect out (its fallback draws instead). */
    public static function motionSwitchesOff(?EffectDefinition $effect): bool
    {
        return self::$reducedMotion && $effect?->reducedMotion === ReducedMotion::Off;
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
        self::$reducedMotion = false;
        self::$motionScale = 0.0;
        self::$flashLimit = false;
    }
}
