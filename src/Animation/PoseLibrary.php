<?php

declare(strict_types=1);

namespace PHPolygon\Animation;

/**
 * Rigs and clips by id, like the mesh and material registries: a game registers its
 * figures' rigs and its clips once, and {@see \PHPolygon\Component\PosePlayer}
 * components refer to them by id (so the components stay plain, serialisable data).
 */
final class PoseLibrary
{
    /** @var array<string, PoseRig> */
    private static array $rigs = [];

    /** @var array<string, PoseClip> */
    private static array $clips = [];

    public static function registerRig(string $id, PoseRig $rig): void
    {
        self::$rigs[$id] = $rig;
    }

    public static function registerClip(string $id, PoseClip $clip): void
    {
        self::$clips[$id] = $clip;
    }

    public static function rig(string $id): ?PoseRig
    {
        return self::$rigs[$id] ?? null;
    }

    public static function clip(string $id): ?PoseClip
    {
        return self::$clips[$id] ?? null;
    }

    public static function clear(): void
    {
        self::$rigs = [];
        self::$clips = [];
    }
}
