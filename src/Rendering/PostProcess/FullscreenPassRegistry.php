<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\PostProcess;

/**
 * The fullscreen passes of the image chain. Register at boot, before the renderer
 * warms its shaders. Empty, the chain has no extra step and no extra target.
 */
final class FullscreenPassRegistry
{
    /** @var array<string, FullscreenPass> */
    private static array $passes = [];

    /** Bumped on every change, so a renderer knows when to look again. */
    private static int $version = 0;

    public static function register(FullscreenPass $pass): void
    {
        self::$passes[$pass->name] = $pass;
        self::$version++;
    }

    public static function isEmpty(): bool
    {
        return self::$passes === [];
    }

    public static function version(): int
    {
        return self::$version;
    }

    public static function get(string $name): ?FullscreenPass
    {
        return self::$passes[$name] ?? null;
    }

    /** @return list<FullscreenPass> */
    public static function all(): array
    {
        return array_values(self::$passes);
    }

    /** @return list<FullscreenPass> the stage's passes in running order; equal order keeps registration order */
    public static function forStage(PostStage $stage): array
    {
        $passes = array_values(array_filter(self::$passes, static fn (FullscreenPass $p): bool => $p->stage === $stage));
        usort($passes, static fn (FullscreenPass $a, FullscreenPass $b): int => $a->order <=> $b->order);

        return $passes;
    }

    public static function clear(): void
    {
        self::$passes = [];
        self::$version++;
    }
}
