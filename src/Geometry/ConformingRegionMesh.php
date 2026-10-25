<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * Surfaces that follow a terrain and whose outline comes from distance fields –
 * paths, plazas, yards, borders, inlays, hems. The low-level grid; for the
 * usual "several surfaces side by side, each with a hem into the ground" use
 * {@see ConformingSurfaceSet}, which builds on it.
 *
 * How it works:
 * 1. A regular grid in XZ carries named scalar fields (e.g. "distance to the
 *    main path minus its half width"). {@see stamp()} / {@see stampShape()}
 *    write a shape in with a minimum – several shapes of one class merge
 *    without overlap; {@see derive()} builds fields from others (differences,
 *    unions, offsets).
 * 2. {@see mesh()} clips every grid cell (two triangles) against the
 *    conditions of a region (Sutherland–Hodgman against the linearly
 *    interpolated field, "marching triangles"). Because every field is
 *    interpolated linearly within a cell, the cut edges of two regions that
 *    carry the same condition with opposite sign lie exactly on top of each
 *    other – no gap, no overlap, shared vertex positions.
 * 3. Every vertex gets its height from a callback (typically: the ground at
 *    exactly that point plus an offset, {@see onGround()}; a hem falling below
 *    the ground, {@see hem()}). Normals are smoothed over shared vertices and
 *    point up (winding counter-clockwise seen from above = CCW-outward), UVs are
 *    world XZ times a scale, so neighbouring meshes tile without a seam.
 *
 * Between two grid corners the surface is a plane while the ground may bend:
 * the cell size bounds how far a surface lifts off at a terrain crease
 * (measure with {@see SurfaceFit}). Grids built with {@see covering()} snap to
 * multiples of the cell, so two grids with the same cell share their corners.
 */
final class ConformingRegionMesh
{
    /** Value of a field where nothing was stamped. */
    public const float FAR = 1.0e6;

    /** Field values closer to zero than this count as on the line (m). */
    private const float EPS = 1.0e-9;

    public readonly int $nx;
    public readonly int $nz;

    /** @var array<string, array<int, float>> field name => value per grid corner */
    private array $fields = [];

    public function __construct(
        public readonly float $x0,
        public readonly float $z0,
        float $x1,
        float $z1,
        public readonly float $cell,
    ) {
        if ($cell <= 0.0 || $x1 <= $x0 || $z1 <= $z0) {
            throw new \InvalidArgumentException('ConformingRegionMesh: empty grid');
        }
        $this->nx = (int) ceil(($x1 - $x0) / $cell - 1e-9);
        $this->nz = (int) ceil(($z1 - $z0) / $cell - 1e-9);
    }

    /**
     * A grid over the given bounds grown by $margin, its origin snapped to a
     * multiple of $cell (so grids of the same cell share their corners).
     */
    public static function covering(float $minX, float $minZ, float $maxX, float $maxZ, float $cell, float $margin = 0.0): self
    {
        if ($cell <= 0.0) {
            throw new \InvalidArgumentException('ConformingRegionMesh: cell must be > 0');
        }
        $x0 = floor(($minX - $margin) / $cell) * $cell;
        $z0 = floor(($minZ - $margin) / $cell) * $cell;
        $x1 = max($x0 + $cell, ceil(($maxX + $margin) / $cell) * $cell);
        $z1 = max($z0 + $cell, ceil(($maxZ + $margin) / $cell) * $cell);

        return new self($x0, $z0, $x1, $z1, $cell);
    }

    /** World x of a grid column. */
    public function gx(int $ix): float
    {
        return $this->x0 + $ix * $this->cell;
    }

    /** World z of a grid row. */
    public function gz(int $iz): float
    {
        return $this->z0 + $iz * $this->cell;
    }

    /** Whether anything was stamped or derived into the field. */
    public function has(string $field): bool
    {
        return ($this->fields[$field] ?? []) !== [];
    }

    /** @return list<string> */
    public function fieldNames(): array
    {
        return array_keys($this->fields);
    }

    /**
     * Stamp a shape into a field: every grid corner inside the rectangle gets
     * min(old, $distance(x, z)).
     *
     * @param callable(float, float): float $distance
     */
    public function stamp(string $field, float $bx0, float $bz0, float $bx1, float $bz1, callable $distance): void
    {
        $f = $this->fields[$field] ?? [];
        $ix0 = max(0, (int) floor(($bx0 - $this->x0) / $this->cell));
        $ix1 = min($this->nx, (int) ceil(($bx1 - $this->x0) / $this->cell));
        $iz0 = max(0, (int) floor(($bz0 - $this->z0) / $this->cell));
        $iz1 = min($this->nz, (int) ceil(($bz1 - $this->z0) / $this->cell));
        $stride = $this->nx + 1;
        for ($iz = $iz0; $iz <= $iz1; $iz++) {
            $z = $this->gz($iz);
            for ($ix = $ix0; $ix <= $ix1; $ix++) {
                $k = $iz * $stride + $ix;
                $d = $distance($this->gx($ix), $z);
                if ($d < ($f[$k] ?? self::FAR)) {
                    $f[$k] = $d;
                }
            }
        }
        $this->fields[$field] = $f;
    }

    /**
     * Stamp a {@see RegionShape}; the field is known up to $margin outside its
     * outline (at least as wide as any hem or border cut from it, plus a cell).
     */
    public function stampShape(string $field, RegionShape $shape, float $margin = 1.0): void
    {
        foreach ($shape->stamps($margin) as [$bx0, $bz0, $bx1, $bz1, $fn]) {
            $this->stamp($field, $bx0, $bz0, $bx1, $bz1, $fn);
        }
    }

    /**
     * Build a field from others (at every grid corner where one of the sources
     * is stamped; missing sources read {@see FAR}).
     *
     * @param list<string> $sources
     * @param callable(array<string, float>): float $combine gets source name => value
     */
    public function derive(string $field, array $sources, callable $combine): void
    {
        $keys = [];
        foreach ($sources as $s) {
            $keys += $this->fields[$s] ?? [];
        }
        $out = [];
        foreach (array_keys($keys) as $k) {
            $vals = [];
            foreach ($sources as $s) {
                $vals[$s] = $this->fields[$s][$k] ?? self::FAR;
            }
            $out[$k] = $combine($vals);
        }
        $this->fields[$field] = $out;
    }

    /** Value of a field at a grid corner ({@see FAR} where nothing is stamped). */
    public function valueAt(string $field, int $ix, int $iz): float
    {
        return $this->fields[$field][$iz * ($this->nx + 1) + $ix] ?? self::FAR;
    }

    /** A field anywhere, bilinear between the grid corners (for checks and placement). */
    public function sample(string $field, float $x, float $z): float
    {
        $fx = ($x - $this->x0) / $this->cell;
        $fz = ($z - $this->z0) / $this->cell;
        $ix = max(0, min($this->nx - 1, (int) floor($fx)));
        $iz = max(0, min($this->nz - 1, (int) floor($fz)));
        $tx = $fx - $ix;
        $tz = $fz - $iz;
        $a = $this->valueAt($field, $ix, $iz);
        $b = $this->valueAt($field, $ix + 1, $iz);
        $c = $this->valueAt($field, $ix, $iz + 1);
        $d = $this->valueAt($field, $ix + 1, $iz + 1);

        return ($a * (1 - $tx) + $b * $tx) * (1 - $tz) + ($c * (1 - $tx) + $d * $tx) * $tz;
    }

    /**
     * Build $field as the true signed distance to the zero line of $source –
     * the line {@see mesh()} cuts – for the corners up to $reach inside it;
     * everywhere else $field = $source.
     *
     * Why: min() of shape distances is exact outside a union of shapes, but
     * inside it measures to the nearest edge of ANY shape, also where two
     * shapes overlap or meet. A band "within w of the outer outline" (a border,
     * an edging) needs the distance to the outline itself.
     */
    public function redistance(string $field, string $source, float $reach): void
    {
        $src = $this->fields[$source] ?? [];
        $stride = $this->nx + 1;
        $bucket = max($reach, $this->cell);
        $cells = [];
        foreach ($src as $k => $val) {
            if ($val > 0.0) {
                continue;
            }
            $iz = intdiv($k, $stride);
            $ix = $k % $stride;
            foreach ([[0, 0], [-1, 0], [0, -1], [-1, -1]] as [$ox, $oz]) {
                $cx = $ix + $ox;
                $cz = $iz + $oz;
                if ($cx >= 0 && $cz >= 0 && $cx < $this->nx && $cz < $this->nz) {
                    $cells[$cz * $this->nx + $cx] = true;
                }
            }
        }
        // Zero-line segments per triangle, bucketed by their bounds.
        $segs = [];
        foreach (array_keys($cells) as $c) {
            $iz = intdiv($c, $this->nx);
            $ix = $c % $this->nx;
            $corners = [];
            foreach ([[0, 0], [1, 0], [1, 1], [0, 1]] as [$ox, $oz]) {
                $corners[] = [$this->gx($ix + $ox), $this->gz($iz + $oz), $src[($iz + $oz) * $stride + $ix + $ox] ?? self::FAR];
            }
            $inside = 0;
            foreach ($corners as $p) {
                if ($p[2] <= 0.0) {
                    $inside++;
                }
            }
            if ($inside === 4) {
                continue;
            }
            foreach (self::halves($ix, $iz, [$corners[0], $corners[1], $corners[2], $corners[3]]) as $tri) {
                $pts = [];
                for ($i = 0; $i < 3; $i++) {
                    $a = $tri[$i];
                    $b = $tri[($i + 1) % 3];
                    if (($a[2] <= 0.0) !== ($b[2] <= 0.0)) {
                        $t = $a[2] / ($a[2] - $b[2]);
                        $pts[] = [$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t];
                    }
                }
                if (count($pts) !== 2) {
                    continue;
                }
                $s = [$pts[0][0], $pts[0][1], $pts[1][0], $pts[1][1]];
                for ($bx = (int) floor(min($s[0], $s[2]) / $bucket); $bx <= (int) floor(max($s[0], $s[2]) / $bucket); $bx++) {
                    for ($bz = (int) floor(min($s[1], $s[3]) / $bucket); $bz <= (int) floor(max($s[1], $s[3]) / $bucket); $bz++) {
                        $segs["{$bx}:{$bz}"][] = $s;
                    }
                }
            }
        }
        $out = $src;
        foreach ($src as $k => $val) {
            if ($val > 0.0 || $val < -$reach) {
                continue; // outside: exact already; deeper inside: the true distance is at least |val|
            }
            $x = $this->gx($k % $stride);
            $z = $this->gz(intdiv($k, $stride));
            $bx = (int) floor($x / $bucket);
            $bz = (int) floor($z / $bucket);
            $best = INF;
            for ($i = -1; $i <= 1; $i++) {
                for ($j = -1; $j <= 1; $j++) {
                    foreach ($segs[($bx + $i) . ':' . ($bz + $j)] ?? [] as [$ax, $az, $ex, $ez]) {
                        $best = min($best, RegionShape::segmentDistance($x, $z, $ax, $az, $ex, $ez));
                    }
                }
            }
            $out[$k] = $best <= $reach ? -$best : min($val, -$reach);
        }
        $this->fields[$field] = $out;
    }

    /**
     * The two triangles of a cell, the diagonal alternating like a chessboard
     * so no direction is preferred. Corners in order (0,0), (1,0), (1,1), (0,1).
     *
     * @template T
     * @param array{T, T, T, T} $c
     * @return array{array{T, T, T}, array{T, T, T}}
     */
    private static function halves(int $ix, int $iz, array $c): array
    {
        return (($ix + $iz) & 1) === 0
            ? [[$c[0], $c[1], $c[2]], [$c[0], $c[2], $c[3]]]
            : [[$c[0], $c[1], $c[3]], [$c[1], $c[2], $c[3]]];
    }

    /**
     * The surface of one region as a mesh.
     *
     * A region is the intersection of its conditions. Regions that share a
     * condition with opposite signs tile without a gap. The cells visited are
     * those around corners where the first INSIDE condition (+1) holds – so
     * list at least one inside condition (without one, every cell is visited).
     *
     * @param list<array{0: string, 1: int}> $conditions each [field, sign]:
     *        +1 = keep where field ≤ 0 (inside); −1 = keep where field ≥ 0 (outside)
     * @param callable(float, float, array<string, float>): float $height height of
     *        a vertex from (x, z) and the interpolated field values (conditions + $carry)
     * @param list<string> $carry fields $height needs in addition
     * @param float $uvScale UV = world XZ × scale
     * @param list<string> $splits fields whose zero lines cut the region's
     *        triangles without removing anything. Give every mesh of a family
     *        of neighbouring regions the same list (all fields any of them
     *        clips by): then each one has a vertex wherever a neighbour's cut
     *        line meets the shared edge – no T-junctions, so the joint stays
     *        closed for a renderer and for an open-edge audit.
     */
    public function mesh(array $conditions, callable $height, array $carry = [], float $uvScale = 1.0, array $splits = []): MeshData
    {
        if ($conditions === []) {
            throw new \InvalidArgumentException('ConformingRegionMesh: a region needs at least one condition');
        }
        $names = [];
        foreach ($conditions as [$f, $sign]) {
            if ($sign !== 1 && $sign !== -1) {
                throw new \InvalidArgumentException("ConformingRegionMesh: sign of '{$f}' must be +1 or -1");
            }
            $names[$f] = true;
        }
        foreach ([...$carry, ...$splits] as $f) {
            $names[$f] = true;
        }
        $names = array_keys($names);
        $own = [];
        foreach ($conditions as [$f]) {
            $own[$f] = true;
        }
        $splits = array_values(array_filter(array_unique($splits), static fn (string $f): bool => !isset($own[$f])));
        $stride = $this->nx + 1;

        $verts = [];
        $index = [];
        $heights = [];
        $tris = [];

        // Cells that can satisfy the seed condition: only around corners where
        // it holds (if all four corners are outside, so is the cell).
        $seed = null;
        foreach ($conditions as [$f, $sign]) {
            if ($sign === 1) {
                $seed = $f;
                break;
            }
        }
        $cells = [];
        if ($seed === null) {
            for ($c = 0, $n = $this->nx * $this->nz; $c < $n; $c++) {
                $cells[$c] = true;
            }
        } else {
            foreach ($this->fields[$seed] ?? [] as $k => $val) {
                if ($val > 0.0) {
                    continue;
                }
                $iz = intdiv($k, $stride);
                $ix = $k % $stride;
                foreach ([[0, 0], [-1, 0], [0, -1], [-1, -1]] as [$ox, $oz]) {
                    $cx = $ix + $ox;
                    $cz = $iz + $oz;
                    if ($cx >= 0 && $cz >= 0 && $cx < $this->nx && $cz < $this->nz) {
                        $cells[$cz * $this->nx + $cx] = true;
                    }
                }
            }
            ksort($cells);
        }

        foreach (array_keys($cells) as $c) {
            $iz = intdiv($c, $this->nx);
            $ix = $c % $this->nx;
            // Quick reject without temporary arrays: fully outside one condition?
            $k00 = $iz * $stride + $ix;
            foreach ($conditions as [$f, $sign]) {
                $fv = $this->fields[$f] ?? [];
                if ($sign * ($fv[$k00] ?? self::FAR) > 0.0 && $sign * ($fv[$k00 + 1] ?? self::FAR) > 0.0
                    && $sign * ($fv[$k00 + $stride] ?? self::FAR) > 0.0 && $sign * ($fv[$k00 + $stride + 1] ?? self::FAR) > 0.0) {
                    continue 2;
                }
            }
            $corners = [];
            foreach ([[0, 0], [1, 0], [1, 1], [0, 1]] as [$ox, $oz]) {
                $vals = [];
                foreach ($names as $n) {
                    $vals[$n] = $this->fields[$n][($iz + $oz) * $stride + $ix + $ox] ?? self::FAR;
                }
                $corners[] = ['x' => $this->gx($ix + $ox), 'z' => $this->gz($iz + $oz), 'v' => $vals];
            }
            foreach (self::halves($ix, $iz, [$corners[0], $corners[1], $corners[2], $corners[3]]) as $poly) {
                foreach ($conditions as [$f, $sign]) {
                    $poly = self::clip($poly, $f, $sign);
                    if (count($poly) < 3) {
                        continue 2;
                    }
                }
                // Cut (not clip) along the split lines: both sides stay.
                $pieces = [$poly];
                foreach ($splits as $f) {
                    $next = [];
                    foreach ($pieces as $piece) {
                        $a = self::clip($piece, $f, 1);
                        $b = self::clip($piece, $f, -1);
                        if (count($a) >= 3 && count($b) >= 3) {
                            $next[] = $a;
                            $next[] = $b;
                        } else {
                            $next[] = $piece;
                        }
                    }
                    $pieces = $next;
                }
                foreach ($pieces as $piece) {
                    $ids = [];
                    foreach ($piece as $p) {
                        $key = (int) round($p['x'] * 1e4) . ':' . (int) round($p['z'] * 1e4);
                        if (!isset($index[$key])) {
                            $index[$key] = count($verts);
                            $verts[] = [$p['x'], $p['z']];
                            $heights[] = $height($p['x'], $p['z'], $p['v']);
                        }
                        if ($ids === [] || ($ids[count($ids) - 1] !== $index[$key] && $ids[0] !== $index[$key])) {
                            $ids[] = $index[$key];
                        }
                    }
                    foreach (self::triangulate($ids, $verts) as $tri) {
                        $tris[] = $tri;
                    }
                }
            }
        }

        return self::assemble($verts, $heights, $tris, $uvScale);
    }

    /**
     * Height callback for {@see mesh()}: the ground at the vertex plus $lift.
     *
     * @param callable(float, float): float $ground
     * @return \Closure(float, float, array<string, float>): float
     */
    public static function onGround(callable $ground, float $lift = 0.0): \Closure
    {
        return static fn (float $x, float $z, array $v): float => $ground($x, $z) + $lift;
    }

    /**
     * Height callback for a hem: $lift above the ground where $field is 0 (the
     * outline), falling linearly to $sink BELOW the ground where $field reaches
     * $width. Mesh it with $field in the conditions or in $carry.
     *
     * @param callable(float, float): float $ground
     * @return \Closure(float, float, array<string, float>): float
     */
    public static function hem(callable $ground, string $field, float $lift, float $width, float $sink): \Closure
    {
        if ($width <= 0.0) {
            throw new \InvalidArgumentException('ConformingRegionMesh::hem: width must be > 0');
        }

        return static fn (float $x, float $z, array $v): float => $ground($x, $z) + $lift
            - ($lift + $sink) * self::fraction($v, $field, $width);
    }

    /**
     * Field value as a fraction of $width, clamped to 0..1 (0 when the field
     * is not among the values – the hem then stays at the surface height).
     *
     * @param array<mixed> $v
     */
    private static function fraction(array $v, string $field, float $width): float
    {
        $f = $v[$field] ?? 0.0;
        if (!is_float($f) && !is_int($f)) {
            return 0.0;
        }

        return max(0.0, min(1.0, $f / $width));
    }

    /**
     * Clip a polygon against "sign × field ≤ 0".
     *
     * @param list<array{x: float, z: float, v: array<string, float>}> $poly
     * @return list<array{x: float, z: float, v: array<string, float>}>
     */
    private static function clip(array $poly, string $field, int $sign): array
    {
        $out = [];
        $n = count($poly);
        for ($i = 0; $i < $n; $i++) {
            $a = $poly[$i];
            $b = $poly[($i + 1) % $n];
            // Values within EPS of zero lie ON the line: an edge that an earlier
            // clip already cut along the same line (two fields with the same
            // zero line, e.g. a union and its member) must not be cut again at
            // float noise – that would leave a T-junction against the neighbour.
            $da = $sign * $a['v'][$field];
            $db = $sign * $b['v'][$field];
            if (abs($da) < self::EPS) {
                $da = 0.0;
            }
            if (abs($db) < self::EPS) {
                $db = 0.0;
            }
            if ($da <= 0.0) {
                $out[] = $a;
            }
            if (($da < 0.0 && $db > 0.0) || ($da > 0.0 && $db < 0.0)) {
                $t = $da / ($da - $db);
                $v = [];
                foreach ($a['v'] as $k => $va) {
                    $v[$k] = $va + ($b['v'][$k] - $va) * $t;
                }
                $out[] = ['x' => $a['x'] + ($b['x'] - $a['x']) * $t, 'z' => $a['z'] + ($b['z'] - $a['z']) * $t, 'v' => $v];
            }
        }

        return $out;
    }

    /**
     * Triangulate a convex polygon (vertex ids, in order) that may carry
     * collinear points on its edges: cut off ears with a non-zero area until
     * three corners are left. Unlike a fan, every point ends up in a triangle,
     * so a point on an edge (where a neighbour is cut) is never skipped.
     *
     * @param list<int> $ids
     * @param list<array{float, float}> $verts
     * @return list<array{int, int, int}>
     */
    private static function triangulate(array $ids, array $verts): array
    {
        $out = [];
        $area = static function (int $a, int $b, int $c) use ($verts): float {
            return ($verts[$b][0] - $verts[$a][0]) * ($verts[$c][1] - $verts[$a][1])
                - ($verts[$c][0] - $verts[$a][0]) * ($verts[$b][1] - $verts[$a][1]);
        };
        while (count($ids) > 3) {
            $n = count($ids);
            $cut = false;
            for ($i = 0; $i < $n; $i++) {
                $a = $ids[($i + $n - 1) % $n];
                $b = $ids[$i];
                $c = $ids[($i + 1) % $n];
                if (abs($area($a, $b, $c)) > 1e-12) {
                    $out[] = [$a, $b, $c];
                    array_splice($ids, $i, 1);
                    $cut = true;
                    break;
                }
            }
            if (!$cut) {
                return $out; // all collinear: nothing left with an area
            }
        }
        if (count($ids) === 3 && abs($area($ids[0], $ids[1], $ids[2])) > 1e-12) {
            $out[] = [$ids[0], $ids[1], $ids[2]];
        }

        return $out;
    }

    /**
     * @param list<array{float, float}> $verts
     * @param list<float> $heights
     * @param list<array{int, int, int}> $tris
     */
    private static function assemble(array $verts, array $heights, array $tris, float $uvScale): MeshData
    {
        $n = count($verts);
        $nrm = array_fill(0, $n * 3, 0.0);
        $indices = [];
        foreach ($tris as [$a, $b, $c]) {
            $ax = $verts[$a][0];
            $az = $verts[$a][1];
            $ay = $heights[$a];
            $ux = $verts[$b][0] - $ax;
            $uy = $heights[$b] - $ay;
            $uz = $verts[$b][1] - $az;
            $vx = $verts[$c][0] - $ax;
            $vy = $heights[$c] - $ay;
            $vz = $verts[$c][1] - $az;
            // Face normal = u × v; turn it up (counter-clockwise seen from above).
            $nx = $uy * $vz - $uz * $vy;
            $ny = $uz * $vx - $ux * $vz;
            $nz = $ux * $vy - $uy * $vx;
            if ($ny < 0.0) {
                [$b, $c] = [$c, $b];
                $nx = -$nx;
                $ny = -$ny;
                $nz = -$nz;
            }
            if ($ny <= 1e-12) {
                continue; // degenerate
            }
            array_push($indices, $a, $b, $c);
            foreach ([$a, $b, $c] as $i) {
                $nrm[$i * 3] += $nx;
                $nrm[$i * 3 + 1] += $ny;
                $nrm[$i * 3 + 2] += $nz;
            }
        }
        $vertices = [];
        $normals = [];
        $uvs = [];
        for ($i = 0; $i < $n; $i++) {
            $vertices[] = $verts[$i][0];
            $vertices[] = $heights[$i];
            $vertices[] = $verts[$i][1];
            $len = sqrt($nrm[$i * 3] ** 2 + $nrm[$i * 3 + 1] ** 2 + $nrm[$i * 3 + 2] ** 2);
            if ($len < 1e-12) {
                array_push($normals, 0.0, 1.0, 0.0);
            } else {
                array_push($normals, $nrm[$i * 3] / $len, $nrm[$i * 3 + 1] / $len, $nrm[$i * 3 + 2] / $len);
            }
            $uvs[] = $verts[$i][0] * $uvScale;
            $uvs[] = $verts[$i][1] * $uvScale;
        }

        return new MeshData($vertices, $normals, $uvs, $indices);
    }
}
