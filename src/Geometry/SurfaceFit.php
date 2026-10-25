<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * How well a surface mesh hugs the ground – the measurement behind "no floating
 * slabs, no ground poking through, no visible slab edge" audits and tests.
 *
 * {@see measure()} samples every non-vertical triangle at its corners, edge
 * midpoints and centroid and compares the surface height with the ground
 * there (lift = surface − ground): the highest and lowest lift with their
 * positions, and the share of samples above / below given thresholds. Then it
 * walks the OPEN edges (edges with only one triangle – the visible outline) and
 * reports the highest one above the ground: without a hem that is the slab
 * thickness seen from the side; with a hem into the ground it is ≤ 0.
 *
 * Measure the merged mesh of all parts of a network (tops, borders, inlays and
 * hems together, {@see MeshData::merge()}) to see only the outer outline as
 * open edges – joints between parts share their vertices and are closed.
 */
final readonly class SurfaceFit
{
    /**
     * @param ?array{float, float} $liftMaxAt
     * @param ?array{float, float} $liftMinAt
     * @param ?array{float, float} $openEdgeAt
     */
    public function __construct(
        public int $triangles,
        public int $samples,
        public float $liftMax,
        public ?array $liftMaxAt,
        public float $liftMin,
        public ?array $liftMinAt,
        public float $shareAbove,
        public float $shareBelow,
        public int $openEdges,
        public float $openEdgeMax,
        public ?array $openEdgeAt,
    ) {
    }

    /**
     * @param callable(float, float): ?float $ground ground height at (x, z); null = unknown there (sample skipped)
     * @param float $above samples with lift above this count into {@see $shareAbove}
     * @param float $below samples with lift below this count into {@see $shareBelow} (ground pokes through)
     */
    public static function measure(MeshData $mesh, callable $ground, float $above = 0.045, float $below = -0.02): self
    {
        $v = $mesh->vertices;
        $idx = $mesh->indices;
        $liftMax = -INF;
        $liftMin = INF;
        $maxAt = null;
        $minAt = null;
        $samples = 0;
        $high = 0;
        $low = 0;
        $edges = [];
        // + 0.0 folds −0 into 0, so a vertex at −1e-17 matches one at 0.
        $key = static fn (int $i): string => (round($v[$i * 3], 3) + 0.0) . ':' . (round($v[$i * 3 + 1], 3) + 0.0) . ':' . (round($v[$i * 3 + 2], 3) + 0.0);
        $weights = [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0], [0.5, 0.5, 0.0], [0.0, 0.5, 0.5], [0.5, 0.0, 0.5], [1 / 3, 1 / 3, 1 / 3]];
        $tris = intdiv(count($idx), 3);
        for ($t = 0; $t < $tris; $t++) {
            $i0 = $idx[$t * 3];
            $i1 = $idx[$t * 3 + 1];
            $i2 = $idx[$t * 3 + 2];
            $p = [[$v[$i0 * 3], $v[$i0 * 3 + 1], $v[$i0 * 3 + 2]], [$v[$i1 * 3], $v[$i1 * 3 + 1], $v[$i1 * 3 + 2]], [$v[$i2 * 3], $v[$i2 * 3 + 1], $v[$i2 * 3 + 2]]];
            // Vertical triangles (a wall, a plank's end) are no surface over the ground.
            $area = ($p[1][0] - $p[0][0]) * ($p[2][2] - $p[0][2]) - ($p[2][0] - $p[0][0]) * ($p[1][2] - $p[0][2]);
            if (abs($area) < 1e-6) {
                continue;
            }
            foreach ($weights as [$a, $b, $c]) {
                $x = $p[0][0] * $a + $p[1][0] * $b + $p[2][0] * $c;
                $y = $p[0][1] * $a + $p[1][1] * $b + $p[2][1] * $c;
                $z = $p[0][2] * $a + $p[1][2] * $b + $p[2][2] * $c;
                $g = $ground($x, $z);
                if ($g === null) {
                    continue;
                }
                $d = $y - $g;
                $samples++;
                if ($d > $liftMax) {
                    $liftMax = $d;
                    $maxAt = [$x, $z];
                }
                if ($d < $liftMin) {
                    $liftMin = $d;
                    $minAt = [$x, $z];
                }
                if ($d > $above) {
                    $high++;
                }
                if ($d < $below) {
                    $low++;
                }
            }
            foreach ([[$i0, $i1], [$i1, $i2], [$i2, $i0]] as [$ia, $ib]) {
                $ka = $key($ia);
                $kb = $key($ib);
                if ($ka === $kb) {
                    continue;
                }
                $e = $ka < $kb ? "{$ka}|{$kb}" : "{$kb}|{$ka}";
                if (isset($edges[$e])) {
                    $edges[$e][2]++;
                } else {
                    $edges[$e] = [$ia, $ib, 1];
                }
            }
        }
        $open = 0;
        $edgeMax = -INF;
        $edgeAt = null;
        foreach ($edges as [$ia, $ib, $uses]) {
            if ($uses !== 1) {
                continue;
            }
            $open++;
            foreach ([0.0, 0.5, 1.0] as $s) {
                $x = $v[$ia * 3] + ($v[$ib * 3] - $v[$ia * 3]) * $s;
                $y = $v[$ia * 3 + 1] + ($v[$ib * 3 + 1] - $v[$ia * 3 + 1]) * $s;
                $z = $v[$ia * 3 + 2] + ($v[$ib * 3 + 2] - $v[$ia * 3 + 2]) * $s;
                $g = $ground($x, $z);
                if ($g !== null && $y - $g > $edgeMax) {
                    $edgeMax = $y - $g;
                    $edgeAt = [$x, $z];
                }
            }
        }

        return new self(
            $tris,
            $samples,
            $liftMax,
            $maxAt,
            $liftMin,
            $minAt,
            $samples > 0 ? $high / $samples : 0.0,
            $samples > 0 ? $low / $samples : 0.0,
            $open,
            $edgeMax,
            $edgeAt,
        );
    }

    /**
     * Whether the surface stays within the given bounds: lift ≤ $maxLift,
     * lift ≥ $minLift, open edges ≤ $maxOpenEdge above the ground.
     */
    public function within(float $maxLift, float $minLift, float $maxOpenEdge = INF): bool
    {
        return $this->samples > 0 && $this->liftMax <= $maxLift && $this->liftMin >= $minLift && $this->openEdgeMax <= $maxOpenEdge;
    }
}
