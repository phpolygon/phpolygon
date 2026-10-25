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

    /** @var array<string, float> */
    private array $uvScales = [];

    private ?ConformingRegionMesh $grid = null;

    /**
     * @param callable(float, float): float $ground terrain height at (x, z)
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
        $memo = [];
        $this->ground = static function (float $x, float $z) use ($ground, &$memo): float {
            $k = round($x, 4) . '|' . round($z, 4);

            return $memo[$k] ??= (float) $ground($x, $z);
        };
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
        $common = $this->cuts !== [] ? [['cut', -1]] : [];
        $inlayNames = $this->inlayOrder();
        $notInlay = $inlayNames !== [] ? [['inlay', -1]] : [];
        $top = ConformingRegionMesh::onGround($g, $this->lift);
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
                $jobs[$name] = [[...$cond, ['border:' . $s, -1]], $top, []];
                $cond[] = ['border:' . $s, 1];
            }
            $jobs[$s] = [$cond, $top, []];
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
            $jobs[$name] = [[...$cond, ...$common], $top, []];
        }

        // Hems: outside the union, each point to the nearest surface.
        if ($this->hemWidth > 0.0) {
            $hem = ConformingRegionMesh::hem($g, 'union', $this->lift, $this->hemWidth, $this->hemSink);
            foreach ($order as $k => $s) {
                $cond = [['hem', 1], ['union', -1]];
                if ($last > 0) {
                    for ($h = 0; $h < $k; $h++) {
                        $cond[] = ['near:' . $order[$h], -1];
                    }
                    if ($k < $last) {
                        $cond[] = ['near:' . $s, 1];
                    }
                }
                $key = self::hemOf($s);
                $jobs[$key] = [[...$cond, ...$common], $hem, ['union']];
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
        if ($this->borders !== []) {
            $reach = 0.0;
            foreach ($this->borders as [$width]) {
                $reach = max($reach, $width);
            }
            // min() of the surfaces is exact outside; inside it would measure to
            // joints between surfaces too – the border needs the outer outline.
            $grid->redistance('outline', 'union', $reach + $this->cell);
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

        return $this->grid = $grid;
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
