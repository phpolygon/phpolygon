<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Command\DrawMeshInstanced;

/**
 * CPU visibility of whole instanced batches: one sphere test per batch and
 * pass, before a renderer resolves, uploads or binds anything for it.
 *
 * Per-entity draws are frustum-culled where the command list is built
 * (Renderer3DSystem); instanced batches reach the renderer from many systems
 * and from game code, so the renderer tests them itself - nobody has to hand
 * in a bound. This is the coarse counterpart of the per-INSTANCE GPU cull
 * (System\GpuInstanceCuller), which still issues its draw and dispatches for
 * every batch: hundreds of small batches spread over a large world cost CPU
 * submit time from every viewpoint unless they are dropped here.
 *
 * Where the sphere of a batch comes from:
 *   - {@see DrawMeshInstanced::$bounds} when the caller gave one (the only way
 *     for a storage-buffer batch); {@see BatchBounds::unbounded()} = never culled.
 *   - Static batches: derived once from matrices and mesh, cached under the
 *     batch's static key and re-derived when the batch changes - by the rule
 *     the renderer's static instance cache uses (flat and packed data compared
 *     whole, Mat4 batches by count and first/last matrix).
 *   - Non-static batches: derived per frame up to {@see DYNAMIC_DERIVE_MAX}
 *     instances; larger ones are never culled without an explicit bound (the
 *     per-frame walk over their matrices would cost what the cull saves).
 *   - Never derived for a material whose vertex stage moves vertices out of
 *     the mesh's own extent (sway, cloth, waves, fur, displacement, a custom
 *     shader): such a batch is culled only with an explicit bound.
 *
 * A batch without a sphere is always drawn. PHPOLYGON_NO_CULL=1 switches every
 * test off.
 */
final class InstancedBatchCuller
{
    /**
     * Largest non-static batch whose sphere is derived every frame. A design
     * value, not a measured break-even.
     */
    public const int DYNAMIC_DERIVE_MAX = 32;

    private bool $enabled = true;

    private bool $derive = true;

    /** Sphere of the batch last passed to resolve(): x, y, z, radius. */
    private float $sx = 0.0;
    private float $sy = 0.0;
    private float $sz = 0.0;
    private float $sr = 0.0;

    /**
     * Derived spheres of static batches with a static key: key => [mesh id,
     * mesh radius, instanceCount, flat matrices, packed matrices, Mat4 count,
     * first Mat4, last Mat4, sphere (null = none)].
     *
     * @var array<string, array{0: string, 1: float, 2: int, 3: array<float>, 4: string, 5: int, 6: ?Mat4, 7: ?Mat4, 8: array{0: float, 1: float, 2: float, 3: float}|null}>
     */
    private array $keyed = [];

    /**
     * The same for static batches without a key: mesh id => material id => entry.
     *
     * @var array<string, array<string, array{0: string, 1: float, 2: int, 3: array<float>, 4: string, 5: int, 6: ?Mat4, 7: ?Mat4, 8: array{0: float, 1: float, 2: float, 3: float}|null}>>
     */
    private array $unkeyed = [];

    /**
     * Spheres derived this frame for non-static batches, by object id of the
     * command (false = none): the shadow and colour tests share one walk.
     *
     * @var array<int, array{0: float, 1: float, 2: float, 3: float}|false>
     */
    private array $frameDerived = [];

    /** @var array<string, bool> material id => its vertex stage leaves the mesh extent (this frame) */
    private array $frameDisplacing = [];

    private int $batches = 0;
    private int $culledView = 0;
    private int $culledShadow = 0;
    private int $derived = 0;

    /**
     * Start a frame: forget the per-frame results and counters.
     *
     * @param bool $deriveBounds false while a program is active whose vertex
     *                           stage the engine does not know (a custom shader
     *                           override): only explicit bounds cull then
     */
    public function beginFrame(bool $deriveBounds = true): void
    {
        $this->enabled = getenv('PHPOLYGON_NO_CULL') !== '1';
        $this->derive = $deriveBounds;
        $this->frameDerived = [];
        $this->frameDisplacing = [];
        $this->batches = 0;
        $this->culledView = 0;
        $this->culledShadow = 0;
        $this->derived = 0;
    }

    /**
     * The command list without the instanced batches that lie completely
     * outside the view volume. Returns $commands itself when nothing is dropped.
     *
     * @param list<object> $commands
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $planes inward planes of the view
     *        volume ({@see Frustum::planes()}); the far plane is one of them
     * @return list<object>
     */
    public function visible(array $commands, array $planes): array
    {
        $out = null;
        foreach ($commands as $i => $cmd) {
            if ($cmd instanceof DrawMeshInstanced) {
                $this->batches++;
                if ($this->enabled
                    && $this->resolve($cmd)
                    && Frustum::sphereOutside($planes, $this->sx, $this->sy, $this->sz, $this->sr)
                ) {
                    $this->culledView++;
                    $out ??= array_slice($commands, 0, $i);
                    continue;
                }
            }
            if ($out !== null) {
                $out[] = $cmd;
            }
        }

        return $out ?? $commands;
    }

    /**
     * Whether the batch can reach the volume bounded by these inward planes -
     * a shadow map's light volume. False only when its sphere, grown by
     * $margin, lies completely outside one of them.
     *
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $planes
     */
    public function reachesPlanes(DrawMeshInstanced $cmd, array $planes, float $margin = 0.0): bool
    {
        if (!$this->enabled || !$this->resolve($cmd)) {
            return true;
        }
        if (Frustum::sphereOutside($planes, $this->sx, $this->sy, $this->sz, $this->sr + $margin)) {
            $this->culledShadow++;
            return false;
        }

        return true;
    }

    /**
     * Whether the batch touches one of these spheres - the ranges of the local
     * lights that render a shadow map. No spheres: nothing is known, true.
     *
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $spheres x, y, z, radius
     */
    public function reachesSpheres(DrawMeshInstanced $cmd, array $spheres): bool
    {
        if (!$this->enabled || $spheres === [] || !$this->resolve($cmd)) {
            return true;
        }
        foreach ($spheres as $s) {
            $dx = $s[0] - $this->sx;
            $dy = $s[1] - $this->sy;
            $dz = $s[2] - $this->sz;
            $reach = $s[3] + $this->sr;
            // Written so that a NaN keeps the batch.
            if (!($dx * $dx + $dy * $dy + $dz * $dz > $reach * $reach)) {
                return true;
            }
        }
        $this->culledShadow++;

        return false;
    }

    /**
     * The sphere a batch is tested with this frame, or null when it has none
     * (and is always drawn). For tests and diagnostics.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|null x, y, z, radius
     */
    public function sphereOf(DrawMeshInstanced $cmd): ?array
    {
        return $this->enabled && $this->resolve($cmd) ? [$this->sx, $this->sy, $this->sz, $this->sr] : null;
    }

    /**
     * Drop the derived sphere stored under a static key, or all of them - with
     * the renderer's static instance buffers (a Mat4 batch edited in a way its
     * count and first/last matrix do not show, a scene unload).
     */
    public function forget(?string $staticKey = null): void
    {
        if ($staticKey === null) {
            $this->keyed = [];
            $this->unkeyed = [];
            return;
        }
        unset($this->keyed[$staticKey]);
    }

    /** Number of static batches with a stored sphere (for tests and diagnostics). */
    public function cachedCount(): int
    {
        $n = count($this->keyed);
        foreach ($this->unkeyed as $byMaterial) {
            $n += count($byMaterial);
        }

        return $n;
    }

    /**
     * Counters since beginFrame(): instanced batches seen by visible(), those it
     * dropped, casters refused by the reaches*() tests (once per caster set),
     * and spheres derived from matrices (0 in a frame answered from the cache).
     *
     * @return array{batches: int, culledView: int, culledShadow: int, derived: int}
     */
    public function stats(): array
    {
        return [
            'batches' => $this->batches,
            'culledView' => $this->culledView,
            'culledShadow' => $this->culledShadow,
            'derived' => $this->derived,
        ];
    }

    /** Put the batch's sphere into $sx..$sr; false when it has none. */
    private function resolve(DrawMeshInstanced $cmd): bool
    {
        $bounds = $cmd->bounds;
        if ($bounds !== null) {
            // Unbounded, negative or NaN: not a sphere to cull with.
            if (!($bounds->radius >= 0.0 && $bounds->radius < INF)) {
                return false;
            }
            $this->sx = $bounds->x;
            $this->sy = $bounds->y;
            $this->sz = $bounds->z;
            $this->sr = $bounds->radius;
            return true;
        }
        if (!$this->derive || $cmd->storageBuffer !== null) {
            return false;
        }
        if ($this->frameDisplacing[$cmd->materialId] ??= self::displacesVertices($cmd->materialId)) {
            return false;
        }
        $sphere = $cmd->isStatic ? $this->staticSphere($cmd) : $this->dynamicSphere($cmd);
        if ($sphere === null) {
            return false;
        }
        [$this->sx, $this->sy, $this->sz, $this->sr] = $sphere;

        return true;
    }

    /**
     * True when the material's vertex stage can move a vertex out of the
     * sphere `instance origin, mesh radius * scale`: the derived bound would
     * not hold.
     */
    private static function displacesVertices(string $materialId): bool
    {
        $m = MaterialRegistry::get($materialId);
        if ($m === null) {
            return true;
        }

        return $m->shader !== 'default'
            || $m->cloth
            || $m->foliageWind
            || $m->grass
            || $m->bodyWave !== null
            || $m->breathing[0] > 0.0
            || $m->furLength > 0.0
            || $m->tessellation > 0.0
            || $m->vatTexture !== null
            || $m->oceanTexture !== null
            // Water: the built-in wave animation lifts the surface.
            || ProcModeRegistry::resolve($materialId) === 2;
    }

    /** @return array{0: float, 1: float, 2: float, 3: float}|null */
    private function staticSphere(DrawMeshInstanced $cmd): ?array
    {
        $radius = BatchBounds::meshRadius($cmd->meshId);
        if ($radius === null) {
            return null;
        }
        $key = $cmd->staticKey;
        $entry = $key !== null
            ? ($this->keyed[$key] ?? null)
            : ($this->unkeyed[$cmd->meshId][$cmd->materialId] ?? null);
        if ($entry !== null
            && $entry[0] === $cmd->meshId
            && $entry[1] === $radius
            && $entry[2] === $cmd->instanceCount
            && $entry[3] === $cmd->flatMatrices
            && $entry[4] === $cmd->packedMatrices
            && self::sameMatrices($entry[5], $entry[6], $entry[7], $cmd->matrices)
        ) {
            return $entry[8];
        }

        $matrices = $cmd->matrices;
        $entry = [
            $cmd->meshId,
            $radius,
            $cmd->instanceCount,
            $cmd->flatMatrices,
            $cmd->packedMatrices,
            count($matrices),
            $matrices === [] ? null : $matrices[array_key_first($matrices)],
            $matrices === [] ? null : $matrices[array_key_last($matrices)],
            $this->deriveSphere($cmd, $radius),
        ];
        if ($key !== null) {
            $this->keyed[$key] = $entry;
        } else {
            $this->unkeyed[$cmd->meshId][$cmd->materialId] = $entry;
        }

        return $entry[8];
    }

    /**
     * The renderer's rule for an unchanged static Mat4 batch: same count and the
     * same first/last matrix - by object, else by value.
     *
     * @param array<int|string, Mat4> $matrices
     */
    private static function sameMatrices(int $count, ?Mat4 $first, ?Mat4 $last, array $matrices): bool
    {
        if (count($matrices) !== $count) {
            return false;
        }
        $firstKey = array_key_first($matrices);
        $lastKey = array_key_last($matrices);
        if ($first === null || $last === null || $firstKey === null || $lastKey === null) {
            return $matrices === [];
        }
        $a = $matrices[$firstKey];
        $b = $matrices[$lastKey];
        if ($a === $first && $b === $last) {
            return true;
        }

        return $a->toArray() === $first->toArray() && $b->toArray() === $last->toArray();
    }

    /** @return array{0: float, 1: float, 2: float, 3: float}|null */
    private function dynamicSphere(DrawMeshInstanced $cmd): ?array
    {
        $count = $cmd->instanceCount >= 0 ? $cmd->instanceCount : count($cmd->matrices);
        if ($count <= 0 || $count > self::DYNAMIC_DERIVE_MAX) {
            return null;
        }
        $id = spl_object_id($cmd);
        $memo = $this->frameDerived[$id] ?? null;
        if ($memo !== null) {
            return $memo === false ? null : $memo;
        }
        $radius = BatchBounds::meshRadius($cmd->meshId);
        $sphere = $radius === null ? null : $this->deriveSphere($cmd, $radius);
        $this->frameDerived[$id] = $sphere ?? false;

        return $sphere;
    }

    /** @return array{0: float, 1: float, 2: float, 3: float}|null */
    private function deriveSphere(DrawMeshInstanced $cmd, float $meshRadius): ?array
    {
        $this->derived++;
        if ($cmd->packedMatrices !== '') {
            return BatchBounds::sphereOfFlat($cmd->flatMatricesResolved(), max(0, $cmd->instanceCount), $meshRadius);
        }
        if ($cmd->flatMatrices !== []) {
            return BatchBounds::sphereOfFlat($cmd->flatMatrices, $cmd->effectiveInstanceCount(), $meshRadius);
        }

        return BatchBounds::sphereOfMatrices($cmd->matrices, $meshRadius);
    }
}
