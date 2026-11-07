<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * Vertex-clustering simplification for far proxies (HLOD, shader plan 9.1): space is
 * cut into cubes of $cell metres, the vertices in one cube collapse to their mean
 * (normals averaged per cube and normal direction, so a box keeps its six faces),
 * and triangles that lost a corner vanish. Fast and topology-blind - meant for what
 * is seen from far away, where a cube's size is below a pixel.
 */
final class MeshSimplifier
{
    public static function cluster(MeshData $mesh, float $cell): MeshData
    {
        if ($cell <= 0.0) {
            throw new \InvalidArgumentException('MeshSimplifier: cell size must be positive');
        }
        $count = intdiv(count($mesh->vertices), 3);
        $keyOf = [];
        $sum = [];
        for ($v = 0; $v < $count; $v++) {
            $x = (float) $mesh->vertices[$v * 3];
            $y = (float) $mesh->vertices[$v * 3 + 1];
            $z = (float) $mesh->vertices[$v * 3 + 2];
            $nx = (float) ($mesh->normals[$v * 3] ?? 0.0);
            $ny = (float) ($mesh->normals[$v * 3 + 1] ?? 0.0);
            $nz = (float) ($mesh->normals[$v * 3 + 2] ?? 0.0);
            // Split a cube by the dominant normal direction: the faces of a corner stay
            // apart instead of averaging into one smeared normal.
            $ax = abs($nx) >= abs($ny) && abs($nx) >= abs($nz) ? ($nx >= 0 ? 0 : 1) : (abs($ny) >= abs($nz) ? ($ny >= 0 ? 2 : 3) : ($nz >= 0 ? 4 : 5));
            $key = (int) floor($x / $cell) . ',' . (int) floor($y / $cell) . ',' . (int) floor($z / $cell) . ',' . $ax;
            $keyOf[$v] = $key;
            $s = $sum[$key] ?? [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0];
            $s[0] += $x;
            $s[1] += $y;
            $s[2] += $z;
            $s[3] += $nx;
            $s[4] += $ny;
            $s[5] += $nz;
            $s[6] += (float) ($mesh->uvs[$v * 2] ?? 0.0);
            $s[7] += (float) ($mesh->uvs[$v * 2 + 1] ?? 0.0);
            $s[8]++;
            $sum[$key] = $s;
        }
        $index = [];
        $vertices = [];
        $normals = [];
        $uvs = [];
        foreach ($sum as $key => $s) {
            $index[$key] = count($index);
            $n = $s[8];
            array_push($vertices, $s[0] / $n, $s[1] / $n, $s[2] / $n);
            $len = sqrt($s[3] * $s[3] + $s[4] * $s[4] + $s[5] * $s[5]);
            $len = $len > 1e-12 ? $len : 1.0;
            array_push($normals, $s[3] / $len, $s[4] / $len, $s[5] / $len);
            array_push($uvs, $s[6] / $n, $s[7] / $n);
        }
        $indices = [];
        $seen = [];
        for ($t = 0, $n = count($mesh->indices); $t + 2 < $n; $t += 3) {
            $a = $index[$keyOf[(int) $mesh->indices[$t]]];
            $b = $index[$keyOf[(int) $mesh->indices[$t + 1]]];
            $c = $index[$keyOf[(int) $mesh->indices[$t + 2]]];
            if ($a === $b || $b === $c || $a === $c) {
                continue;
            }
            // The same collapsed triangle twice (two sides of a thin wall) is drawn once.
            $sorted = [$a, $b, $c];
            sort($sorted);
            $sig = implode(',', $sorted) . ($a < $b ? '+' : '-');
            if (isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = true;
            array_push($indices, $a, $b, $c);
        }
        return new MeshData($vertices, $normals, $uvs, $indices);
    }
}
