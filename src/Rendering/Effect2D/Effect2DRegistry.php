<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Effect2D;

use PHPolygon\Rendering\Renderer2DInterface;

/**
 * The 2D effects of a game. Register at boot; each also needs an entry in
 * {@see \PHPolygon\Rendering\Effects\EffectRegistry} under the same name. Empty, the 2D
 * renderer has no extra target, shader or step.
 */
final class Effect2DRegistry
{
    /** @var array<string, Effect2D> */
    private static array $effects = [];

    /** Bumped on every change, so a renderer knows when to look again. */
    private static int $version = 0;

    public static function register(Effect2D $effect): void
    {
        self::$effects[$effect->name] = $effect;
        self::$version++;
    }

    public static function get(string $name): ?Effect2D
    {
        return self::$effects[$name] ?? null;
    }

    /** @return list<Effect2D> */
    public static function all(): array
    {
        return array_values(self::$effects);
    }

    public static function isEmpty(): bool
    {
        return self::$effects === [];
    }

    public static function version(): int
    {
        return self::$version;
    }

    public static function clear(): void
    {
        self::$effects = [];
        self::$version++;
    }

    /**
     * Draw an effect on any 2D renderer: the shader where the renderer runs effects and
     * this one can run, else the effect's fallback. False when neither drew anything
     * (no fallback, or an unknown name).
     *
     * @param array<string, int|float|list<float>> $parameters
     * @param array<string, \PHPolygon\Rendering\Texture> $textures
     */
    public static function draw(
        Renderer2DInterface $renderer,
        string $name,
        float $x,
        float $y,
        float $w,
        float $h,
        array $parameters = [],
        array $textures = [],
    ): bool {
        $effect = self::$effects[$name] ?? null;
        if ($effect === null) {
            return false;
        }
        if ($renderer instanceof EffectRenderer2DInterface && $renderer->drawEffect($name, $x, $y, $w, $h, $parameters, $textures)) {
            return true;
        }
        if ($effect->fallback === null) {
            return false;
        }
        ($effect->fallback)($renderer, $x, $y, $w, $h, $parameters);

        return true;
    }
}
