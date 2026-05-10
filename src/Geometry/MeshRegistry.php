<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * Mesh registry with eager + lazy registration paths.
 *
 * Eager (legacy):
 *   `register('crate', BoxMesh::generate(1, 1, 1))`
 *   The MeshData object is held in the registry from the moment of
 *   registration and stays in PHP memory for the process lifetime.
 *
 * Lazy:
 *   `registerLazy('crate', static fn() => BoxMesh::generate(1, 1, 1))`
 *   The factory is stored; the actual MeshData is generated only the
 *   first time `get()` is called for that id. Once materialised, it
 *   stays in memory like any eager mesh.
 *
 * Why lazy: large procedural worlds register hundreds of meshes during
 * scene build. Eager registration runs every generator (and pays its
 * RAM cost) up front. Lazy lets the engine show a "Loading meshes
 * X/Y" progress bar during the splash screen while iterating
 * `pendingIds()` and calling `prefetch()` per id, then drops back to
 * O(1) lookup at runtime - same as the eager path.
 *
 * Versions: every materialisation (eager or lazy) bumps the per-id
 * version counter, so renderers that cache GPU buffers can invalidate
 * cleanly on re-registration without touching the eager/lazy split.
 */
class MeshRegistry
{
    /** @var array<string, MeshData> */
    private static array $registry = [];

    /**
     * @var array<string, callable(): MeshData> Factories that have been
     *      registered but not yet materialised. Drained into `$registry`
     *      on first `get()` or explicit `prefetch()`.
     */
    private static array $pendingFactories = [];

    /** @var array<string, int> Increments on every (re-)materialisation. */
    private static array $versions = [];

    public static function register(string $id, MeshData $mesh): void
    {
        self::$registry[$id] = $mesh;
        unset(self::$pendingFactories[$id]);
        self::$versions[$id] = (self::$versions[$id] ?? 0) + 1;
    }

    /**
     * Register a mesh factory without running it. The factory must
     * return a MeshData when invoked. The first `get()` for this id
     * (or an explicit `prefetch()` call) materialises the result.
     *
     * @param callable(): MeshData $factory
     */
    public static function registerLazy(string $id, callable $factory): void
    {
        // If something was already registered under this id, drop it so
        // the factory is the new source of truth on next access.
        unset(self::$registry[$id]);
        self::$pendingFactories[$id] = $factory;
    }

    public static function get(string $id): ?MeshData
    {
        if (isset(self::$registry[$id])) {
            return self::$registry[$id];
        }
        if (isset(self::$pendingFactories[$id])) {
            $factory = self::$pendingFactories[$id];
            $mesh = $factory();
            self::$registry[$id] = $mesh;
            unset(self::$pendingFactories[$id]);
            self::$versions[$id] = (self::$versions[$id] ?? 0) + 1;
            return $mesh;
        }
        return null;
    }

    public static function has(string $id): bool
    {
        return isset(self::$registry[$id]) || isset(self::$pendingFactories[$id]);
    }

    /**
     * Force the lazy factory for $id to run now, materialising the
     * MeshData into the registry. Returns true when something was
     * actually materialised, false when the id was already eager or
     * unknown.
     *
     * Use during a loading screen: iterate `pendingIds()` and call
     * `prefetch()` per id with a progress callback.
     */
    public static function prefetch(string $id): bool
    {
        if (!isset(self::$pendingFactories[$id])) {
            return false;
        }
        self::get($id);
        return true;
    }

    /**
     * Monotonic version counter, incremented every time a mesh is
     * materialised under this id (eager register OR lazy resolve OR
     * re-register). Renderers compare against the last uploaded version
     * to decide whether to re-upload the GPU buffer for dynamic meshes.
     */
    public static function version(string $id): int
    {
        return self::$versions[$id] ?? 0;
    }

    public static function clear(): void
    {
        self::$registry = [];
        self::$pendingFactories = [];
        self::$versions = [];
    }

    /** @return list<string> All known ids - both materialised and pending. */
    public static function ids(): array
    {
        $ids = array_keys(self::$registry);
        foreach (self::$pendingFactories as $id => $_) {
            if (!isset(self::$registry[$id])) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /** @return list<string> Only the ids whose factory has not yet run. */
    public static function pendingIds(): array
    {
        return array_keys(self::$pendingFactories);
    }

    /** Number of ids waiting to be materialised. */
    public static function pendingCount(): int
    {
        return count(self::$pendingFactories);
    }

    /** Number of ids currently held in PHP memory as MeshData. */
    public static function materialisedCount(): int
    {
        return count(self::$registry);
    }

    /**
     * Materialise every pending lazy mesh, calling $progress between
     * each one. Built for splash-screen integration:
     *
     * ```php
     * MeshRegistry::prefetchAll(function (int $done, int $total, string $id) use ($engine) {
     *     $engine->setSplashProgress($done / $total, "Mesh {$id} ({$done}/{$total})");
     * });
     * ```
     *
     * Callback receives done count (1..total), total pending at start,
     * and the id just materialised. Already-materialised ids are
     * skipped. Returns how many meshes the call materialised.
     *
     * @param ?callable(int, int, string): void $progress
     */
    public static function prefetchAll(?callable $progress = null): int
    {
        // Snapshot pending; get() mutates the map.
        $pending = self::pendingIds();
        $total   = count($pending);
        $done    = 0;
        foreach ($pending as $id) {
            self::get($id);
            $done++;
            if ($progress !== null) {
                $progress($done, $total, $id);
            }
        }
        return $done;
    }
}
