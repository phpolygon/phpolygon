<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

use PHPolygon\Math\Vec2;
use PHPolygon\Math\Vec3;
use PHPolygon\Runtime\PerfProfiler;

/**
 * Sweep a 2D cross-section along a 3D path to produce a tube-like mesh.
 *
 * Cross-section: closed polygon in the local XY plane (Z = 0). The local
 * X axis maps to the path's parallel-transported normal, local Y maps to
 * the binormal. The cross-section's polygon order should be CCW when
 * viewed from +tangent for outward-facing normals.
 *
 * Path: polyline of Vec3 points (>= 2). Frames use parallel transport,
 * which avoids the violent twisting of pure Frenet frames at inflection
 * points.
 *
 * Use cases: handles, hoses, cables, pipes, rails, vines, tentacles.
 */
class SweepMesh
{
    /**
     * @param Vec2[] $crossSection Closed polygon (do not repeat the first
     *                             point at the end).
     * @param Vec3[] $path         At least 2 points.
     */
    public static function generate(array $crossSection, array $path, bool $capEnds = true): MeshData
    {
        return PerfProfiler::section('mesh.generate.sweep', static fn(): MeshData
            => self::generateImpl($crossSection, $path, $capEnds));
    }

    /**
     * Convenience: sweep a regular n-gon (circle approximation).
     *
     * @param Vec3[] $path
     */
    public static function tube(float $radius, int $sides, array $path, bool $capEnds = true): MeshData
    {
        $sides = max(3, $sides);
        $section = [];
        for ($i = 0; $i < $sides; $i++) {
            $a = 2.0 * M_PI * $i / $sides;
            $section[] = new Vec2(cos($a) * $radius, sin($a) * $radius);
        }
        return self::generate($section, $path, $capEnds);
    }

    /**
     * @param Vec2[] $crossSection
     * @param Vec3[] $path
     */
    private static function generateImpl(array $crossSection, array $path, bool $capEnds): MeshData
    {
        $m = count($crossSection);
        $k = count($path);
        if ($m < 3 || $k < 2) {
            return new MeshData(vertices: [], normals: [], uvs: [], indices: []);
        }

        // Tangents at each path point (central difference for interior).
        $tangents = [];
        for ($i = 0; $i < $k; $i++) {
            $a = $i === 0          ? $path[0]      : $path[$i - 1];
            $b = $i === $k - 1     ? $path[$k - 1] : $path[$i + 1];
            $t = $b->sub($a);
            if ($t->lengthSquared() < 1e-18) {
                // Fallback: forward difference.
                $t = ($i < $k - 1) ? $path[$i + 1]->sub($path[$i]) : $path[$i]->sub($path[$i - 1]);
            }
            $tangents[$i] = $t->normalize();
        }

        // Initial frame (parallel transport).
        $N = self::pickPerpendicular($tangents[0]);
        $B = $tangents[0]->cross($N);

        $frameN = [$N];
        $frameB = [$B];
        for ($i = 1; $i < $k; $i++) {
            $tPrev = $tangents[$i - 1];
            $tCurr = $tangents[$i];
            $axis  = $tPrev->cross($tCurr);
            $axisLen = $axis->length();
            if ($axisLen < 1e-9) {
                $frameN[$i] = $frameN[$i - 1];
                $frameB[$i] = $frameB[$i - 1];
                continue;
            }
            $axis = $axis->div($axisLen);
            $cos  = max(-1.0, min(1.0, $tPrev->dot($tCurr)));
            $angle = acos($cos);
            $frameN[$i] = self::rotateAround($frameN[$i - 1], $axis, $angle);
            $frameB[$i] = $tCurr->cross($frameN[$i]);
        }

        // Pre-compute cross-section vertex normals (in local XY) so side
        // normals are smooth across the section seams.
        $sectionNormals = self::sectionVertexNormals($crossSection);

        /** @var list<float> $vertices */
        $vertices = [];
        /** @var list<float> $normals */
        $normals  = [];
        /** @var list<float> $uvs */
        $uvs      = [];
        /** @var list<int> $indices */
        $indices  = [];

        // Side rings: ($k) along path x ($m + 1) around section (seam dup).
        // $k is guaranteed >= 2 by the early-return at the top of this
        // method, so the V-coord interpolation cannot divide by zero.
        for ($i = 0; $i < $k; $i++) {
            $p  = $path[$i];
            $Nf = $frameN[$i];
            $Bf = $frameB[$i];
            $v  = (float)$i / ($k - 1);

            for ($j = 0; $j <= $m; $j++) {
                $jw = $j % $m;
                $cs = $crossSection[$jw];
                $sn = $sectionNormals[$jw];

                $vertices[] = $p->x + $Nf->x * $cs->x + $Bf->x * $cs->y;
                $vertices[] = $p->y + $Nf->y * $cs->x + $Bf->y * $cs->y;
                $vertices[] = $p->z + $Nf->z * $cs->x + $Bf->z * $cs->y;

                // Section normal (in N/B basis) -> world space.
                $nx = $Nf->x * $sn[0] + $Bf->x * $sn[1];
                $ny = $Nf->y * $sn[0] + $Bf->y * $sn[1];
                $nz = $Nf->z * $sn[0] + $Bf->z * $sn[1];
                $nl = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
                if ($nl > 1e-9) {
                    $nx /= $nl; $ny /= $nl; $nz /= $nl;
                }
                $normals[] = $nx; $normals[] = $ny; $normals[] = $nz;

                $uvs[] = (float)$j / $m;
                $uvs[] = $v;
            }
        }

        $stride = $m + 1;
        for ($i = 0; $i < $k - 1; $i++) {
            for ($j = 0; $j < $m; $j++) {
                $a = $i       * $stride + $j;
                $b = ($i + 1) * $stride + $j;
                $c = ($i + 1) * $stride + $j + 1;
                $d = $i       * $stride + $j + 1;
                $indices[] = $a; $indices[] = $b; $indices[] = $c;
                $indices[] = $a; $indices[] = $c; $indices[] = $d;
            }
        }

        if ($capEnds) {
            self::appendEndCap(
                $vertices, $normals, $uvs, $indices,
                $crossSection, $path[0], $frameN[0], $frameB[0],
                $tangents[0]->mul(-1.0), reverseWinding: false,
            );
            self::appendEndCap(
                $vertices, $normals, $uvs, $indices,
                $crossSection, $path[$k - 1], $frameN[$k - 1], $frameB[$k - 1],
                $tangents[$k - 1], reverseWinding: true,
            );
        }

        return new MeshData($vertices, $normals, $uvs, $indices);
    }

    /**
     * @param Vec2[] $crossSection
     * @return list<array{0: float, 1: float}> Smoothed unit normals in
     *         local XY (length = |crossSection|).
     */
    private static function sectionVertexNormals(array $crossSection): array
    {
        $m = count($crossSection);
        $out = [];
        for ($j = 0; $j < $m; $j++) {
            $prev = $crossSection[($j - 1 + $m) % $m];
            $next = $crossSection[($j + 1) % $m];
            $tx = $next->x - $prev->x;
            $ty = $next->y - $prev->y;
            // Outward normal for a CCW-wound polygon: rotate edge tangent
            // by -90 degrees ((tx,ty) -> (ty,-tx)).
            $nx =  $ty;
            $ny = -$tx;
            $len = sqrt($nx * $nx + $ny * $ny);
            if ($len < 1e-9) {
                $out[$j] = [1.0, 0.0];
                continue;
            }
            $out[$j] = [$nx / $len, $ny / $len];
        }
        // $out is built with explicit 0..m-1 keys via the $j loop above,
        // so it satisfies the list<...> contract.
        return array_values($out);
    }

    /**
     * @param list<float>  $vertices
     * @param list<float>  $normals
     * @param list<float>  $uvs
     * @param list<int>    $indices
     * @param Vec2[]       $crossSection
     */
    private static function appendEndCap(
        array &$vertices,
        array &$normals,
        array &$uvs,
        array &$indices,
        array $crossSection,
        Vec3 $center,
        Vec3 $N,
        Vec3 $B,
        Vec3 $faceNormal,
        bool $reverseWinding,
    ): void {
        $m = count($crossSection);

        $centerIdx = (int)(count($vertices) / 3);
        $vertices[] = $center->x;
        $vertices[] = $center->y;
        $vertices[] = $center->z;
        $normals[]  = $faceNormal->x;
        $normals[]  = $faceNormal->y;
        $normals[]  = $faceNormal->z;
        $uvs[] = 0.5;
        $uvs[] = 0.5;

        $ringBase = (int)(count($vertices) / 3);
        for ($j = 0; $j <= $m; $j++) {
            $jw = $j % $m;
            $cs = $crossSection[$jw];
            $vertices[] = $center->x + $N->x * $cs->x + $B->x * $cs->y;
            $vertices[] = $center->y + $N->y * $cs->x + $B->y * $cs->y;
            $vertices[] = $center->z + $N->z * $cs->x + $B->z * $cs->y;
            $normals[]  = $faceNormal->x;
            $normals[]  = $faceNormal->y;
            $normals[]  = $faceNormal->z;
            $uvs[] = 0.5 + 0.5 * cos(2.0 * M_PI * $j / $m);
            $uvs[] = 0.5 + 0.5 * sin(2.0 * M_PI * $j / $m);
        }

        for ($j = 0; $j < $m; $j++) {
            if ($reverseWinding) {
                $indices[] = $centerIdx;
                $indices[] = $ringBase + $j + 1;
                $indices[] = $ringBase + $j;
            } else {
                $indices[] = $centerIdx;
                $indices[] = $ringBase + $j;
                $indices[] = $ringBase + $j + 1;
            }
        }
    }

    private static function pickPerpendicular(Vec3 $t): Vec3
    {
        $ref = abs($t->x) < 0.9 ? new Vec3(1.0, 0.0, 0.0) : new Vec3(0.0, 1.0, 0.0);
        return $t->cross($ref)->normalize();
    }

    /** Rodrigues rotation. Axis must be unit length. */
    private static function rotateAround(Vec3 $v, Vec3 $axis, float $angle): Vec3
    {
        $c = cos($angle);
        $s = sin($angle);
        $oneMinusC = 1.0 - $c;
        $d  = $axis->dot($v);
        $cr = $axis->cross($v);
        return new Vec3(
            $v->x * $c + $cr->x * $s + $axis->x * $d * $oneMinusC,
            $v->y * $c + $cr->y * $s + $axis->y * $d * $oneMinusC,
            $v->z * $c + $cr->z * $s + $axis->z * $d * $oneMinusC,
        );
    }
}
