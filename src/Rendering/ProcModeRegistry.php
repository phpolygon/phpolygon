<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Maps a material id to a procedural-shading mode (`u_proc_mode`), the int the
 * 3D renderers feed the mesh3d shader to pick a per-material shading branch.
 *
 * The mapping is GAME-DRIVEN and generic: the engine ships no mappings (an
 * unmapped id resolves to 0 = standard PBR), and a game registers its own
 * id-prefix → mode assignments at boot. This keeps the engine free of any
 * specific game's material vocabulary while letting a game extend the shading
 * (paired with {@see ProcModeShaderRegistry} for the GLSL/MSL that mode runs).
 *
 * Resolution is longest-prefix-wins on the id's non-numeric prefix, so more
 * specific prefixes (e.g. "pool_water") take precedence over shorter ones
 * regardless of registration order.
 */
final class ProcModeRegistry
{
    /** @var array<string, int> prefix → mode; longest matching prefix wins at resolve */
    private static array $prefixToMode = [];

    /** @var array<string, int> per-material-id result cache */
    private static array $cache = [];

    /** Map a material-id PREFIX to a proc_mode int. */
    public static function map(string $prefix, int $mode): void
    {
        self::$prefixToMode[$prefix] = $mode;
        self::$cache = []; // registrations happen at boot, before the first draw
    }

    /**
     * Bulk register prefix → mode.
     *
     * @param array<string, int> $prefixToMode
     */
    public static function mapAll(array $prefixToMode): void
    {
        foreach ($prefixToMode as $prefix => $mode) {
            self::map($prefix, $mode);
        }
    }

    /** Resolve a material id to its proc_mode (0 = standard PBR when unmapped). */
    public static function resolve(string $materialId): int
    {
        if (isset(self::$cache[$materialId])) {
            return self::$cache[$materialId];
        }

        $prefixRaw = strtok($materialId, '0123456789');
        $prefix = $prefixRaw === false ? $materialId : $prefixRaw;

        $mode = 0;
        $bestLen = -1;
        foreach (self::$prefixToMode as $registered => $registeredMode) {
            $len = strlen($registered);
            if ($len > $bestLen && str_starts_with($prefix, $registered)) {
                $mode = $registeredMode;
                $bestLen = $len;
            }
        }

        return self::$cache[$materialId] = $mode;
    }

    public static function clear(): void
    {
        self::$prefixToMode = [];
        self::$cache = [];
    }
}
