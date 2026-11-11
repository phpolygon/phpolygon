<?php

declare(strict_types=1);

namespace PHPolygon\Visibility;

/**
 * XZ grid of bounding spheres, {@see CELL_SIZE} metre cells, flat per-cell
 * handle sets. Holds no component references and no ECS dependency - it is
 * driven purely by register()/update()/remove(), so {@see VisibilityService}
 * (World attach/detach, Transform3DSystem's moved list, MeshRenderer's
 * visible/meshId hooks) and later phases (batches, emitters, lights) can all
 * feed it the same way.
 *
 * There is no periodic full rebuild - unlike Renderer3DSystem's former 256 m
 * bins (rebuilt every 30 frames), every entry is kept current by whoever
 * calls update()/remove() in response to an actual change. A stale entry
 * only exists between an event happening and its caller observing it, not
 * between rebuild boundaries.
 */
final class SpatialIndex
{
    /** Cell size in world units (XZ only - Y is unbounded within a cell). */
    public const float CELL_SIZE = 32.0;

    /**
     * One entry per registered handle. "Eintrag = Handle, Art, Kugel, Flags,
     * Besitzer" per the visibility plan: kind and owner are carried from
     * registration but unused by Phase 2's own queries - they let a later
     * phase register batches/emitters/lights here without reshaping entries
     * again.
     *
     * @var array<int, array{
     *     kind: EntryKind,
     *     owner: ?int,
     *     cellKey: string,
     *     x: float, y: float, z: float,
     *     radius: float,
     *     unbounded: bool,
     *     alwaysVisible: bool,
     *     castsShadow: bool,
     * }>
     */
    private array $entries = [];

    /**
     * Flat per-cell sparse sets of handles, keyed "cx,cz" (same convention as
     * Renderer3DSystem's former bins). Bounded entries only - see
     * $unboundedHandles for entries whose extent isn't known.
     *
     * @var array<string, array<int, true>>
     */
    private array $cells = [];

    /**
     * Per-cell Y range and largest member radius, maintained incrementally:
     * extended in O(1) on register()/grow, recomputed by scanning the cell's
     * own (typically small) member list on remove()/shrink. Used to inflate
     * a cell's XZ test bounds by its largest member's radius the same way
     * Renderer3DSystem's old per-bin AABB did - without it, a large object
     * (e.g. a kilometre-wide water plane) registered near one cell's centre
     * would make that cell look only CELL_SIZE wide and pop out when the
     * camera reached the object's far edge.
     *
     * @var array<string, array{loY: float, hiY: float, maxRadius: float}>
     */
    private array $cellBounds = [];

    /**
     * Handles whose world extent isn't known (registered with $unbounded -
     * typically a mesh id not yet in MeshRegistry). Excluded from $cells /
     * $cellBounds entirely so they can never inflate a cell's test bounds;
     * a classification pass always treats them as visible instead.
     *
     * @var array<int, true>
     */
    private array $unboundedHandles = [];

    /**
     * Handles registered with $alwaysVisible (bypassing classification
     * outright - unused by Phase 2's own MeshRenderer-driven registration,
     * reserved for a future producer). Excluded from $cells / $cellBounds
     * exactly like $unboundedHandles.
     *
     * @var array<int, true>
     */
    private array $alwaysVisibleHandles = [];

    public static function cellKeyFor(float $x, float $z): string
    {
        $cx = (int) floor($x / self::CELL_SIZE);
        $cz = (int) floor($z / self::CELL_SIZE);
        return $cx . ',' . $cz;
    }

    /**
     * Registers (or re-registers, replacing any previous entry under the
     * same handle - see remove()) a handle at a world-space position with a
     * bounding sphere radius. $unbounded means the radius is not meaningful
     * (the real extent isn't known): the entry is kept out of every cell's
     * AABB and always passes the exact sphere test.
     */
    public function register(
        int $handle,
        float $x,
        float $y,
        float $z,
        float $radius,
        bool $unbounded = false,
        bool $alwaysVisible = false,
        bool $castsShadow = true,
        EntryKind $kind = EntryKind::Entity,
        ?int $owner = null,
    ): void {
        $this->remove($handle);

        $cellKey = self::cellKeyFor($x, $z);
        $this->entries[$handle] = [
            'kind' => $kind,
            'owner' => $owner,
            'cellKey' => $cellKey,
            'x' => $x,
            'y' => $y,
            'z' => $z,
            'radius' => $radius,
            'unbounded' => $unbounded,
            'alwaysVisible' => $alwaysVisible,
            'castsShadow' => $castsShadow,
        ];

        // Neither needs cell membership: unbounded because its extent isn't
        // known (never cull it), alwaysVisible because it bypasses
        // classification outright (e.g. a future skybox-like producer) -
        // putting either in a cell would only let it inflate that cell's
        // bounds for every sibling entry for no benefit.
        if ($unbounded) {
            $this->unboundedHandles[$handle] = true;
        }
        if ($alwaysVisible) {
            $this->alwaysVisibleHandles[$handle] = true;
        }
        if ($unbounded || $alwaysVisible) {
            return;
        }
        $this->cells[$cellKey][$handle] = true;
        $this->growCellBounds($cellKey, $y, $radius);
    }

    /**
     * Moves/resizes an already-registered handle. A no-op for a handle that
     * was never registered (or already removed) - a caller draining a moved
     * list that races a detach must not resurrect a dead entry.
     */
    public function update(int $handle, float $x, float $y, float $z, ?float $radius = null): void
    {
        $entry = $this->entries[$handle] ?? null;
        if ($entry === null) {
            return;
        }
        $this->register(
            $handle,
            $x,
            $y,
            $z,
            $radius ?? $entry['radius'],
            $entry['unbounded'],
            $entry['alwaysVisible'],
            $entry['castsShadow'],
            $entry['kind'],
            $entry['owner'],
        );
    }

    public function remove(int $handle): void
    {
        $entry = $this->entries[$handle] ?? null;
        if ($entry === null) {
            return;
        }
        unset($this->entries[$handle], $this->unboundedHandles[$handle], $this->alwaysVisibleHandles[$handle]);

        $cellKey = $entry['cellKey'];
        if (!isset($this->cells[$cellKey])) {
            return; // was unbounded - never had a cell
        }
        unset($this->cells[$cellKey][$handle]);
        if ($this->cells[$cellKey] === []) {
            unset($this->cells[$cellKey], $this->cellBounds[$cellKey]);
            return;
        }
        // The removed entry may have been the one defining the cell's Y
        // range or largest radius - recompute from the members that remain.
        // Cells are small (CELL_SIZE is 32 m), so this scan is cheap.
        $this->rebuildCellBounds($cellKey);
    }

    public function has(int $handle): bool
    {
        return isset($this->entries[$handle]);
    }

    /**
     * @return array{
     *     kind: EntryKind, owner: ?int, cellKey: string,
     *     x: float, y: float, z: float, radius: float,
     *     unbounded: bool, alwaysVisible: bool, castsShadow: bool,
     * }|null
     */
    public function entry(int $handle): ?array
    {
        return $this->entries[$handle] ?? null;
    }

    /** @return list<int> Every registered handle, regardless of kind. */
    public function allHandles(): array
    {
        return array_keys($this->entries);
    }

    /** @return list<int> Handles excluded from cell classification - unknown extent, never culled. */
    public function unboundedHandles(): array
    {
        return array_keys($this->unboundedHandles);
    }

    /** @return list<int> Handles excluded from cell classification - always visible by flag. */
    public function alwaysVisibleHandles(): array
    {
        return array_keys($this->alwaysVisibleHandles);
    }

    /** @return list<string> Every occupied (bounded) cell key. */
    public function cellKeys(): array
    {
        return array_keys($this->cells);
    }

    /** @return list<int> Handles registered in one cell. */
    public function handlesInCell(string $cellKey): array
    {
        return array_keys($this->cells[$cellKey] ?? []);
    }

    /**
     * Classifies every occupied cell against a frustum's planes: a cell is
     * OUTSIDE (absent from both returned sets - the caller skips it and
     * every handle in it), INSIDE (every member passes without an exact
     * per-entity test), or INTERSECTED (members need the exact test).
     *
     * Cell world bounds are its geometric XZ square inflated by the cell's
     * largest member radius (so a sphere poking past its own cell's edge is
     * not wrongly dropped), with the real Y range from $cellBounds.
     *
     * @param list<array{0:float,1:float,2:float,3:float}> $planes
     * @return array{0: array<string, true>, 1: array<string, true>} [insideCellKeys, intersectedCellKeys]
     */
    public function classify(array $planes): array
    {
        $inside = [];
        $intersected = [];
        foreach ($this->cells as $cellKey => $_) {
            [$cx, $cz] = array_map('intval', explode(',', $cellKey));
            $bounds = $this->cellBounds[$cellKey];
            $pad = $bounds['maxRadius'];
            $lx = $cx * self::CELL_SIZE - $pad;
            $hx = ($cx + 1) * self::CELL_SIZE + $pad;
            $lz = $cz * self::CELL_SIZE - $pad;
            $hz = ($cz + 1) * self::CELL_SIZE + $pad;
            $ly = $bounds['loY'];
            $hy = $bounds['hiY'];

            $allInside = true;
            $outside = false;
            foreach ($planes as $p) {
                $nx = $p[0]; $ny = $p[1]; $nz = $p[2]; $d = $p[3];
                $farX = $nx >= 0.0 ? $hx : $lx;
                $farY = $ny >= 0.0 ? $hy : $ly;
                $farZ = $nz >= 0.0 ? $hz : $lz;
                if ($nx * $farX + $ny * $farY + $nz * $farZ + $d < 0.0) {
                    $outside = true;
                    break;
                }
                $nearX = $nx >= 0.0 ? $lx : $hx;
                $nearY = $ny >= 0.0 ? $ly : $hy;
                $nearZ = $nz >= 0.0 ? $lz : $hz;
                if ($nx * $nearX + $ny * $nearY + $nz * $nearZ + $d < 0.0) {
                    $allInside = false;
                }
            }
            if ($outside) {
                continue;
            }
            if ($allInside) {
                $inside[$cellKey] = true;
            } else {
                $intersected[$cellKey] = true;
            }
        }
        return [$inside, $intersected];
    }

    private function growCellBounds(string $cellKey, float $y, float $radius): void
    {
        $lo = $y - $radius;
        $hi = $y + $radius;
        if (!isset($this->cellBounds[$cellKey])) {
            $this->cellBounds[$cellKey] = ['loY' => $lo, 'hiY' => $hi, 'maxRadius' => $radius];
            return;
        }
        $b = &$this->cellBounds[$cellKey];
        if ($lo < $b['loY']) { $b['loY'] = $lo; }
        if ($hi > $b['hiY']) { $b['hiY'] = $hi; }
        if ($radius > $b['maxRadius']) { $b['maxRadius'] = $radius; }
        unset($b);
    }

    private function rebuildCellBounds(string $cellKey): void
    {
        $loY = PHP_FLOAT_MAX;
        $hiY = -PHP_FLOAT_MAX;
        $maxRadius = 0.0;
        foreach ($this->cells[$cellKey] as $handle => $_) {
            $entry = $this->entries[$handle];
            $lo = $entry['y'] - $entry['radius'];
            $hi = $entry['y'] + $entry['radius'];
            if ($lo < $loY) { $loY = $lo; }
            if ($hi > $hiY) { $hiY = $hi; }
            if ($entry['radius'] > $maxRadius) { $maxRadius = $entry['radius']; }
        }
        $this->cellBounds[$cellKey] = ['loY' => $loY, 'hiY' => $hiY, 'maxRadius' => $maxRadius];
    }
}
