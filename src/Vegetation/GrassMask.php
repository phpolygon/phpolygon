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
 */
final class GrassMask
{
    private const int RECT = 0;
    private const int CIRCLE = 1;
    private const int POLYGON = 2;
    private const int PATH = 3;

    /**
     * @var list<array{kind: int, feather: float, bounds: array{0: float, 1: float, 2: float, 3: float}, data: list<float>|list<array{0: float, 1: float}>}>
     */
    private array $includes = [];

    /**
     * @var list<array{kind: int, feather: float, bounds: array{0: float, 1: float, 2: float, 3: float}, data: list<float>|list<array{0: float, 1: float}>}>
     */
    private array $excludes = [];

    /** @var list<\Closure(float, float): float> */
    private array $callables = [];

    /** Incremented by every change; {@see \PHPolygon\System\GrassSystem} rebuilds its cells when it moves. */
    public int $version = 0;

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
        $this->callables[] = \Closure::fromCallable($density);
        $this->version++;
        return $this;
    }

    // ── query ────────────────────────────────────────────────────────────

    /** Density factor at (x, z): 0 = no grass, 1 = full. */
    public function factor(float $x, float $z): float
    {
        $f = 1.0;
        if ($this->includes !== []) {
            $best = 0.0;
            foreach ($this->includes as $area) {
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
        foreach ($this->excludes as $area) {
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
        foreach ($this->callables as $c) {
            $f *= max(0.0, min(1.0, $c($x, $z)));
            if ($f <= 0.0) {
                return 0.0;
            }
        }
        return $f;
    }

    /** Whether nothing restricts the grass (no areas, no callables). */
    public function isOpen(): bool
    {
        return $this->includes === [] && $this->excludes === [] && $this->callables === [];
    }

    // ── internals ────────────────────────────────────────────────────────

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
        $this->version++;
        return $this;
    }

    /**
     * @param list<float>|list<array{0: float, 1: float}> $data
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
     * @param array{kind: int, feather: float, bounds: array{0: float, 1: float, 2: float, 3: float}, data: list<float>|list<array{0: float, 1: float}>} $area
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
