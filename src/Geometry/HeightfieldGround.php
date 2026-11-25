<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * Ground meshes from a sampled height function ({@see HeightfieldGrid}): one regular grid, two planar
 * triangles per cell, with convex HOLES cut exactly into it ({@see HeightfieldHole}: a lift shaft through
 * the ground) and ZONES that split the surface into one mesh per zone name (a material each).
 *
 *     $grid = HeightfieldGrid::sample(-700, -700, 700, 700, 2.0, $terrain);
 *     $meshes = HeightfieldGround::build($grid, [HeightfieldHole::rect(10, 4, 5, 5)], $zoneAt);
 *     // ['grass' => MeshData, 'road' => MeshData]
 *
 * Pure: the result depends on the arguments alone (plain data, no static state), so the same call in a
 * worker thread or another run gives the same bits. {@see contentKey()} folds every input into one key
 * for {@see MeshSetCache::remember()} - the height and zone FUNCTIONS cannot be hashed, so the grid
 * (their sampled result) and a caller-given `$zoneKey` stand in for them.
 *
 * Properties the mesh keeps:
 * - Lattice corners are `index * cell` and their heights are the grid's exactly; every vertex lies on the
 *   triangulated surface of the grid ({@see HeightfieldGrid::heightAt()}) - a hole removes area, it moves no
 *   height. A collision mesh built from the same grid agrees with the render mesh.
 * - Triangles are counter-clockwise seen from above (the mesh convention), every face normal points up.
 * - Normals come from the heights around the vertex (central differences over the grid, halo included),
 *   not from the faces of one mesh: zone seams and tile borders shade alike. Vertices a hole adds
 *   blend the four corner normals of their cell. UV = world XZ x scale.
 * - Seam-free: all zones of one call, and neighbouring grids built with the same cell size, share
 *   vertex positions bit for bit (the lattice is absolute; an outline crossing a lattice edge is computed
 *   along the edge in a canonical direction), and every edge cut by a hole carries its cut point on both
 *   sides - no T-junction. Open edges remain only on the outer border and on the hole outlines.
 * - A hole is cut out of the triangles it touches: pieces outside the convex outline, welded with the
 *   points the cut leaves on neighbouring edges, each piece fanned from the apex that gives the best
 *   triangles. A cut that would fall within `$snap` x cell of a corner snaps to that corner (no sliver);
 *   the outline then moves by at most that length.
 * - Zone: `$zone(x, z)` is asked once per triangle, at its centroid; a triangle belongs to exactly one
 *   zone, the zone borders follow triangle edges.
 *
 * Radial grids are not part of this class.
 */
final class HeightfieldGround
{
    /** Zone name of a build without a zone function. */
    public const string DEFAULT_ZONE = 'ground';

    /** Bump when the mesh layout changes. */
    public const int FORMAT = 1;

    private const float EPS = 1.0e-9;
    private const float COLLINEAR = 1.0e-7;
    private const float MIN_AREA = 1.0e-10;

    private readonly float $cell;
    private readonly float $inv;
    private readonly int $i0;
    private readonly int $j0;
    private readonly int $nx;
    private readonly int $nz;
    private readonly int $stride;

    /** @var array<int, array{0: float, 1: float, 2: float}> corner id => unit normal */
    private array $cornerNormals = [];

    /** @var array<string, array{v: list<float>, n: list<float>, uv: list<float>, i: list<int>, c: array<int, int>, p: array<int, int>}> */
    private array $zones = [];

    /**
     * @param list<HeightfieldHole> $holes
     * @param (\Closure(float, float): (string|int))|null $zone
     */
    private function __construct(
        private readonly HeightfieldGrid $grid,
        private readonly array $holes,
        private readonly ?\Closure $zone,
        private readonly float $uvScale,
        private readonly float $snapLength,
        private readonly int $flatBlock = 0,
        private readonly float $flatTolerance = 1.0e-6,
    ) {
        $this->cell = $grid->cell;
        $this->inv = 1.0 / $grid->cell;
        $this->i0 = $grid->i0;
        $this->j0 = $grid->j0;
        $this->nx = $grid->nx;
        $this->nz = $grid->nz;
        $this->stride = $grid->nx + 3;
    }

    /**
     * @param list<HeightfieldHole> $holes convex holes; may overlap each other
     * @param (\Closure(float, float): (string|int))|null $zone zone name at a point; null = one mesh named {@see DEFAULT_ZONE}
     * @param float $uvScale UV = world XZ x this
     * @param float $snap a cut closer than `$snap` x cell to a triangle corner moves onto it (0 = never; below 0.5)
     * @param int $flatBlock FLAT BLOCKS (0 = off, else >= 2): a square block of `$flatBlock` x `$flatBlock` cells whose
     *                       corners all lie on one plane (within `$flatTolerance` metres), have ground, belong to one
     *                       zone and are a cell clear of every hole is emitted as ONE polygon fanned from its centre
     *                       (4 x `$flatBlock` triangles instead of 2 x `$flatBlock`^2) instead of cell by cell. Blocks sit
     *                       on absolute lattice multiples of `$flatBlock` (neighbouring tiles agree) and keep EVERY
     *                       lattice corner of their border as a vertex, so the border stays watertight against blocks and
     *                       single cells alike. Interior corners are dropped: the surface inside deviates from the grid
     *                       by at most `$flatTolerance` (not at all for an exactly flat block).
     * @param float $flatTolerance largest distance (m) of a block corner from the block's plane
     * @return array<string, MeshData> zone name => mesh, sorted by name; a zone without triangles is absent
     */
    public static function build(HeightfieldGrid $grid, array $holes = [], ?\Closure $zone = null, float $uvScale = 1.0, float $snap = 0.05, int $flatBlock = 0, float $flatTolerance = 1.0e-6): array
    {
        if ($snap < 0.0 || $snap >= 0.5) {
            throw new \InvalidArgumentException('HeightfieldGround: snap must be in [0, 0.5)');
        }
        if ($flatBlock === 1 || $flatBlock < 0 || $flatTolerance < 0.0) {
            throw new \InvalidArgumentException('HeightfieldGround: flatBlock must be 0 or >= 2 and flatTolerance >= 0');
        }

        return (new self($grid, $holes, $zone, $uvScale, $snap * $grid->cell, $flatBlock, $flatTolerance))->run();
    }

    /**
     * One content key for everything that decides the meshes: the grid, the holes, the zone function's own
     * key, the UV scale and the snap. Use it as (part of) the key of {@see MeshSetCache::remember()}.
     *
     * @param list<HeightfieldHole> $holes
     * @param string $zoneKey a string that changes whenever the zone function does (e.g. 'zones-v3' plus a hash of its data)
     */
    public static function contentKey(HeightfieldGrid $grid, array $holes = [], string $zoneKey = '', float $uvScale = 1.0, float $snap = 0.05, int $flatBlock = 0, float $flatTolerance = 1.0e-6): string
    {
        // The flat-block options only enter the key when used, so keys of builds without them stay as they were.
        $flat = $flatBlock > 0 ? ['flat' . $flatBlock . ':' . $flatTolerance] : [];

        return MeshSetCache::fingerprint(
            'heightfield-ground',
            (string) self::FORMAT,
            $grid->contentHash(),
            implode(',', array_map(static fn (HeightfieldHole $h): string => $h->fingerprint(), $holes)),
            $zoneKey,
            pack('ee', $uvScale, $snap),
            ...$flat,
        );
    }

    /** @return array<string, MeshData> */
    private function run(): array
    {
        $special = $this->specialCells();

        // Pass 1: cut the holes out of the cells they reach and note the points that land on lattice
        // edges, so the neighbours on the other side can carry them.
        $pieces = [];
        /** @var array<string, array<int, array{0: float, 1: float, 2: float}>> $extras */
        $extras = [];
        foreach ($special as $id => [$ia, $ja]) {
            $cellPieces = [];
            foreach ($this->cellTriangles($ia, $ja) as $tri) {
                foreach ($this->outside($tri) as $piece) {
                    $cellPieces[] = $piece;
                }
            }
            $pieces[$id] = $cellPieces;
            foreach ($this->cellEdges($ia, $ja) as [$key, $a, $b]) {
                foreach ($cellPieces as $piece) {
                    foreach ($piece as $q) {
                        if ($this->interior($q, $a, $b)) {
                            $extras[$key][self::positionKey($q[0], $q[1])] = $q;
                        }
                    }
                }
            }
        }

        // Pass 2: emit. Cells away from every hole are plain triangles (or part of a flat block).
        [$blockOrigins, $blockCells] = $this->flatBlock > 1 ? $this->flatBlocks($special) : [[], []];
        for ($ja = $this->j0; $ja < $this->j0 + $this->nz; $ja++) {
            for ($ia = $this->i0; $ia < $this->i0 + $this->nx; $ia++) {
                $id = $this->cellId($ia, $ja);
                if (isset($blockCells[$id])) {
                    if (array_key_exists($id, $blockOrigins)) {
                        $this->emitBlock($ia, $ja, $blockOrigins[$id]);
                    }
                    continue;
                }
                if (!isset($special[$id])) {
                    foreach ($this->cellTriangles($ia, $ja) as [$a, $b, $c]) {
                        $this->emit($a, $b, $c);
                    }
                    continue;
                }
                $candidates = [];
                foreach ($pieces[$id] as $piece) {
                    foreach ($piece as $q) {
                        $candidates[self::positionKey($q[0], $q[1])] = $q;
                    }
                }
                foreach ($this->cellEdges($ia, $ja) as [$key]) {
                    foreach ($extras[$key] ?? [] as $pk => $q) {
                        $candidates[$pk] = $q;
                    }
                }
                foreach ($pieces[$id] as $piece) {
                    $this->fan($this->withCandidates($piece, array_values($candidates)));
                }
            }
        }

        $out = [];
        foreach ($this->zones as $name => $z) {
            if ($z['i'] !== []) {
                $out[$name] = new MeshData($z['v'], $z['n'], $z['uv'], $z['i']);
            }
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * The flat blocks of the grid ({@see build()}, `$flatBlock`): origin cell id => zone name (null without a zone
     * function), and the set of all cell ids they cover.
     *
     * @param array<int, array{0: int, 1: int}> $special cells touched by holes (and the ring round them)
     * @return array{0: array<int, string|null>, 1: array<int, true>}
     */
    private function flatBlocks(array $special): array
    {
        $b = $this->flatBlock;
        $origins = [];
        $covered = [];
        $iStart = (int) (ceil($this->i0 / $b) * $b);
        $jStart = (int) (ceil($this->j0 / $b) * $b);
        for ($ja = $jStart; $ja + $b <= $this->j0 + $this->nz; $ja += $b) {
            for ($ia = $iStart; $ia + $b <= $this->i0 + $this->nx; $ia += $b) {
                $name = $this->flatBlockZone($ia, $ja, $special);
                if ($name === false) {
                    continue;
                }
                $origins[$this->cellId($ia, $ja)] = $name;
                for ($dj = 0; $dj < $b; $dj++) {
                    for ($di = 0; $di < $b; $di++) {
                        $covered[$this->cellId($ia + $di, $ja + $dj)] = true;
                    }
                }
            }
        }

        return [$origins, $covered];
    }

    /**
     * The zone name of the block at cell (ia, ja) when it can be emitted as one polygon (null = no zone function), false
     * when not: all its corners have ground and lie on one plane, no hole cell within a cell of it, one zone for every
     * triangle.
     *
     * @param array<int, array{0: int, 1: int}> $special
     */
    private function flatBlockZone(int $ia, int $ja, array $special): string|false|null
    {
        $b = $this->flatBlock;
        for ($dj = -1; $dj <= $b; $dj++) {
            for ($di = -1; $di <= $b; $di++) {
                if (isset($special[$this->cellId($ia + $di, $ja + $dj)])) {
                    return false;
                }
            }
        }
        $g = $this->grid;
        $h00 = $g->cornerHeight($ia, $ja);
        $dx = ($g->cornerHeight($ia + $b, $ja) - $h00) / $b;
        $dz = ($g->cornerHeight($ia, $ja + $b) - $h00) / $b;
        for ($dj = 0; $dj <= $b; $dj++) {
            for ($di = 0; $di <= $b; $di++) {
                if (!$g->hasGround($ia + $di, $ja + $dj)
                    || abs($g->cornerHeight($ia + $di, $ja + $dj) - ($h00 + $dx * $di + $dz * $dj)) > $this->flatTolerance) {
                    return false;
                }
            }
        }
        if ($this->zone === null) {
            return null;
        }
        $name = null;
        for ($dj = 0; $dj < $b; $dj++) {
            for ($di = 0; $di < $b; $di++) {
                foreach ($this->cellTriangles($ia + $di, $ja + $dj) as [$p, $q, $r]) {
                    $n = (string) ($this->zone)(($p[0] + $q[0] + $r[0]) / 3.0, ($p[1] + $q[1] + $r[1]) / 3.0);
                    if ($name !== null && $n !== $name) {
                        return false;
                    }
                    $name = $n;
                }
            }
        }

        return $name;
    }

    /**
     * One flat block as a fan from its centre over every lattice corner of its border (the collinear border points stay
     * vertices, so neighbours that keep their single cells meet it without a T-junction).
     */
    private function emitBlock(int $ia, int $ja, ?string $name): void
    {
        $b = $this->flatBlock;
        $g = $this->grid;
        $ring = [];
        for ($k = 0; $k < $b; $k++) {
            $ring[] = [$ia + $k, $ja];
        }
        for ($k = 0; $k < $b; $k++) {
            $ring[] = [$ia + $b, $ja + $k];
        }
        for ($k = $b; $k > 0; $k--) {
            $ring[] = [$ia + $k, $ja + $b];
        }
        for ($k = $b; $k > 0; $k--) {
            $ring[] = [$ia, $ja + $k];
        }
        $pts = [];
        foreach ($ring as [$i, $j]) {
            $pts[] = [$i * $this->cell, $j * $this->cell, $g->cornerHeight($i, $j)];
        }
        $h00 = $g->cornerHeight($ia, $ja);
        $centre = [
            ($ia + $b * 0.5) * $this->cell,
            ($ja + $b * 0.5) * $this->cell,
            $h00 + 0.5 * ($g->cornerHeight($ia + $b, $ja) - $h00) + 0.5 * ($g->cornerHeight($ia, $ja + $b) - $h00),
        ];
        $n = count($pts);
        for ($k = 0; $k < $n; $k++) {
            $this->emit($centre, $pts[$k], $pts[($k + 1) % $n], $name);
        }
    }

    private function cellId(int $ia, int $ja): int
    {
        return ($ja - $this->j0 + 1) * $this->stride + ($ia - $this->i0 + 1);
    }

    /**
     * The cells to cut, one ring wider than the holes reach (the ring carries the points the cuts leave on
     * shared edges): id => [i, j], in a fixed order. Covers the cells one outside the grid too, so a hole
     * across a tile border cuts both sides alike.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private function specialCells(): array
    {
        $cells = [];
        foreach ($this->holes as $hole) {
            [$bx0, $bz0, $bx1, $bz1] = $hole->bounds();
            $ia0 = max($this->i0 - 1, (int) floor($bx0 * $this->inv) - 1);
            $ia1 = min($this->i0 + $this->nx, (int) floor($bx1 * $this->inv) + 1);
            $ja0 = max($this->j0 - 1, (int) floor($bz0 * $this->inv) - 1);
            $ja1 = min($this->j0 + $this->nz, (int) floor($bz1 * $this->inv) + 1);
            for ($ja = $ja0; $ja <= $ja1; $ja++) {
                for ($ia = $ia0; $ia <= $ia1; $ia++) {
                    $cells[$this->cellId($ia, $ja)] = [$ia, $ja];
                }
            }
        }
        ksort($cells);

        return $cells;
    }

    /**
     * The two triangles of a cell, counter-clockwise seen from above; points are [x, z, height]. On a masked
     * grid the triangles with a groundless corner are left out.
     *
     * @return list<list<array{0: float, 1: float, 2: float}>>
     */
    private function cellTriangles(int $ia, int $ja): array
    {
        $xa = $ia * $this->cell;
        $xb = ($ia + 1) * $this->cell;
        $za = $ja * $this->cell;
        $zb = ($ja + 1) * $this->cell;
        $p00 = [$xa, $za, $this->grid->cornerHeight($ia, $ja)];
        $p10 = [$xb, $za, $this->grid->cornerHeight($ia + 1, $ja)];
        $p11 = [$xb, $zb, $this->grid->cornerHeight($ia + 1, $ja + 1)];
        $p01 = [$xa, $zb, $this->grid->cornerHeight($ia, $ja + 1)];

        $tris = $this->grid->risingAt($ia, $ja)
            ? [[$p00, $p01, $p11], [$p00, $p11, $p10]]
            : [[$p00, $p01, $p10], [$p10, $p01, $p11]];
        if ($this->grid->isMasked()) {
            // Void-corner rule: a triangle exists only if all three corners have ground.
            foreach ([1, 0] as $which) {
                if (!$this->grid->triangleHasGround($ia, $ja, $which)) {
                    unset($tris[$which]);
                }
            }
            $tris = array_values($tris);
        }

        return $tris;
    }

    /**
     * The five lattice edges of a cell with a key each (shared with the neighbour): [key, from, to].
     *
     * @return list<array{0: string, 1: array{0: float, 1: float}, 2: array{0: float, 1: float}}>
     */
    private function cellEdges(int $ia, int $ja): array
    {
        $xa = $ia * $this->cell;
        $xb = ($ia + 1) * $this->cell;
        $za = $ja * $this->cell;
        $zb = ($ja + 1) * $this->cell;
        $rising = $this->grid->risingAt($ia, $ja);

        return [
            ['h' . $ia . ':' . $ja, [$xa, $za], [$xb, $za]],
            ['h' . $ia . ':' . ($ja + 1), [$xa, $zb], [$xb, $zb]],
            ['v' . $ia . ':' . $ja, [$xa, $za], [$xa, $zb]],
            ['v' . ($ia + 1) . ':' . $ja, [$xb, $za], [$xb, $zb]],
            $rising ? ['d' . $ia . ':' . $ja, [$xa, $za], [$xb, $zb]] : ['d' . $ia . ':' . $ja, [$xb, $za], [$xa, $zb]],
        ];
    }

    /**
     * A triangle with every hole cut out: convex pieces outside all holes.
     *
     * @param list<array{0: float, 1: float, 2: float}> $tri
     * @return list<list<array{0: float, 1: float, 2: float}>>
     */
    private function outside(array $tri): array
    {
        $pieces = [$tri];
        foreach ($this->holes as $hole) {
            $next = [];
            foreach ($pieces as $piece) {
                foreach ($this->outsideOne($piece, $hole) as $q) {
                    $next[] = $q;
                }
            }
            $pieces = $next;
        }

        return $pieces;
    }

    /**
     * The part of a convex polygon outside one convex hole, as convex pieces: for each hole edge in turn,
     * what lies beyond that edge and inside the edges before it. A polygon the hole does not reach stays whole.
     *
     * @param list<array{0: float, 1: float, 2: float}> $poly
     * @return list<list<array{0: float, 1: float, 2: float}>>
     */
    private function outsideOne(array $poly, HeightfieldHole $hole): array
    {
        $planes = $hole->planes();
        foreach ($planes as [$a, $b, $c]) {
            $apart = true;
            foreach ($poly as $p) {
                if ($a * $p[0] + $b * $p[1] + $c > self::EPS) {
                    $apart = false;
                    break;
                }
            }
            if ($apart) {
                return [$poly];
            }
        }
        $out = [];
        $rest = $poly;
        foreach ($planes as [$a, $b, $c]) {
            $beyond = $this->clip($rest, -$a, -$b, -$c);
            if (count($beyond) >= 3 && self::area($beyond) > self::MIN_AREA) {
                $out[] = $beyond;
            }
            $rest = $this->clip($rest, $a, $b, $c);
            if (count($rest) < 3) {
                break;
            }
        }

        return $out;
    }

    /**
     * Keep the part of a convex polygon where `a x + b z + c >= 0` (points on the line count as inside).
     * A cut is computed along the edge from its lexicographically smaller end, whatever the polygon's
     * direction, so two polygons sharing the edge get the same point to the bit; a cut within the snap length
     * of an end moves onto that end.
     *
     * @param list<array{0: float, 1: float, 2: float}> $poly
     * @return list<array{0: float, 1: float, 2: float}>
     */
    private function clip(array $poly, float $a, float $b, float $c): array
    {
        $out = [];
        $n = count($poly);
        for ($i = 0; $i < $n; $i++) {
            $p = $poly[$i];
            $q = $poly[($i + 1) % $n];
            $dp = $a * $p[0] + $b * $p[1] + $c;
            $dq = $a * $q[0] + $b * $q[1] + $c;
            if (abs($dp) < self::EPS) {
                $dp = 0.0;
            }
            if (abs($dq) < self::EPS) {
                $dq = 0.0;
            }
            if ($dp >= 0.0) {
                $out[] = $p;
            }
            if (($dp > 0.0 && $dq < 0.0) || ($dp < 0.0 && $dq > 0.0)) {
                if ($p[0] < $q[0] || ($p[0] === $q[0] && $p[1] < $q[1])) {
                    [$lo, $hi, $dl, $dh] = [$p, $q, $dp, $dq];
                } else {
                    [$lo, $hi, $dl, $dh] = [$q, $p, $dq, $dp];
                }
                $t = $dl / ($dl - $dh);
                $len = hypot($hi[0] - $lo[0], $hi[1] - $lo[1]);
                if ($t * $len < $this->snapLength) {
                    $out[] = $lo;
                } elseif ((1.0 - $t) * $len < $this->snapLength) {
                    $out[] = $hi;
                } else {
                    $out[] = [
                        $lo[0] + ($hi[0] - $lo[0]) * $t,
                        $lo[1] + ($hi[1] - $lo[1]) * $t,
                        $lo[2] + ($hi[2] - $lo[2]) * $t,
                    ];
                }
            }
        }

        return self::dedupe($out);
    }

    /**
     * @param list<array{0: float, 1: float, 2: float}> $poly
     * @return list<array{0: float, 1: float, 2: float}>
     */
    private static function dedupe(array $poly): array
    {
        $out = [];
        foreach ($poly as $p) {
            $last = $out === [] ? null : $out[count($out) - 1];
            if ($last === null || $last[0] !== $p[0] || $last[1] !== $p[1]) {
                $out[] = $p;
            }
        }
        $n = count($out);
        if ($n > 1 && $out[0][0] === $out[$n - 1][0] && $out[0][1] === $out[$n - 1][1]) {
            array_pop($out);
        }

        return $out;
    }

    /**
     * Unsigned XZ area of a polygon.
     *
     * @param list<array{0: float, 1: float, 2: float}> $poly
     */
    private static function area(array $poly): float
    {
        $n = count($poly);
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $p = $poly[$i];
            $q = $poly[($i + 1) % $n];
            $sum += $p[0] * $q[1] - $q[0] * $p[1];
        }

        return abs($sum) * 0.5;
    }

    /**
     * Whether q lies on the open segment a-b (not at an end).
     *
     * @param array{0: float, 1: float, 2?: float} $q
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $b
     */
    private function interior(array $q, array $a, array $b): bool
    {
        return $this->parameterOnSegment($q, $a, $b) !== null;
    }

    /**
     * The position 0..1 of q along a-b when it lies on the segment strictly between the ends, else null.
     *
     * @param array{0: float, 1: float, 2?: float} $q
     * @param array{0: float, 1: float, 2?: float} $a
     * @param array{0: float, 1: float, 2?: float} $b
     */
    private function parameterOnSegment(array $q, array $a, array $b): ?float
    {
        $ex = $b[0] - $a[0];
        $ez = $b[1] - $a[1];
        $len2 = $ex * $ex + $ez * $ez;
        if ($len2 < 1e-24) {
            return null;
        }
        $t = (($q[0] - $a[0]) * $ex + ($q[1] - $a[1]) * $ez) / $len2;
        if ($t <= 1.0e-9 || $t >= 1.0 - 1.0e-9) {
            return null;
        }
        $off = abs($ex * ($q[1] - $a[1]) - $ez * ($q[0] - $a[0])) / sqrt($len2);

        return $off <= self::COLLINEAR ? $t : null;
    }

    /**
     * A piece with the given points inserted into its edges where they lie on them.
     *
     * @param list<array{0: float, 1: float, 2: float}> $piece
     * @param list<array{0: float, 1: float, 2: float}> $candidates
     * @return list<array{0: float, 1: float, 2: float}>
     */
    private function withCandidates(array $piece, array $candidates): array
    {
        $out = [];
        $n = count($piece);
        for ($i = 0; $i < $n; $i++) {
            $a = $piece[$i];
            $b = $piece[($i + 1) % $n];
            $out[] = $a;
            $on = [];
            foreach ($candidates as $q) {
                $t = $this->parameterOnSegment($q, $a, $b);
                if ($t !== null) {
                    $on[] = [$t, $q];
                }
            }
            usort($on, static fn (array $l, array $r): int => $l[0] <=> $r[0]);
            foreach ($on as [, $q]) {
                $out[] = $q;
            }
        }

        return self::dedupe($out);
    }

    /**
     * Fan a convex polygon (collinear points allowed) from the apex whose smallest triangle is best shaped.
     * The apex is never in line with two neighbouring points, so every point is a corner of a triangle.
     *
     * @param list<array{0: float, 1: float, 2: float}> $poly
     */
    private function fan(array $poly): void
    {
        $n = count($poly);
        if ($n < 3) {
            return;
        }
        if ($n === 3) {
            $this->emit($poly[0], $poly[1], $poly[2]);

            return;
        }
        $bestApex = -1;
        $bestQuality = -1.0;
        for ($a = 0; $a < $n; $a++) {
            // An apex in line with two neighbours would skip the middle one: that point (a T-vertex a
            // neighbour relies on) would then belong to no triangle. Such an apex is not a candidate.
            $worst = INF;
            for ($j = 1; $j < $n - 1; $j++) {
                $worst = min($worst, self::quality($poly[$a], $poly[($a + $j) % $n], $poly[($a + $j + 1) % $n]));
            }
            if ($worst > 0.0 && $worst > $bestQuality + 1e-12) {
                $bestQuality = $worst;
                $bestApex = $a;
            }
        }
        if ($bestApex < 0) {
            // Several points in line on more than one edge: fan from the centroid (a point of the polygon's
            // plane, since the corners are coplanar).
            $cx = $cz = $ch = 0.0;
            foreach ($poly as $p) {
                $cx += $p[0];
                $cz += $p[1];
                $ch += $p[2];
            }
            $centre = [$cx / $n, $cz / $n, $ch / $n];
            for ($j = 0; $j < $n; $j++) {
                $this->emit($centre, $poly[$j], $poly[($j + 1) % $n]);
            }

            return;
        }
        for ($j = 1; $j < $n - 1; $j++) {
            $this->emit($poly[$bestApex], $poly[($bestApex + $j) % $n], $poly[($bestApex + $j + 1) % $n]);
        }
    }

    /**
     * 4 sqrt(3) area / sum of squared edges: 1 for an equilateral triangle, towards 0 for a sliver; 0 without area.
     *
     * @param array{0: float, 1: float, 2: float} $a
     * @param array{0: float, 1: float, 2: float} $b
     * @param array{0: float, 1: float, 2: float} $c
     */
    private static function quality(array $a, array $b, array $c): float
    {
        $area = 0.5 * abs(($b[0] - $a[0]) * ($c[1] - $a[1]) - ($c[0] - $a[0]) * ($b[1] - $a[1]));
        if ($area < self::MIN_AREA) {
            return 0.0;
        }
        $s = ($b[0] - $a[0]) ** 2 + ($b[1] - $a[1]) ** 2
            + ($c[0] - $b[0]) ** 2 + ($c[1] - $b[1]) ** 2
            + ($a[0] - $c[0]) ** 2 + ($a[1] - $c[1]) ** 2;

        return 4.0 * sqrt(3.0) * $area / $s;
    }

    /**
     * Add one triangle to its zone's mesh, wound counter-clockwise seen from above.
     *
     * @param array{0: float, 1: float, 2: float} $a
     * @param array{0: float, 1: float, 2: float} $b
     * @param array{0: float, 1: float, 2: float} $c
     * @param string|null $zoneName zone of the triangle when the caller knows it (a flat block); null = ask the zone function
     */
    private function emit(array $a, array $b, array $c, ?string $zoneName = null): void
    {
        $cross = ($b[1] - $a[1]) * ($c[0] - $a[0]) - ($b[0] - $a[0]) * ($c[1] - $a[1]);
        if (abs($cross) < 2.0 * self::MIN_AREA) {
            return;
        }
        if ($cross < 0.0) {
            [$b, $c] = [$c, $b];
        }
        $name = self::DEFAULT_ZONE;
        if ($zoneName !== null) {
            $name = $zoneName;
        } elseif ($this->zone !== null) {
            $name = (string) ($this->zone)(($a[0] + $b[0] + $c[0]) / 3.0, ($a[1] + $b[1] + $c[1]) / 3.0);
        }
        if (!isset($this->zones[$name])) {
            $this->zones[$name] = ['v' => [], 'n' => [], 'uv' => [], 'i' => [], 'c' => [], 'p' => []];
        }
        $ia = $this->vertex($name, $a);
        $ib = $this->vertex($name, $b);
        $ic = $this->vertex($name, $c);
        $this->zones[$name]['i'][] = $ia;
        $this->zones[$name]['i'][] = $ib;
        $this->zones[$name]['i'][] = $ic;
    }

    /**
     * Index of the vertex in a zone's mesh, adding it on first use: lattice corners by index, other points by position.
     *
     * @param array{0: float, 1: float, 2: float} $p
     */
    private function vertex(string $name, array $p): int
    {
        $u = $p[0] * $this->inv;
        $w = $p[1] * $this->inv;
        $ru = round($u);
        $rw = round($w);
        $zone = &$this->zones[$name];
        if (abs($u - $ru) < 1.0e-9 && abs($w - $rw) < 1.0e-9) {
            $ia = (int) $ru;
            $ja = (int) $rw;
            $key = $this->cellId($ia, $ja);
            if (isset($zone['c'][$key])) {
                return $zone['c'][$key];
            }
            $x = $ia * $this->cell;
            $z = $ja * $this->cell;
            $h = $this->grid->cornerHeight($ia, $ja);
            $n = $this->cornerNormal($ia, $ja);
            $index = intdiv(count($zone['v']), 3);
            $zone['c'][$key] = $index;
        } else {
            $key = self::positionKey($p[0], $p[1]);
            if (isset($zone['p'][$key])) {
                return $zone['p'][$key];
            }
            $x = $p[0];
            $z = $p[1];
            $h = $p[2];
            $n = $this->normalAt($x, $z);
            $index = intdiv(count($zone['v']), 3);
            $zone['p'][$key] = $index;
        }
        array_push($zone['v'], $x, $h, $z);
        array_push($zone['n'], $n[0], $n[1], $n[2]);
        array_push($zone['uv'], $x * $this->uvScale, $z * $this->uvScale);

        return $index;
    }

    private static function positionKey(float $x, float $z): int
    {
        return (int) round($x * 1.0e4) * 2147483648 + (int) round($z * 1.0e4);
    }

    /** @return array{0: float, 1: float, 2: float} */
    private function cornerNormal(int $ia, int $ja): array
    {
        $id = $this->cellId($ia, $ja);
        if (isset($this->cornerNormals[$id])) {
            return $this->cornerNormals[$id];
        }
        $g = $this->grid;
        $dx = ($g->cornerHeight($ia + 1, $ja) - $g->cornerHeight($ia - 1, $ja)) / (2.0 * $this->cell);
        $dz = ($g->cornerHeight($ia, $ja + 1) - $g->cornerHeight($ia, $ja - 1)) / (2.0 * $this->cell);
        $len = sqrt($dx * $dx + 1.0 + $dz * $dz);

        return $this->cornerNormals[$id] = [-$dx / $len, 1.0 / $len, -$dz / $len];
    }

    /**
     * Normal of a point inside a cell: its four corner normals blended bilinearly, renormalised.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private function normalAt(float $x, float $z): array
    {
        $u = max((float) $this->i0, min((float) ($this->i0 + $this->nx), $x * $this->inv));
        $w = max((float) $this->j0, min((float) ($this->j0 + $this->nz), $z * $this->inv));
        $ia = min($this->i0 + $this->nx - 1, (int) floor($u));
        $ja = min($this->j0 + $this->nz - 1, (int) floor($w));
        $fx = $u - $ia;
        $fz = $w - $ja;
        $n00 = $this->cornerNormal($ia, $ja);
        $n10 = $this->cornerNormal($ia + 1, $ja);
        $n01 = $this->cornerNormal($ia, $ja + 1);
        $n11 = $this->cornerNormal($ia + 1, $ja + 1);
        $out = [0.0, 0.0, 0.0];
        for ($k = 0; $k < 3; $k++) {
            $out[$k] = ($n00[$k] * (1.0 - $fx) + $n10[$k] * $fx) * (1.0 - $fz) + ($n01[$k] * (1.0 - $fx) + $n11[$k] * $fx) * $fz;
        }
        $len = sqrt($out[0] * $out[0] + $out[1] * $out[1] + $out[2] * $out[2]);

        return [$out[0] / $len, $out[1] / $len, $out[2] / $len];
    }
}
