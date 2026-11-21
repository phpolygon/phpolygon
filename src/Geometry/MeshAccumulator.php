<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * Accumulates flat- or smooth-shaded polygon faces into one {@see MeshData}. Every face gets its own vertices,
 * the winding is turned to face a given direction, UVs are planar projections along the dominant normal axis
 * (`$uvScale` per metre). Pure data, no registry; the same calls give the same bits.
 *
 * The frame helpers ({@see rect()}, {@see box()}, {@see walls()}, {@see bevel()}) build axis-aligned pieces in a
 * {@see PlanarFrame} so a turned shaft, pad or room needs no hand-rolled corner maths.
 */
final class MeshAccumulator
{
    /** @var list<float> */
    private array $v = [];
    /** @var list<float> */
    private array $n = [];
    /** @var list<float> */
    private array $uv = [];
    /** @var list<int> */
    private array $i = [];

    /** @param float $uvScale texture coordinates per metre */
    public function __construct(private readonly float $uvScale = 0.5) {}

    /**
     * Quad a-b-c-d (around the outline) facing `$hint`.
     *
     * @param array{0: float, 1: float, 2: float} $a
     * @param array{0: float, 1: float, 2: float} $b
     * @param array{0: float, 1: float, 2: float} $c
     * @param array{0: float, 1: float, 2: float} $d
     * @param array{0: float, 1: float, 2: float} $hint
     */
    public function quad(array $a, array $b, array $c, array $d, array $hint): void
    {
        $this->fan([$a, $b, $c, $d], $hint);
    }

    /**
     * Convex polygon as a triangle fan, flat normal, wound to face `$hint`. Degenerate triangles are skipped.
     *
     * @param list<array{0: float, 1: float, 2: float}> $poly
     * @param array{0: float, 1: float, 2: float} $hint
     */
    public function fan(array $poly, array $hint): void
    {
        $count = count($poly);
        for ($t = 1; $t + 1 < $count; $t++) {
            $tri = [$poly[0], $poly[$t], $poly[$t + 1]];
            $cross = self::cross($tri);
            $len = sqrt($cross[0] ** 2 + $cross[1] ** 2 + $cross[2] ** 2);
            if ($len < 1e-9) {
                continue;
            }
            $dot = $cross[0] * $hint[0] + $cross[1] * $hint[1] + $cross[2] * $hint[2];
            if ($dot < 0.0) {
                $tri = [$tri[0], $tri[2], $tri[1]];
                $cross = [-$cross[0], -$cross[1], -$cross[2]];
            }
            $nrm = [$cross[0] / $len, $cross[1] / $len, $cross[2] / $len];
            $this->push($tri, [$nrm, $nrm, $nrm]);
        }
    }

    /**
     * Convex polygon as a triangle fan with per-vertex normals from `$normalAt`, wound to face the normals.
     *
     * @param list<array{0: float, 1: float, 2: float}> $poly
     * @param \Closure(float, float, float): array{0: float, 1: float, 2: float} $normalAt
     */
    public function fanSmooth(array $poly, \Closure $normalAt): void
    {
        $count = count($poly);
        for ($t = 1; $t + 1 < $count; $t++) {
            $tri = [$poly[0], $poly[$t], $poly[$t + 1]];
            $cross = self::cross($tri);
            if (sqrt($cross[0] ** 2 + $cross[1] ** 2 + $cross[2] ** 2) < 1e-9) {
                continue;
            }
            $ns = [$normalAt(...$tri[0]), $normalAt(...$tri[1]), $normalAt(...$tri[2])];
            $dot = $cross[0] * ($ns[0][0] + $ns[1][0] + $ns[2][0]) + $cross[1] * ($ns[0][1] + $ns[1][1] + $ns[2][1]) + $cross[2] * ($ns[0][2] + $ns[1][2] + $ns[2][2]);
            if ($dot < 0.0) {
                $tri = [$tri[0], $tri[2], $tri[1]];
                $ns = [$ns[0], $ns[2], $ns[1]];
            }
            $this->push($tri, $ns);
        }
    }

    /** A horizontal rectangle of the frame at height `$y`, facing up (`$up`) or down. */
    public function rect(PlanarFrame $f, float $u0, float $u1, float $w0, float $w1, float $y, bool $up): void
    {
        $this->quad($f->point($u0, $y, $w0), $f->point($u1, $y, $w0), $f->point($u1, $y, $w1), $f->point($u0, $y, $w1), [0.0, $up ? 1.0 : -1.0, 0.0]);
    }

    /** A closed box of the frame (six faces, outward). */
    public function box(PlanarFrame $f, float $u0, float $u1, float $w0, float $w1, float $y0, float $y1): void
    {
        $this->rect($f, $u0, $u1, $w0, $w1, $y1, true);
        $this->rect($f, $u0, $u1, $w0, $w1, $y0, false);
        $this->walls($f, $u0, $u1, $w0, $w1, $y0, $y1, false);
    }

    /**
     * The four vertical walls of a rectangular prism of the frame; `$inward` turns the faces towards the prism's axis
     * (the walls of a hole), otherwise they face out.
     */
    public function walls(PlanarFrame $f, float $u0, float $u1, float $w0, float $w1, float $y0, float $y1, bool $inward): void
    {
        $s = $inward ? -1.0 : 1.0;
        $du = $f->uAxis();
        $dw = $f->wAxis();
        $eu = [$du[0] * $s, 0.0, $du[2] * $s];
        $ew = [$dw[0] * $s, 0.0, $dw[2] * $s];
        $neg = self::negate(...);
        $this->quad($f->point($u1, $y0, $w0), $f->point($u1, $y1, $w0), $f->point($u1, $y1, $w1), $f->point($u1, $y0, $w1), $eu);
        $this->quad($f->point($u0, $y0, $w0), $f->point($u0, $y1, $w0), $f->point($u0, $y1, $w1), $f->point($u0, $y0, $w1), $neg($eu));
        $this->quad($f->point($u0, $y0, $w1), $f->point($u1, $y0, $w1), $f->point($u1, $y1, $w1), $f->point($u0, $y1, $w1), $ew);
        $this->quad($f->point($u0, $y0, $w0), $f->point($u1, $y0, $w0), $f->point($u1, $y1, $w0), $f->point($u0, $y1, $w0), $neg($ew));
    }

    /**
     * The four sloped faces between a top rectangle and a larger base rectangle (both `[u0, u1, half w]`, w centred on 0),
     * facing outward and up.
     *
     * @param array{0: float, 1: float, 2: float} $top
     * @param array{0: float, 1: float, 2: float} $base
     */
    public function bevel(PlanarFrame $f, array $top, float $yTop, array $base, float $yBase): void
    {
        [$tu0, $tu1, $tw] = $top;
        [$bu0, $bu1, $bw] = $base;
        $corners = [
            [[$tu0, -$tw], [$tu1, -$tw], [$bu1, -$bw], [$bu0, -$bw]],
            [[$tu1, -$tw], [$tu1, $tw], [$bu1, $bw], [$bu1, -$bw]],
            [[$tu1, $tw], [$tu0, $tw], [$bu0, $bw], [$bu1, $bw]],
            [[$tu0, $tw], [$tu0, -$tw], [$bu0, -$bw], [$bu0, $bw]],
        ];
        foreach ($corners as $q) {
            $a = $f->point($q[0][0], $yTop, $q[0][1]);
            $b = $f->point($q[1][0], $yTop, $q[1][1]);
            $c = $f->point($q[2][0], $yBase, $q[2][1]);
            $d = $f->point($q[3][0], $yBase, $q[3][1]);
            // Hint: out of the top centre (horizontal part of the face's mid point) and up.
            $mx = ($c[0] + $d[0]) * 0.5 - ($a[0] + $b[0]) * 0.5;
            $mz = ($c[2] + $d[2]) * 0.5 - ($a[2] + $b[2]) * 0.5;
            $this->quad($a, $b, $c, $d, [$mx, 0.6 * hypot($mx, $mz), $mz]);
        }
    }

    /**
     * @param array{0: float, 1: float, 2: float} $v
     * @return array{0: float, 1: float, 2: float}
     */
    private static function negate(array $v): array
    {
        return [-$v[0], -$v[1], -$v[2]];
    }

    public function build(): MeshData
    {
        return new MeshData($this->v, $this->n, $this->uv, $this->i);
    }

    /**
     * @param list<array{0: float, 1: float, 2: float}> $tri
     * @param list<array{0: float, 1: float, 2: float}> $ns
     */
    private function push(array $tri, array $ns): void
    {
        $base = intdiv(count($this->v), 3);
        $s = $this->uvScale;
        foreach ($tri as $k => $p) {
            $nrm = $ns[$k];
            $this->v[] = $p[0];
            $this->v[] = $p[1];
            $this->v[] = $p[2];
            $this->n[] = $nrm[0];
            $this->n[] = $nrm[1];
            $this->n[] = $nrm[2];
            if (abs($nrm[1]) > 0.7) {
                $this->uv[] = $p[0] * $s;
                $this->uv[] = $p[2] * $s;
            } elseif (abs($nrm[0]) > abs($nrm[2])) {
                $this->uv[] = $p[2] * $s;
                $this->uv[] = $p[1] * $s;
            } else {
                $this->uv[] = $p[0] * $s;
                $this->uv[] = $p[1] * $s;
            }
        }
        array_push($this->i, $base, $base + 1, $base + 2);
    }

    /**
     * @param list<array{0: float, 1: float, 2: float}> $tri
     * @return array{0: float, 1: float, 2: float}
     */
    private static function cross(array $tri): array
    {
        $ux = $tri[1][0] - $tri[0][0];
        $uy = $tri[1][1] - $tri[0][1];
        $uz = $tri[1][2] - $tri[0][2];
        $wx = $tri[2][0] - $tri[0][0];
        $wy = $tri[2][1] - $tri[0][1];
        $wz = $tri[2][2] - $tri[0][2];

        return [$uy * $wz - $uz * $wy, $uz * $wx - $ux * $wz, $ux * $wy - $uy * $wx];
    }
}


