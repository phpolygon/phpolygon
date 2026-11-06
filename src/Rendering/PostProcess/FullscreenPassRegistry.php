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

    /** @var array<string, bool> run-time switch by name; a name without an entry is on */
    private static array $active = [];

    /** @var array<string, list<FullscreenPass>> forStage() per stage, for the current version */
    private static array $byStage = [];

    private static int $byStageVersion = -1;

    public static function register(FullscreenPass $pass): void
    {
        self::$passes[$pass->name] = $pass;
        self::$version++;
    }

    /**
     * Switch a pass on or off at run time, from the next frame on: a photo mode, a helmet
     * visor, a sketch view. Nothing is rebuilt or recompiled, no setting is written and the
     * temporal history stays. The player's setting still decides first: a pass the settings
     * turn off stays off. Works for names registered later too.
     */
    public static function setActive(string $name, bool $active): void
    {
        if ($active) {
            unset(self::$active[$name]);
        } else {
            self::$active[$name] = false;
        }
    }

    /** The run-time switch of $name; true unless {@see setActive()} turned it off. */
    public static function isActive(string $name): bool
    {
        return self::$active[$name] ?? true;
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
        if (self::$byStageVersion !== self::$version) {
            self::$byStage = [];
            self::$byStageVersion = self::$version;
        }
        if (!isset(self::$byStage[$stage->value])) {
            $passes = array_values(array_filter(self::$passes, static fn (FullscreenPass $p): bool => $p->stage === $stage));
            usort($passes, static fn (FullscreenPass $a, FullscreenPass $b): int => $a->order <=> $b->order);
            self::$byStage[$stage->value] = $passes;
        }

        return self::$byStage[$stage->value];
    }

    /** Forgets every pass and every run-time switch. */
    public static function clear(): void
    {
        self::$passes = [];
        self::$active = [];
        self::$version++;
    }
}
