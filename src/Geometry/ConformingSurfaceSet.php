<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * Several ground-following surfaces side by side – paths, plazas, yards with a
 * border band, inlays – built on one {@see ConformingRegionMesh} grid so that
 * every joint is seamless, plus a hem around the outer outline that falls from
 * the surface into the ground (no slab edge seen from the side, no gap).
 *
 *     $set = new ConformingSurfaceSet($groundAt, cell: 0.5, lift: 0.025, hemWidth: 0.35, hemSink: 0.05);
 *     $set->add('paving', RegionShape::polyline($mainPath, 1.5))
 *         ->add('paving', RegionShape::circle(10.0, 4.0, 6.0))
 *         ->add('gravel', RegionShape::polyline($sidePath, 0.9))
 *         ->border('paving', 0.3, 'kerb')
 *         ->inlay('marker', RegionShape::circle(10.0, 4.0, 0.8))
 *         ->cut(RegionShape::rect(0.0, 20.0, 8.0, 2.0));
 *     $meshes = $set->build(); // ['paving' => …, 'gravel' => …, 'kerb' => …, 'marker' => …, 'paving.hem' => …, 'gravel.hem' => …]
 *
 * Rules (each one an exact partition by complementary grid conditions, so no
 * two parts overlap and no gap opens between them):
 * - Surfaces: where shapes of several surfaces overlap, the surface added
 *   FIRST wins. Together they cover exactly the union of all shapes.
 * - Border (optional, per surface): the strip of that surface within $width of
 *   the union's OUTER outline (kerb, edging, plaza border band). Joints between
 *   two surfaces get no border.
 * - Inlays: shapes laid into the surfaces (marker discs, rails, coloured
 *   rings), clipped to the union; lower `layer` first, then order of adding.
 * - Hem: outside the union, $hemWidth wide, from $lift above the ground down to
 *   $hemSink below it; each hem point belongs to the nearest surface, so a hem
 *   can take that surface's material.
 * - Cuts: nothing (no surface, no hem, no inlay) inside a cut – e.g. at a quay
 *   edge, a water basin, a building footprint. A surface ends there with its
 *   open edge at $lift.
 *
 * Every top vertex sits at ground + $lift (the ground sampled at exactly that
 * vertex), the UVs are world XZ × scale (per part via {@see uvScale()}), the
 * normals point up. The surface carries no collider – at a few centimetres the
 * ground collider carries the player.
 *
 * One mesh per part; a caller merges the parts that share a material
 * ({@see MeshData::merge()}) to keep a whole network at a few draws.
 */
final class ConformingSurfaceSet
{
    /** Key suffix of a surface's hem in {@see build()}. */
    public const string HEM = '.hem';

    /** @var \Closure(float, float): float */
    private \Closure $ground;

    /** @var array<string, list<RegionShape>> surface => shapes, in priority order */
    private array $surfaces = [];

    /** @var array<string, array{0: float, 1: string}> surface => [width, part name] */
    private array $borders = [];

    /** @var array<string, array{layer: int, order: int, shapes: list<RegionShape>}> */
    private array $inlays = [];

    /** @var list<RegionShape> */
    private array $cuts = [];

    /** @var list<array{RegionShape, float, float, bool}> neighbour, lap, minimum width of a remnant outside it, close the wedge edges */
    private array $abuts = [];

    /** @var array<string, float> */
    private array $uvScales = [];

    private ?ConformingRegionMesh $grid = null;

    /**
     * @param callable(float, float): float $ground terrain height at (x, z); pass one
     *        {@see GroundMemo} to several sets (a staged family) and they share the samples
     * @param float $cell grid size (m); smaller follows terrain creases closer, costs more vertices
     * @param float $lift surface height above the ground (m)
     * @param float $hemWidth width of the hem outside the outline (m); 0 = no hem (bare edge)
     * @param float $hemSink how far below the ground the hem ends (m)
     * @param float $uvScale default UV = world XZ × scale
     */
    public function __construct(
        callable $ground,
        public readonly float $cell = 0.5,
        public readonly float $lift = 0.025,
        public readonly float $hemWidth = 0.35,
        public readonly float $hemSink = 0.05,
        public readonly float $uvScale = 1.0,
    ) {
        if ($cell <= 0.0 || $hemWidth < 0.0 || $hemSink < 0.0) {
            throw new \InvalidArgumentException('ConformingSurfaceSet: cell must be > 0, hem width and sink >= 0');
        }
        $this->ground = ($ground instanceof GroundMemo ? $ground : new GroundMemo($ground))(...);
    }

    /** Key of a surface's hem in {@see build()}. */
    public static function hemOf(string $surface): string
    {
        return $surface . self::HEM;
    }

    /** Add a shape to a surface (created on first use; earlier surfaces win overlaps). */
    public function add(string $surface, RegionShape $shape): self
    {
        $this->assertFree($surface, 'surface');
        $this->surfaces[$surface][] = $shape;
        $this->grid = null;

        return $this;
    }

    /** A strip of $width along the outer outline inside $surface, built as part $name. */
    public function border(string $surface, float $width, string $name): self
    {
        if ($width <= 0.0) {
            throw new \InvalidArgumentException('ConformingSurfaceSet::border: width must be > 0');
        }
        $this->assertFree($name, 'border');
        $this->borders[$surface] = [$width, $name];
        $this->grid = null;

        return $this;
    }

    /** Lay a shape into the surfaces as part $name (lower layer wins, then order of adding). */
    public function inlay(string $name, RegionShape $shape, int $layer = 0): self
    {
        $this->assertFree($name, 'inlay');
        $this->inlays[$name] ??= ['layer' => $layer, 'order' => count($this->inlays), 'shapes' => []];
        $this->inlays[$name]['shapes'][] = $shape;
        $this->grid = null;

        return $this;
    }

    /**
     * Let the set end flush against a neighbour surface – an imported road, a
     * quay, a floor – whose VISIBLE ground the ground function already reports
     * (the set rises $lift over that ground, so a neighbour that is not in the
     * ground would leave a $lift step). Shapes of the set should reach into the
     * neighbour by at least $lap. Inside the neighbour, over $lap:
     * - the surface's lift fades from $lift to 0 (smoothstep), so there is no
     *   step and no slab edge at the neighbour's edge;
     * - at $lap inside, where the lift has reached 0, the surface ends (as a
     *   {@see cut()} of the neighbour shrunk by $lap): a closed, flush edge, no
     *   gap and no strip of bare ground (the old feathered cut left one);
     * - the outline for border() and the hem is formed WITHOUT the stretches
     *   inside the neighbour: no kerb and no hem along the abutting edge, and a
     *   kerb band opens where a path joins the neighbour.
     * Several neighbours may be added; where they overlap the nearest one rules.
     * Without abut() nothing changes (bit for bit). The shapes' distances must
     * be exact (circle, rect, polygon, polyline): the ramp is measured with them.
     *
     * $minWidth (default 0 = off) removes thin remnants: a surface that runs out
     * tangentially along the neighbour's outer edge (a tapering fillet, a mouth
     * shape) leaves a strip OUTSIDE the neighbour. Along every line perpendicular
     * to the neighbour's edge, a surface whose outer outline lies less than
     * $minWidth beyond the edge is removed there – also its lap zone inside the
     * neighbour, its kerb and its hem – and the surface that stays fades its lift
     * to 0 towards the cut over max(0.2, $minWidth / 4), so the cut is flush: no
     * step, no open slab end. A path that meets the edge head on is not touched
     * (it is long along the perpendicular), and neither is a surface wider than
     * $minWidth + that fade. Surfaces between $minWidth and $minWidth + fade wide
     * beside the neighbour are a little lower where they are that narrow.
     *
     * $closeWedge (default false = as before) closes the two side edges of the wedge
     * on which the surface fades inside the neighbour (up to $lift high, a few
     * decimetres long each, open to the side): the hem runs along them too,
     * starting at the faded lift and falling to $hemSink below the ground, under
     * the neighbour's own surface. It has no effect without a hem. Kerb is not
     * added there.
     *
     * A MeshSetCache key built for a set with abut() must contain the neighbour
     * shapes, $lap, $minWidth and $closeWedge, like every other input of the build.
     */
    public function abut(RegionShape $neighbour, float $lap = 0.3, float $minWidth = 0.0, bool $closeWedge = false): self
    {
        if ($lap <= 0.0) {
            throw new \InvalidArgumentException('ConformingSurfaceSet::abut: lap must be > 0');
        }
        if ($minWidth < 0.0) {
            throw new \InvalidArgumentException('ConformingSurfaceSet::abut: minWidth must be >= 0');
        }
        $this->abuts[] = [$neighbour, $lap, $minWidth, $closeWedge];
        $this->grid = null;

        return $this;
    }

    /** Keep the shape free of everything. */
    public function cut(RegionShape $shape): self
    {
        $this->cuts[] = $shape;
        $this->grid = null;

        return $this;
    }

    /** UV scale of one part (surface, hem, border or inlay key). */
    public function uvScale(string $part, float $scale): self
    {
        $this->uvScales[$part] = $scale;

        return $this;
    }

    /**
     * The ground the set samples (memoised) – also the reference for
     * {@see SurfaceFit::measure()}.
     *
     * @return \Closure(float, float): float
     */
    public function ground(): \Closure
    {
        return $this->ground;
    }

    /**
     * All parts as meshes, empty ones left out. Keys: surface names, border
     * names, inlay names and `<surface>.hem` ({@see hemOf()}).
     *
     * @return array<string, MeshData>
     */
    public function build(): array
    {
        if ($this->surfaces === []) {
            return [];
        }
        $grid = $this->grid();
        $order = array_keys($this->surfaces);
        $g = $this->ground;
        $common = $this->cuts !== [] || $this->abuts !== [] ? [['cut', -1]] : [];
        $slivers = false;
        foreach ($this->abuts as [, , $minWidth]) {
            $slivers = $slivers || $minWidth > 0.0;
        }
        if ($slivers) {
            $common[] = ['sliv', -1];
        }
        $inlayNames = $this->inlayOrder();
        $notInlay = $inlayNames !== [] ? [['inlay', -1]] : [];
        $abutting = $this->abuts !== [];
        $top = $abutting
            ? ConformingRegionMesh::onGroundRamped($g, $this->lift, 'abutn', $slivers ? 'sliv' : null)
            : ConformingRegionMesh::onGround($g, $this->lift);
        $topCarry = $abutting ? ($slivers ? ['abutn', 'sliv'] : ['abutn']) : [];
        // Hem along the wedge edges: some neighbour asks for it. Its hem field then leaves out only the others.
        $closing = false;
        $leaving = false;
        foreach ($this->abuts as [, , , $close]) {
            $closing = $closing || $close;
            $leaving = $leaving || !$close;
        }
        $hemOutside = !$abutting ? 'union' : (!$closing ? 'unionx' : ($leaving ? 'unionh' : 'union'));
        $jobs = [];

        // Surfaces: the union, partitioned by priority (the last one takes the rest).
        $last = count($order) - 1;
        foreach ($order as $k => $s) {
            $cond = [['union', 1]];
            for ($h = 0; $h < $k; $h++) {
                $cond[] = ['d:' . $order[$h], -1];
            }
            if ($k < $last) {
                $cond[] = ['d:' . $s, 1];
            }
            $cond = [...$cond, ...$notInlay, ...$common];
            if (isset($this->borders[$s])) {
                [, $name] = $this->borders[$s];
                $jobs[$name] = [[...$cond, ['border:' . $s, -1]], $top, $topCarry];
                $cond[] = ['border:' . $s, 1];
            }
            $jobs[$s] = [$cond, $top, $topCarry];
        }

        // Inlays: inside the union, partitioned by layer/order.
        $lastInlay = count($inlayNames) - 1;
        foreach ($inlayNames as $k => $name) {
            $cond = [['inlay', 1], ['union', 1]];
            for ($h = 0; $h < $k; $h++) {
                $cond[] = ['inlay:' . $inlayNames[$h], -1];
            }
            if ($k < $lastInlay) {
                $cond[] = ['inlay:' . $name, 1];
            }
            $jobs[$name] = [[...$cond, ...$common], $top, $topCarry];
        }

        // Hems: outside the union, each point to the nearest surface.
        if ($this->hemWidth > 0.0) {
            $hem = $closing
                ? ConformingRegionMesh::hemRamped($g, 'union', $this->lift, $this->hemWidth, $this->hemSink, 'abutn', $slivers ? 'sliv' : null)
                : ConformingRegionMesh::hem($g, 'union', $this->lift, $this->hemWidth, $this->hemSink);
            $hemCarry = $closing ? ($slivers ? ['union', 'abutn', 'sliv'] : ['union', 'abutn']) : ['union'];
            foreach ($order as $k => $s) {
                // Outside the surfaces, and outside the neighbours they abut (no hem along an abutting edge).
                $cond = [['hem', 1], [$hemOutside, -1]];
                if ($last > 0) {
                    for ($h = 0; $h < $k; $h++) {
                        $cond[] = ['near:' . $order[$h], -1];
                    }
                    if ($k < $last) {
                        $cond[] = ['near:' . $s, 1];
                    }
                }
                $key = self::hemOf($s);
                $jobs[$key] = [[...$cond, ...$common], $hem, $hemCarry];
            }
        }

        // Every part is cut along every partition line of the set, so that all
        // parts share their vertices at every joint (no T-junctions).
        $splits = [];
        foreach ($jobs as [$cond]) {
            foreach ($cond as [$f]) {
                $splits[$f] = true;
            }
        }
        if ($abutting) {
            $splits['nd'] = true; // a vertex row on the neighbour's edge, where the lift starts to fade
        }
        $splits = array_keys($splits);
        $out = [];
        foreach ($jobs as $key => [$cond, $height, $carry]) {
            $mesh = $grid->mesh($cond, $height, $carry, $this->scaleOf($key), $splits);
            if ($mesh->indices !== []) {
                $out[$key] = $mesh;
            }
        }

        return $out;
    }

    /**
     * The grid with all fields (built on first use). Field names:
     * `d:<surface>` signed distance to that surface's shapes, `union` to all
     * surfaces, `hem` = union − hem width, `near:<surface>` = d:surface minus
     * the nearest other surface, `outline` = distance to the union's outer
     * outline (exact inside within the widest border; only with borders),
     * `border:<surface>` = outline + border width,
     * `inlay:<name>`, `inlay` (all inlays), `cut`. Use it to sample distances
     * for placement ("is this lamp outside every surface?") or to mesh extra
     * regions on the same corners.
     */
    public function grid(): ConformingRegionMesh
    {
        if ($this->grid !== null) {
            return $this->grid;
        }
        $margin = $this->hemWidth + 2.0 * $this->cell;
        $box = [INF, INF, -INF, -INF];
        $all = [];
        foreach ($this->surfaces as $shapes) {
            array_push($all, ...$shapes);
        }
        foreach ($this->inlays as $i) {
            array_push($all, ...$i['shapes']);
        }
        foreach ($all as $shape) {
            [$a, $b, $c, $d] = $shape->bounds();
            $box = [min($box[0], $a), min($box[1], $b), max($box[2], $c), max($box[3], $d)];
        }
        if ($all === []) {
            $box = [0.0, 0.0, $this->cell, $this->cell];
        }
        $grid = ConformingRegionMesh::covering($box[0], $box[1], $box[2], $box[3], $this->cell, $margin + $this->cell);

        $dFields = [];
        foreach ($this->surfaces as $s => $shapes) {
            foreach ($shapes as $shape) {
                $grid->stampShape('d:' . $s, $shape, $margin);
            }
            $dFields[] = 'd:' . $s;
        }
        if ($dFields !== []) {
            $grid->derive('union', $dFields, self::minOf(...));
            $hw = $this->hemWidth;
            $grid->derive('hem', ['union'], static fn (array $v): float => $v['union'] - $hw);
            if (count($dFields) > 1) {
                foreach ($dFields as $f) {
                    $grid->derive('near:' . substr($f, 2), $dFields, static function (array $v) use ($f): float {
                        $o = ConformingRegionMesh::FAR;
                        foreach ($v as $name => $val) {
                            if ($name !== $f && $val < $o) {
                                $o = $val;
                            }
                        }

                        // Ties (two surfaces equally near over an area) go to the earlier one.
                        return $v[$f] - $o - 1e-6;
                    });
                }
            }
        }
        if ($this->abuts !== [] && $dFields !== []) {
            // nd: signed distance to the nearest neighbour; abutn: the same in laps (the lift ramp);
            // unionx: surfaces and neighbours together, the outline kerb and hem are formed from.
            $names = [];
            $laps = [];
            foreach ($this->abuts as $i => [$shape, $lap]) {
                $grid->stampShape('nd:' . $i, $shape, $margin);
                $names[] = 'nd:' . $i;
                $laps['nd:' . $i] = $lap;
            }
            $grid->derive('nd', $names, self::minOf(...));
            $grid->derive('abutn', $names, static function (array $v) use ($laps): float {
                $m = ConformingRegionMesh::FAR;
                foreach ($v as $name => $d) {
                    $m = min($m, $d / $laps[$name]);
                }

                return $m;
            });
            $grid->derive('unionx', ['union', 'nd'], self::minOf(...));
            $others = [];
            foreach ($this->abuts as $i => [, , , $close]) {
                if (!$close) {
                    $others[] = 'nd:' . $i;
                }
            }
            if ($others !== [] && $others !== $names) {
                // The hem leaves out only the neighbours that do not close their wedge edges.
                $grid->derive('ndh', $others, self::minOf(...));
                $grid->derive('unionh', ['union', 'ndh'], self::minOf(...));
            }
            foreach ($this->abuts as $i => [$shape, $lap, $minWidth]) {
                if ($minWidth > 0.0) {
                    $this->stampSlivers($grid, $shape, 'nd:' . $i, $lap, $minWidth);
                }
            }
        }
        if ($this->borders !== []) {
            $reach = 0.0;
            foreach ($this->borders as [$width]) {
                $reach = max($reach, $width);
            }
            // min() of the surfaces is exact outside; inside it would measure to
            // joints between surfaces too – the border needs the outer outline.
            $grid->redistance('outline', $this->abuts !== [] ? 'unionx' : 'union', $reach + $this->cell);
            foreach ($this->borders as $s => [$width]) {
                $grid->derive('border:' . $s, ['outline'], static fn (array $v): float => $v['outline'] + $width);
            }
        }
        $inlayFields = [];
        foreach ($this->inlays as $name => $i) {
            foreach ($i['shapes'] as $shape) {
                $grid->stampShape('inlay:' . $name, $shape, 2.0 * $this->cell);
            }
            $inlayFields[] = 'inlay:' . $name;
        }
        if ($inlayFields !== []) {
            $grid->derive('inlay', $inlayFields, self::minOf(...));
        }
        foreach ($this->cuts as $shape) {
            $grid->stampShape('cut', $shape, $margin);
        }
        foreach ($this->abuts as [$shape, $lap]) {
            // The neighbour shrunk by its lap: where the lift has faded to 0 the surface ends.
            [$x0, $z0, $x1, $z1] = $shape->bounds();
            $grid->stampShape('cut', RegionShape::field($x0, $z0, $x1, $z1, static fn (float $x, float $z): float => $shape->distance($x, $z) + $lap), $margin);
        }

        return $this->grid = $grid;
    }

    /**
     * The field `sliv` for one neighbour: along the line perpendicular to the
     * neighbour's edge through each corner near it, how far the surfaces reach
     * beyond the edge (w, by marching the `union` field). Value
     * (w - minWidth) / fade where w is known and below minWidth + fade, with a
     * steep wall far behind the remnant's outer edge (its hem), so negative =
     * remove this spot, 0 = the cut line, 1 and more = clear of it. Corners not
     * written keep FAR: nothing to remove.
     */
    private function stampSlivers(ConformingRegionMesh $grid, RegionShape $neighbour, string $field, float $lap, float $minWidth): void
    {
        $fade = max(0.2, 0.25 * $minWidth);
        $reach = $minWidth + $fade;
        $far = $this->hemWidth + $this->cell;
        $step = $this->cell * 0.25;
        $h = 1.0e-4;
        foreach ($grid->corners($field) as [$ix, $iz, $x, $z, $dn]) {
            if ($dn < -$lap - $this->cell || $dn > $reach + $far + $this->cell) {
                continue;
            }
            $gx = ($neighbour->distance($x + $h, $z) - $neighbour->distance($x - $h, $z)) / (2.0 * $h);
            $gz = ($neighbour->distance($x, $z + $h) - $neighbour->distance($x, $z - $h)) / (2.0 * $h);
            $len = hypot($gx, $gz);
            if ($len < 1.0e-9) {
                continue;
            }
            $gx /= $len;
            $gz /= $len;
            // The foot of the perpendicular on the neighbour's edge; the surface has to cover it.
            $bx = $x - $dn * $gx;
            $bz = $z - $dn * $gz;
            $prev = $grid->sample('union', $bx, $bz);
            if ($prev > 0.0) {
                continue;
            }
            $w = null;
            for ($d = $step; $d <= $reach + $step; $d += $step) {
                $u = $grid->sample('union', $bx + $d * $gx, $bz + $d * $gz);
                if ($u > 0.0) {
                    $w = $d - $step + $step * $prev / ($prev - $u);
                    break;
                }
                $prev = $u;
            }
            if ($w === null) {
                continue; // wide enough: untouched
            }
            $s = max($w - $minWidth, 50.0 * ($dn - ($w + $far)));
            $grid->merge('sliv', $ix, $iz, $s / $fade);
        }
    }

    /** @param array<string, float> $v */
    private static function minOf(array $v): float
    {
        $m = ConformingRegionMesh::FAR;
        foreach ($v as $x) {
            if ($x < $m) {
                $m = $x;
            }
        }

        return $m;
    }

    /** @return list<string> inlay names in priority order */
    private function inlayOrder(): array
    {
        $names = array_keys($this->inlays);
        usort($names, fn (string $a, string $b): int => [$this->inlays[$a]['layer'], $this->inlays[$a]['order']] <=> [$this->inlays[$b]['layer'], $this->inlays[$b]['order']]);

        return $names;
    }

    private function scaleOf(string $part): float
    {
        return $this->uvScales[$part] ?? $this->uvScale;
    }

    private function assertFree(string $name, string $as): void
    {
        $taken = [];
        foreach (array_keys($this->surfaces) as $s) {
            $taken[$s] = 'surface';
            $taken[self::hemOf($s)] = 'hem';
        }
        foreach ($this->borders as [, $n]) {
            $taken[$n] = 'border';
        }
        foreach (array_keys($this->inlays) as $n) {
            $taken[$n] = 'inlay';
        }
        if ($name === '' || str_contains($name, ':')) {
            throw new \InvalidArgumentException("ConformingSurfaceSet: invalid part name '{$name}'");
        }
        if (isset($taken[$name]) && $taken[$name] !== $as) {
            throw new \InvalidArgumentException("ConformingSurfaceSet: '{$name}' is already a {$taken[$name]}");
        }
        if ($as === 'surface' && isset($taken[self::hemOf($name)]) && $taken[self::hemOf($name)] !== 'hem') {
            throw new \InvalidArgumentException("ConformingSurfaceSet: '" . self::hemOf($name) . "' is already a {$taken[self::hemOf($name)]}");
        }
        if ($as === 'border' && isset($taken[$name])) {
            throw new \InvalidArgumentException("ConformingSurfaceSet: border '{$name}' is already used");
        }
    }
}
