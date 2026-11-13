<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * A smooth, cage-free deformation of space that moves named landmark points of a template onto target points
 * (radial basis functions): the template mesh is moved with it, everything between the landmarks follows smoothly.
 * With the thin-plate kernel (phi(r) = r, the biharmonic spline of 3D) the warp is the smoothest one that hits the targets;
 * the cubic kernel (r^3) is smoother still but reaches further.
 *
 * The map is `x -> A (1, x) + sum_j w_j phi(|x - s_j|)`: an affine part (a global scale, rotation or shear for free) and
 * one weight per landmark. {@see fit()} solves the dense system of size N + 4 once (LU with pivoting; a few hundred
 * landmarks take well under a second), after which {@see apply()} costs N kernel evaluations per vertex: warp the
 * BASE template (a few thousand vertices), then subdivide ({@see CatmullClark}) or re-smooth with the stencils
 * ({@see SubdivisionResult::positions()}), instead of warping the subdivided surface.
 *
 * Immutable after fit(); pure, no global state, safe to share between threads.
 */
final class LandmarkWarp
{
    public const KERNEL_THIN_PLATE = 'tps';
    public const KERNEL_CUBIC = 'cubic';

    /**
     * @param array<int, float> $source   landmark sources, xyz each
     * @param list<float> $weights  3 per landmark
     * @param list<float> $affine   4 x 3: constant, x, y, z coefficients for each output axis (row-major by input term)
     */
    private function __construct(
        private readonly array $source,
        private readonly array $weights,
        private readonly array $affine,
        private readonly bool $cubic,
    ) {
    }

    /**
     * Fit the warp that maps $source[i] to $target[i].
     *
     * @param array<int, float> $source flat xyz of the landmarks on the template
     * @param array<int, float> $target flat xyz of where they must go
     * @param string $kernel      KERNEL_THIN_PLATE or KERNEL_CUBIC
     * @param float  $regularization >= 0: relaxes the interpolation (landmarks are then hit approximately, the warp is smoother:
     *                            use it for noisy targets); 0 = exact
     * @throws \InvalidArgumentException on mismatched lists, fewer than four landmarks, or landmarks that do not span 3D space
     */
    public static function fit(array $source, array $target, string $kernel = self::KERNEL_THIN_PLATE, float $regularization = 0.0): self
    {
        $n = intdiv(count($source), 3);
        if (count($source) !== count($target) || count($source) % 3 !== 0) {
            throw new \InvalidArgumentException('LandmarkWarp: source and target must be xyz lists of the same length');
        }
        if ($n < 4) {
            throw new \InvalidArgumentException('LandmarkWarp: at least four landmarks are needed');
        }
        if ($kernel !== self::KERNEL_THIN_PLATE && $kernel !== self::KERNEL_CUBIC) {
            throw new \InvalidArgumentException("LandmarkWarp: unknown kernel '{$kernel}'");
        }
        $cubic = $kernel === self::KERNEL_CUBIC;
        $m = $n + 4;
        // Augmented matrix [A | b0 b1 b2], row-major arrays of rows.
        $a = [];
        for ($i = 0; $i < $n; $i++) {
            $row = array_fill(0, $m + 3, 0.0);
            for ($j = 0; $j < $n; $j++) {
                $dx = $source[$i * 3] - $source[$j * 3];
                $dy = $source[$i * 3 + 1] - $source[$j * 3 + 1];
                $dz = $source[$i * 3 + 2] - $source[$j * 3 + 2];
                $r = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
                $row[$j] = $cubic ? $r * $r * $r : $r;
            }
            $row[$i] += $regularization;
            $row[$n] = 1.0;
            $row[$n + 1] = $source[$i * 3];
            $row[$n + 2] = $source[$i * 3 + 1];
            $row[$n + 3] = $source[$i * 3 + 2];
            $row[$m] = $target[$i * 3];
            $row[$m + 1] = $target[$i * 3 + 1];
            $row[$m + 2] = $target[$i * 3 + 2];
            $a[] = $row;
        }
        for ($k = 0; $k < 4; $k++) {
            $row = array_fill(0, $m + 3, 0.0);
            for ($j = 0; $j < $n; $j++) {
                $row[$j] = $k === 0 ? 1.0 : $source[$j * 3 + $k - 1];
            }
            $a[] = $row;
        }
        $x = self::solve($a, $m, 3);

        $weights = [];
        for ($i = 0; $i < $n; $i++) {
            array_push($weights, $x[$i][0], $x[$i][1], $x[$i][2]);
        }
        $affine = [];
        for ($k = 0; $k < 4; $k++) {
            array_push($affine, $x[$n + $k][0], $x[$n + $k][1], $x[$n + $k][2]);
        }

        return new self($source, $weights, $affine, $cubic);
    }

    /** The number of landmarks. */
    public function landmarkCount(): int
    {
        return intdiv(count($this->source), 3);
    }

    /**
     * The warped position of one point.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public function point(float $x, float $y, float $z): array
    {
        $out = $this->apply([$x, $y, $z]);

        return [$out[0], $out[1], $out[2]];
    }

    /**
     * Warp flat xyz positions (a template's vertices).
     *
     * @param array<float> $positions
     * @return list<float>
     */
    public function apply(array $positions): array
    {
        $s = $this->source;
        $w = $this->weights;
        $af = $this->affine;
        $n = intdiv(count($s), 3);
        $cubic = $this->cubic;
        $out = [];
        for ($v = 0, $c = count($positions); $v < $c; $v += 3) {
            $px = $positions[$v];
            $py = $positions[$v + 1];
            $pz = $positions[$v + 2];
            $ox = $af[0] + $af[3] * $px + $af[6] * $py + $af[9] * $pz;
            $oy = $af[1] + $af[4] * $px + $af[7] * $py + $af[10] * $pz;
            $oz = $af[2] + $af[5] * $px + $af[8] * $py + $af[11] * $pz;
            for ($j = 0, $k = 0; $j < $n; $j++, $k += 3) {
                $dx = $px - $s[$k];
                $dy = $py - $s[$k + 1];
                $dz = $pz - $s[$k + 2];
                $d2 = $dx * $dx + $dy * $dy + $dz * $dz;
                $r = $cubic ? $d2 * sqrt($d2) : sqrt($d2);
                $ox += $w[$k] * $r;
                $oy += $w[$k + 1] * $r;
                $oz += $w[$k + 2] * $r;
            }
            $out[] = $ox;
            $out[] = $oy;
            $out[] = $oz;
        }

        return $out;
    }

    /**
     * Gaussian elimination with partial pivoting on an augmented matrix; returns the solution rows.
     *
     * @param array<int, array<int, float>> $a $m rows of $m + $rhs columns
     * @return array<int, array<int, float>>
     */
    private static function solve(array $a, int $m, int $rhs): array
    {
        for ($c = 0; $c < $m; $c++) {
            $best = $c;
            $max = abs($a[$c][$c]);
            for ($r = $c + 1; $r < $m; $r++) {
                if (abs($a[$r][$c]) > $max) {
                    $max = abs($a[$r][$c]);
                    $best = $r;
                }
            }
            if ($max < 1e-12) {
                throw new \InvalidArgumentException('LandmarkWarp: the landmarks are degenerate (coincident or all in one plane)');
            }
            if ($best !== $c) {
                [$a[$c], $a[$best]] = [$a[$best], $a[$c]];
            }
            $pivot = $a[$c][$c];
            $rowC = $a[$c];
            for ($r = $c + 1; $r < $m; $r++) {
                $f = $a[$r][$c] / $pivot;
                if ($f === 0.0) {
                    continue;
                }
                $row = $a[$r];
                for ($k = $c; $k < $m + $rhs; $k++) {
                    $row[$k] -= $f * $rowC[$k];
                }
                $a[$r] = $row;
            }
        }
        $x = array_fill(0, $m, array_fill(0, $rhs, 0.0));
        for ($r = $m - 1; $r >= 0; $r--) {
            for ($k = 0; $k < $rhs; $k++) {
                $sum = $a[$r][$m + $k];
                for ($c = $r + 1; $c < $m; $c++) {
                    $sum -= $a[$r][$c] * $x[$c][$k];
                }
                $x[$r][$k] = $sum / $a[$r][$r];
            }
        }

        return $x;
    }
}
