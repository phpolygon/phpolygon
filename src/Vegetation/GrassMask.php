<?php

declare(strict_types=1);

namespace PHPolygon\Vegetation;

use PHPolygon\Scene\Layout\Zone;

/**
 * Where grass grows: a density factor 0..1 per ground point (x, z).
 *
 * - Include areas (optional): with at least one, grass grows only inside them,
 *   fading in over the area's feather distance from its edge.
 * - Exclude areas: paths (polyline + half width), rectangles, circles, polygons
 *   and {@see Zone}s - no grass inside, a soft edge over the feather distance
 *   outside (fewer and shorter blades at a path's edge, no blade through it).
 * - Callables `fn(float $x, float $z): float` multiply in anything else the game
 *   knows (a ground-material lookup, trampled spots, a painted map).
 *
 * The mask names nothing of a game: every shape comes from the caller.
 *
 * A query costs what is near the point, not what the mask holds: beyond a few
 * edges {@see factor()} answers from tiles of {@see $tileSize} metres, built on
 * first use, each with only the shapes it touches - polygons cut to the tile,
 * paths reduced to their segments in reach. The values are the same: what is cut
 * away lies further from the tile than the feather reaches, and cutting a polygon
 * does not change what is inside it.
 *
 * Several fields over one ground share a mask through {@see multiplyMask()}: they
 * share its tiles, and an edit of it reaches {@see $version} of every mask that
 * depends on it, so their fields rebuild. A callable given to {@see multiply()} is
 * opaque, also one that wraps a mask: when what it returns changes, call
 * `GrassField::touch()`.
 *
 * @phpstan-type Area array{kind: int, feather: float, bounds: array{0: float, 1: float, 2: float, 3: float}, data: list<float>|list<array<int, float>>}
 */
final class GrassMask
{
    private const int RECT = 0;
    private const int CIRCLE = 1;
    private const int POLYGON = 2;
    private const int PATH = 3;
    /** Loose segments of a path inside a tile: `[ax, az, bx, bz]`, last entry `[half width]`. */
    private const int SEGMENTS = 4;

    /** Edges and areas from which tiles pay for themselves. */
    private const int TILED_FROM = 16;

    /** Tiles kept; beyond that the cache starts over. */
    private const int MAX_TILES = 4096;

    /** A cut area keeps this much beyond its feather, against rounding at the tile border. */
    private const float CUT_MARGIN = 0.01;

    /** @var list<Area> */
    private array $includes = [];

    /** @var list<Area> */
    private array $excludes = [];

    /** @var list<\Closure(float, float): float> */
    private array $callables = [];

    /** @var list<\Closure(float, float): float> {@see $callables} with other masks read without tiles */
    private array $exhaustive = [];

    /** @var list<self> masks this one multiplies in */
    private array $sources = [];

    /**
     * Masks that multiply this one in. Weak: a shared mask must not keep every
     * mask built on it alive.
     *
     * @var \WeakMap<self, true>
     */
    private \WeakMap $dependents;

    /**
     * Incremented by every change, of this mask and of every mask it multiplies in;
     * {@see \PHPolygon\System\GrassSystem} rebuilds its cells when it moves.
     */
    public int $version = 0;

    /** Edges and areas of all shapes. */
    private int $complexity = 0;

    /** @var array<string, array{0: list<Area>, 1: list<Area>}> tile => [includes, excludes] */
    private array $tiles = [];

    private int $tileX = PHP_INT_MIN;
    private int $tileZ = PHP_INT_MIN;

    /** @var array{0: list<Area>, 1: list<Area>} the tile asked last: a cell stays on one */
    private array $tile = [[], []];

    /** @param float $tileSize metres; the default matches the system's grass cells */
    public function __construct(public readonly float $tileSize = 12.0)
    {
        if ($tileSize <= 0.0) {
            throw new \InvalidArgumentException('The tile size must be positive.');
        }
        $this->dependents = new \WeakMap();
    }

    // ── include ──────────────────────────────────────────────────────────

    public function includeRect(float $minX, float $minZ, float $maxX, float $maxZ, float $feather = 0.0): self
    {
        return $this->add(true, self::RECT, $feather, [min($minX, $maxX), min($minZ, $maxZ), max($minX, $maxX), max($minZ, $maxZ)]);
    }

    public function includeCircle(float $x, float $z, float $radius, float $feather = 0.0): self
    {
        return $this->add(true, self::CIRCLE, $feather, [$x, $z, max(0.0, $radius)]);
    }

    /** @param list<array{0: float, 1: float}> $points (x, z) corners, any winding */
    public function includePolygon(array $points, float $feather = 0.0): self
    {
        return $this->add(true, self::POLYGON, $feather, $points);
    }

    // ── exclude ──────────────────────────────────────────────────────────

    /**
     * A path: no grass within `$halfWidth` of the polyline, a soft edge over
     * `$feather` metres beyond.
     *
     * @param list<array{0: float, 1: float}> $points (x, z)
     */
    public function excludePath(array $points, float $halfWidth, float $feather = 0.6): self
    {
        $data = [];
        foreach ($points as $p) {
            $data[] = [(float) $p[0], (float) $p[1]];
        }
        if ($data === []) {
            return $this;
        }
        $data[] = [max(0.0, $halfWidth), 0.0]; // last entry: half width
        return $this->add(false, self::PATH, $feather, $data);
    }

    public function excludeRect(float $minX, float $minZ, float $maxX, float $maxZ, float $feather = 0.4): self
    {
        return $this->add(false, self::RECT, $feather, [min($minX, $maxX), min($minZ, $maxZ), max($minX, $maxX), max($minZ, $maxZ)]);
    }

    public function excludeCircle(float $x, float $z, float $radius, float $feather = 0.4): self
    {
        return $this->add(false, self::CIRCLE, $feather, [$x, $z, max(0.0, $radius)]);
    }

    /** @param list<array{0: float, 1: float}> $points (x, z) corners, any winding */
    public function excludePolygon(array $points, float $feather = 0.4): self
    {
        return $this->add(false, self::POLYGON, $feather, $points);
    }

    /** A layout zone (its XZ footprint), e.g. a building footprint or a plaza. */
    public function excludeZone(Zone $zone, float $feather = 0.4): self
    {
        return $this->excludeRect($zone->min->x, $zone->min->z, $zone->max->x, $zone->max->z, $feather);
    }

    /**
     * Multiply a game density in, 0..1 per point (values outside are clamped).
     *
     * @param callable(float, float): float $density
     */
    public function multiply(callable $density): self
    {
        $closure = \Closure::fromCallable($density);
        $this->callables[] = $closure;
        $this->exhaustive[] = $closure;
        $this->changed();
        return $this;
    }

    /**
     * Multiply another mask in: the way for several fields to share one. Its edits
     * count as edits of this mask, and queries run on its tiles, kept in one place.
     *
     * @throws \InvalidArgumentException when `$other` depends on this mask
     */
    public function multiplyMask(self $other): self
    {
        if ($other === $this || $other->dependsOn($this)) {
            throw new \InvalidArgumentException('A grass mask cannot multiply itself in.');
        }
        $this->sources[] = $other;
        $other->dependents[$this] = true;
        $this->callables[] = $other->factor(...);
        $this->exhaustive[] = $other->factorExhaustive(...);
        $this->changed();
        return $this;
    }

    // ── query ────────────────────────────────────────────────────────────

    /** Density factor at (x, z): 0 = no grass, 1 = full. */
    public function factor(float $x, float $z): float
    {
        if ($this->complexity < self::TILED_FROM) {
            return $this->evaluate($this->includes, $this->excludes, $this->callables, $x, $z);
        }
        $tx = (int) floor($x / $this->tileSize);
        $tz = (int) floor($z / $this->tileSize);
        if ($tx !== $this->tileX || $tz !== $this->tileZ) {
            $this->tile = $this->tileAt($tx, $tz);
            $this->tileX = $tx;
            $this->tileZ = $tz;
        }
        return $this->evaluate($this->tile[0], $this->tile[1], $this->callables, $x, $z);
    }

    /** Whether nothing restricts the grass (no areas, no callables). */
    public function isOpen(): bool
    {
        return $this->includes === [] && $this->excludes === [] && $this->callables === [];
    }

    /** The factor without tiles, of multiplied masks neither: every shape, every edge. The reference for tests and benches. */
    public function factorExhaustive(float $x, float $z): float
    {
        return $this->evaluate($this->includes, $this->excludes, $this->exhaustive, $x, $z);
    }

    /** Tiles in memory (tests and profiles). */
    public function tileCount(): int
    {
        return count($this->tiles);
    }

    // ── internals ────────────────────────────────────────────────────────

    /**
     * @param list<Area> $includes
     * @param list<Area> $excludes
     * @param list<\Closure(float, float): float> $callables
     */
    private function evaluate(array $includes, array $excludes, array $callables, float $x, float $z): float
    {
        $f = 1.0;
        if ($this->includes !== []) {
            $best = 0.0;
            foreach ($includes as $area) {
                [$x0, $z0, $x1, $z1] = $area['bounds'];
                if ($x < $x0 || $x > $x1 || $z < $z0 || $z > $z1) {
                    continue;
                }
                $sd = self::signedDistance($area, $x, $z);
                if ($sd >= 0.0) {
                    continue;
                }
                $best = max($best, $area['feather'] > 0.0 ? self::smooth(-$sd / $area['feather']) : 1.0);
                if ($best >= 1.0) {
                    break;
                }
            }
            $f = $best;
            if ($f <= 0.0) {
                return 0.0;
            }
        }
        foreach ($excludes as $area) {
            [$x0, $z0, $x1, $z1] = $area['bounds'];
            $m = $area['feather'];
            if ($x < $x0 - $m || $x > $x1 + $m || $z < $z0 - $m || $z > $z1 + $m) {
                continue;
            }
            $sd = self::signedDistance($area, $x, $z);
            if ($sd <= 0.0) {
                return 0.0;
            }
            if ($sd < $m) {
                $f *= self::smooth($sd / $m);
            }
        }
        foreach ($callables as $c) {
            $f *= max(0.0, min(1.0, $c($x, $z)));
            if ($f <= 0.0) {
                return 0.0;
            }
        }
        return $f;
    }

    private function dependsOn(self $mask): bool
    {
        foreach ($this->sources as $source) {
            if ($source === $mask || $source->dependsOn($mask)) {
                return true;
            }
        }
        return false;
    }

    /** Count a change here and in every mask built on this one. */
    private function changed(): void
    {
        $this->version++;
        foreach ($this->dependents as $mask => $_) {
            $mask->changed();
        }
    }

    /**
     * @return array{0: list<Area>, 1: list<Area>}
     */
    private function tileAt(int $tx, int $tz): array
    {
        $key = $tx . ':' . $tz;
        if (isset($this->tiles[$key])) {
            return $this->tiles[$key];
        }
        if (count($this->tiles) >= self::MAX_TILES) {
            $this->tiles = [];
        }
        $x0 = $tx * $this->tileSize;
        $z0 = $tz * $this->tileSize;
        $x1 = $x0 + $this->tileSize;
        $z1 = $z0 + $this->tileSize;
        $tile = [[], []];
        foreach ([$this->includes, $this->excludes] as $side => $areas) {
            foreach ($areas as $area) {
                $cut = self::cut($area, $x0, $z0, $x1, $z1);
                if ($cut !== null) {
                    $tile[$side][] = $cut;
                }
            }
        }
        return $this->tiles[$key] = $tile;
    }

    /**
     * What a tile needs of an area, null if nothing. Only for points inside the
     * tile, where it gives the same factor as the whole area: a dropped edge is
     * further away than the feather, and so is any edge the cut adds.
     *
     * @param Area $area
     * @return Area|null
     */
    private static function cut(array $area, float $x0, float $z0, float $x1, float $z1): ?array
    {
        $m = $area['feather'] + self::CUT_MARGIN;
        $x0 -= $m;
        $z0 -= $m;
        $x1 += $m;
        $z1 += $m;
        [$ax0, $az0, $ax1, $az1] = $area['bounds'];
        if ($ax1 < $x0 || $ax0 > $x1 || $az1 < $z0 || $az0 > $z1) {
            return null;
        }
        if ($area['kind'] === self::POLYGON) {
            $points = [];
            foreach ($area['data'] as $p) {
                if (is_array($p)) {
                    $points[] = [$p[0], $p[1]];
                }
            }
            $data = self::clip($points, $x0, $z0, $x1, $z1);
            if (count($data) < 3) {
                return null;
            }
            return ['kind' => self::POLYGON, 'feather' => $area['feather'], 'bounds' => self::bounds(self::POLYGON, $data), 'data' => $data];
        }
        if ($area['kind'] === self::PATH) {
            return self::cutPath($area, $x0, $z0, $x1, $z1);
        }
        return $area;
    }

    /**
     * The segments of a path in reach of a rectangle, as loose segments.
     *
     * @param Area $area
     * @return Area|null
     */
    private static function cutPath(array $area, float $x0, float $z0, float $x1, float $z1): ?array
    {
        $points = [];
        foreach ($area['data'] as $p) {
            if (is_array($p)) {
                $points[] = [$p[0], $p[1]];
            }
        }
        $last = array_pop($points);
        $half = $last[0] ?? 0.0;
        if (count($points) === 1) {
            $points[] = $points[0]; // a single point: a segment of length 0
        }
        $x0 -= $half;
        $z0 -= $half;
        $x1 += $half;
        $z1 += $half;
        $segments = [];
        for ($i = 0, $n = count($points); $i + 1 < $n; $i++) {
            [$ax, $az] = $points[$i];
            [$bx, $bz] = $points[$i + 1];
            if (max($ax, $bx) < $x0 || min($ax, $bx) > $x1 || max($az, $bz) < $z0 || min($az, $bz) > $z1) {
                continue;
            }
            $segments[] = [$ax, $az, $bx, $bz];
        }
        if ($segments === []) {
            return null;
        }
        $bx0 = $bz0 = INF;
        $bx1 = $bz1 = -INF;
        foreach ($segments as [$ax, $az, $bx, $bz]) {
            $bx0 = min($bx0, $ax, $bx);
            $bz0 = min($bz0, $az, $bz);
            $bx1 = max($bx1, $ax, $bx);
            $bz1 = max($bz1, $az, $bz);
        }
        $segments[] = [$half];
        return ['kind' => self::SEGMENTS, 'feather' => $area['feather'], 'bounds' => [$bx0 - $half, $bz0 - $half, $bx1 + $half, $bz1 + $half], 'data' => $segments];
    }

    /**
     * Sutherland-Hodgman against a rectangle. Clipping a concave polygon leaves
     * bridges along the rectangle's border: they lie outside the tile by more than
     * the feather and go through it in pairs, so neither the distance nor the
     * inside test of a point in the tile notices them.
     *
     * @param list<array{0: float, 1: float}> $points
     * @return list<array{0: float, 1: float}>
     */
    private static function clip(array $points, float $minX, float $minZ, float $maxX, float $maxZ): array
    {
        foreach ([[0, $minX, 1.0], [0, $maxX, -1.0], [1, $minZ, 1.0], [1, $maxZ, -1.0]] as [$axis, $limit, $sign]) {
            $kept = [];
            $n = count($points);
            for ($i = 0; $i < $n; $i++) {
                $a = $points[$i];
                $b = $points[($i + 1) % $n];
                $da = ($a[$axis] - $limit) * $sign;
                $db = ($b[$axis] - $limit) * $sign;
                if ($da >= 0.0) {
                    $kept[] = $a;
                }
                if (($da >= 0.0) !== ($db >= 0.0)) {
                    $t = $da / ($da - $db);
                    $kept[] = [$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t];
                }
            }
            $points = $kept;
            if ($points === []) {
                return [];
            }
        }
        return $points;
    }

    /**
     * @param list<float>|list<array{0: float, 1: float}> $data
     */
    private function add(bool $include, int $kind, float $feather, array $data): self
    {
        if ($kind === self::POLYGON) {
            $pts = [];
            foreach ($data as $p) {
                if (is_array($p)) {
                    $pts[] = [(float) $p[0], (float) $p[1]];
                }
            }
            if (count($pts) < 3) {
                return $this;
            }
            $data = $pts;
        }
        $area = ['kind' => $kind, 'feather' => max(0.0, $feather), 'bounds' => self::bounds($kind, $data), 'data' => $data];
        if ($include) {
            $this->includes[] = $area;
        } else {
            $this->excludes[] = $area;
        }
        $this->complexity += $kind === self::RECT || $kind === self::CIRCLE ? 1 : count($data);
        $this->tiles = [];
        $this->tile = [[], []];
        $this->tileX = $this->tileZ = PHP_INT_MIN;
        $this->changed();
        return $this;
    }

    /**
     * @param list<float>|list<array<int, float>> $data
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private static function bounds(int $kind, array $data): array
    {
        if ($kind === self::RECT) {
            return [self::f($data[0]), self::f($data[1]), self::f($data[2]), self::f($data[3])];
        }
        if ($kind === self::CIRCLE) {
            $x = self::f($data[0]);
            $z = self::f($data[1]);
            $r = self::f($data[2]);
            return [$x - $r, $z - $r, $x + $r, $z + $r];
        }
        $pts = $data;
        $pad = 0.0;
        if ($kind === self::PATH) {
            $last = array_pop($pts);
            $pad = is_array($last) ? $last[0] : 0.0;
        }
        $x0 = $z0 = INF;
        $x1 = $z1 = -INF;
        foreach ($pts as $p) {
            if (!is_array($p)) {
                continue;
            }
            $x0 = min($x0, $p[0]);
            $z0 = min($z0, $p[1]);
            $x1 = max($x1, $p[0]);
            $z1 = max($z1, $p[1]);
        }
        return [$x0 - $pad, $z0 - $pad, $x1 + $pad, $z1 + $pad];
    }

    private static function f(mixed $v): float
    {
        return is_float($v) || is_int($v) ? (float) $v : 0.0;
    }

    /**
     * Signed distance to the area's edge in the XZ plane: negative inside.
     *
     * @param Area $area
     */
    private static function signedDistance(array $area, float $x, float $z): float
    {
        $d = $area['data'];
        switch ($area['kind']) {
            case self::RECT:
                $x0 = self::f($d[0]);
                $z0 = self::f($d[1]);
                $x1 = self::f($d[2]);
                $z1 = self::f($d[3]);
                $dx = max($x0 - $x, $x - $x1);
                $dz = max($z0 - $z, $z - $z1);
                if ($dx <= 0.0 && $dz <= 0.0) {
                    return max($dx, $dz);
                }
                return sqrt(max($dx, 0.0) ** 2 + max($dz, 0.0) ** 2);
            case self::CIRCLE:
                return sqrt(($x - self::f($d[0])) ** 2 + ($z - self::f($d[1])) ** 2) - self::f($d[2]);
            case self::PATH:
                $pts = $d;
                $last = array_pop($pts);
                $half = is_array($last) ? $last[0] : 0.0;
                $best = INF;
                $n = count($pts);
                if ($n === 1 && is_array($pts[0])) {
                    $best = sqrt(($x - $pts[0][0]) ** 2 + ($z - $pts[0][1]) ** 2);
                }
                for ($i = 0; $i + 1 < $n; $i++) {
                    $a = $pts[$i];
                    $b = $pts[$i + 1];
                    if (!is_array($a) || !is_array($b)) {
                        continue;
                    }
                    $best = min($best, self::segmentDistance($x, $z, $a[0], $a[1], $b[0], $b[1]));
                }
                return $best - $half;
            case self::SEGMENTS:
                $best = INF;
                $half = 0.0;
                foreach ($d as $s) {
                    if (!is_array($s)) {
                        continue;
                    }
                    if (count($s) === 1) {
                        $half = $s[0];
                        continue;
                    }
                    $best = min($best, self::segmentDistance($x, $z, $s[0], $s[1], $s[2], $s[3]));
                }
                return $best - $half;
            default:
                $pts = [];
                foreach ($d as $p) {
                    if (is_array($p)) {
                        $pts[] = $p;
                    }
                }
                $n = count($pts);
                $best = INF;
                $inside = false;
                for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                    [$xi, $zi] = $pts[$i];
                    [$xj, $zj] = $pts[$j];
                    if ((($zi > $z) !== ($zj > $z)) && ($x < ($xj - $xi) * ($z - $zi) / (($zj - $zi) ?: 1e-12) + $xi)) {
                        $inside = !$inside;
                    }
                    $best = min($best, self::segmentDistance($x, $z, $xi, $zi, $xj, $zj));
                }
                return $inside ? -$best : $best;
        }
    }

    private static function segmentDistance(float $x, float $z, float $ax, float $az, float $bx, float $bz): float
    {
        $vx = $bx - $ax;
        $vz = $bz - $az;
        $len2 = $vx * $vx + $vz * $vz;
        $t = $len2 > 1e-12 ? max(0.0, min(1.0, (($x - $ax) * $vx + ($z - $az) * $vz) / $len2)) : 0.0;
        $dx = $ax + $vx * $t - $x;
        $dz = $az + $vz * $t - $z;
        return sqrt($dx * $dx + $dz * $dz);
    }

    private static function smooth(float $t): float
    {
        $t = max(0.0, min(1.0, $t));
        return $t * $t * (3.0 - 2.0 * $t);
    }
}
