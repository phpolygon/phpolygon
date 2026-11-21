<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * Convex polygon clipping against half spaces and square prisms. Polygons are lists of world points [x, y, z];
 * pieces come back convex and keep the input's winding.
 */
final class PrismClip
{
    /**
     * @param PlanarFrame $frame  frame the prism is axis-aligned in
     * @param float $half         half width of the square cross section (|u|, |w| < half)
     * @param float $reach        a polygon none of whose vertices is within `$half + $reach` of the axis in both u and w
     *                            is returned whole without testing (a speed cut-off; polygons must be smaller than this)
     */
    public function __construct(
        public readonly PlanarFrame $frame,
        public readonly float $half,
        public readonly float $reach = 30.0,
    ) {}

    /**
     * The part of a convex polygon that lies OUTSIDE the prism, as convex pieces. A polygon that does not reach into
     * the prism is returned whole (no needless cut lines, no T-junctions).
     *
     * @param list<array{0: float, 1: float, 2: float}> $poly
     * @return list<list<array{0: float, 1: float, 2: float}>>
     */
    public function outside(array $poly): array
    {
        $f = $this->frame;
        $h = $this->half;
        $near = false;
        foreach ($poly as $p) {
            [$u, $w] = $f->local($p[0], $p[2]);
            if (abs($u) < $h + $this->reach && abs($w) < $h + $this->reach) {
                $near = true;
                break;
            }
        }
        if (!$near) {
            return [$poly];
        }
        $inside = $poly;
        foreach ([[-1.0, -$h], [1.0, -$h]] as [$s, $lim]) {
            $inside = $this->keep($inside, true, $s, $lim);
        }
        foreach ([[-1.0, -$h], [1.0, -$h]] as [$s, $lim]) {
            $inside = $this->keep($inside, false, $s, $lim);
        }
        if (count($inside) < 3 || $this->areaLocal($inside) < 1e-6) {
            return [$poly];
        }
        $mid = $this->keep($this->keep($poly, true, -1.0, -$h), true, 1.0, -$h);
        $pieces = [
            $this->keep($poly, true, 1.0, $h),
            $this->keep($poly, true, -1.0, $h),
            $this->keep($mid, false, 1.0, $h),
            $this->keep($mid, false, -1.0, $h),
        ];
        return array_values(array_filter($pieces, static fn (array $p): bool => count($p) >= 3));
    }

    /**
     * Sutherland-Hodgman against one half space in the XZ plane: keeps the part where `$nx * x + $nz * z + $offset >= 0`.
     * Y is interpolated along the cut edges.
     *
     * @param list<array{0: float, 1: float, 2: float}> $poly
     * @return list<array{0: float, 1: float, 2: float}>
     */
    public static function clipHalfSpace(array $poly, float $nx, float $nz, float $offset): array
    {
        $out = [];
        $n = count($poly);
        for ($i = 0; $i < $n; $i++) {
            $a = $poly[$i];
            $b = $poly[($i + 1) % $n];
            $da = $nx * $a[0] + $nz * $a[2] + $offset;
            $db = $nx * $b[0] + $nz * $b[2] + $offset;
            if ($da >= 0.0) {
                $out[] = $a;
            }
            if (($da >= 0.0) !== ($db >= 0.0)) {
                $t = $da / ($da - $db);
                $out[] = [$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t, $a[2] + ($b[2] - $a[2]) * $t];
            }
        }

        return $out;
    }

    /**
     * Keeps the part of `$poly` where `$sign * coordinate >= $limit`, the coordinate being the frame's u or w.
     *
     * @param list<array{0: float, 1: float, 2: float}> $poly
     * @return list<array{0: float, 1: float, 2: float}>
     */
    private function keep(array $poly, bool $alongU, float $sign, float $limit): array
    {
        $f = $this->frame;
        // u = (x - ox) dx + (z - oz) dz ; w = -(x - ox) dz + (z - oz) dx
        if ($alongU) {
            $nx = $sign * $f->dirX;
            $nz = $sign * $f->dirZ;
            $c = -$sign * ($f->originX * $f->dirX + $f->originZ * $f->dirZ) - $limit;
        } else {
            $nx = -$sign * $f->dirZ;
            $nz = $sign * $f->dirX;
            $c = $sign * ($f->originX * $f->dirZ - $f->originZ * $f->dirX) - $limit;
        }

        return self::clipHalfSpace($poly, $nx, $nz, $c);
    }
    /**
     * Area of a polygon projected into the frame (m2).
     *
     * @param list<array{0: float, 1: float, 2: float}> $poly
     */
    private function areaLocal(array $poly): float
    {
        $sum = 0.0;
        $n = count($poly);
        for ($i = 0; $i < $n; $i++) {
            [$u0, $w0] = $this->frame->local($poly[$i][0], $poly[$i][2]);
            [$u1, $w1] = $this->frame->local($poly[($i + 1) % $n][0], $poly[($i + 1) % $n][2]);
            $sum += $u0 * $w1 - $u1 * $w0;
        }

        return abs($sum) * 0.5;
    }
}






