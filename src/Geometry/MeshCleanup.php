<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * Mesh hygiene helpers: vertex welding, degenerate-triangle removal and a
 * topology report. Pure functions; every result is a new MeshData.
 */
final class MeshCleanup
{
    /**
     * Merge vertices that lie within $tolerance of each other. By default a
     * vertex only merges when its normal is within ~2.5 degrees
     * ($minNormalDot) and its UV within $uvTolerance of the kept vertex, so
     * hard edges and UV seams survive; pass false for either to merge purely by
     * position (the first vertex's attributes win, normals are NOT averaged).
     * The first vertex in index order is the one kept, so the result is
     * deterministic. Triangles that collapse are dropped when
     * $dropDegenerate is true. Tangents are kept when present.
     */
    public static function weld(
        MeshData $mesh,
        float $tolerance = 1e-5,
        bool $matchNormals = true,
        bool $matchUvs = true,
        float $minNormalDot = 0.999,
        float $uvTolerance = 1e-5,
        bool $dropDegenerate = true,
    ): MeshData {
        $map = self::weldMap($mesh, $tolerance, $matchNormals, $matchUvs, $minNormalDot, $uvTolerance);
        $count = $mesh->vertexCount();

        $newIndex = [];
        $vertices = [];
        $normals = [];
        $uvs = [];
        $tangents = $mesh->tangents !== null ? [] : null;
        $next = 0;
        for ($i = 0; $i < $count; $i++) {
            if ($map[$i] !== $i) {
                continue;
            }
            $newIndex[$i] = $next++;
            array_push($vertices, $mesh->vertices[$i * 3], $mesh->vertices[$i * 3 + 1], $mesh->vertices[$i * 3 + 2]);
            if (isset($mesh->normals[$i * 3 + 2])) {
                array_push($normals, $mesh->normals[$i * 3], $mesh->normals[$i * 3 + 1], $mesh->normals[$i * 3 + 2]);
            }
            if (isset($mesh->uvs[$i * 2 + 1])) {
                array_push($uvs, $mesh->uvs[$i * 2], $mesh->uvs[$i * 2 + 1]);
            }
            if ($tangents !== null && $mesh->tangents !== null) {
                array_push($tangents, $mesh->tangents[$i * 4], $mesh->tangents[$i * 4 + 1], $mesh->tangents[$i * 4 + 2], $mesh->tangents[$i * 4 + 3]);
            }
        }
        $indices = [];
        for ($t = 0, $n = count($mesh->indices) - 2; $t < $n; $t += 3) {
            $a = $newIndex[$map[$mesh->indices[$t]]];
            $b = $newIndex[$map[$mesh->indices[$t + 1]]];
            $c = $newIndex[$map[$mesh->indices[$t + 2]]];
            if ($dropDegenerate && ($a === $b || $b === $c || $a === $c)) {
                continue;
            }
            array_push($indices, $a, $b, $c);
        }
        return new MeshData($vertices, $normals, $uvs, $indices, $tangents);
    }

    /**
     * Drop triangles with repeated indices or an area below $areaEpsilon
     * (square metres), and by default the vertices no triangle uses any more.
     */
    public static function removeDegenerate(MeshData $mesh, float $areaEpsilon = 1e-12, bool $compact = true): MeshData
    {
        $v = $mesh->vertices;
        $indices = [];
        for ($t = 0, $n = count($mesh->indices) - 2; $t < $n; $t += 3) {
            $a = $mesh->indices[$t];
            $b = $mesh->indices[$t + 1];
            $c = $mesh->indices[$t + 2];
            if ($a === $b || $b === $c || $a === $c) {
                continue;
            }
            $ux = $v[$b * 3] - $v[$a * 3];
            $uy = $v[$b * 3 + 1] - $v[$a * 3 + 1];
            $uz = $v[$b * 3 + 2] - $v[$a * 3 + 2];
            $wx = $v[$c * 3] - $v[$a * 3];
            $wy = $v[$c * 3 + 1] - $v[$a * 3 + 1];
            $wz = $v[$c * 3 + 2] - $v[$a * 3 + 2];
            $cx = $uy * $wz - $uz * $wy;
            $cy = $uz * $wx - $ux * $wz;
            $cz = $ux * $wy - $uy * $wx;
            if (0.5 * sqrt($cx * $cx + $cy * $cy + $cz * $cz) < $areaEpsilon) {
                continue;
            }
            array_push($indices, $a, $b, $c);
        }
        $out = new MeshData($mesh->vertices, $mesh->normals, $mesh->uvs, $indices, $mesh->tangents);
        return $compact ? self::compact($out) : $out;
    }

    /** Remove vertices that no triangle references, keeping order. */
    public static function compact(MeshData $mesh): MeshData
    {
        $used = [];
        foreach ($mesh->indices as $i) {
            $used[$i] = true;
        }
        $count = $mesh->vertexCount();
        $newIndex = [];
        $vertices = [];
        $normals = [];
        $uvs = [];
        $tangents = $mesh->tangents !== null ? [] : null;
        $next = 0;
        for ($i = 0; $i < $count; $i++) {
            if (!isset($used[$i])) {
                continue;
            }
            $newIndex[$i] = $next++;
            array_push($vertices, $mesh->vertices[$i * 3], $mesh->vertices[$i * 3 + 1], $mesh->vertices[$i * 3 + 2]);
            if (isset($mesh->normals[$i * 3 + 2])) {
                array_push($normals, $mesh->normals[$i * 3], $mesh->normals[$i * 3 + 1], $mesh->normals[$i * 3 + 2]);
            }
            if (isset($mesh->uvs[$i * 2 + 1])) {
                array_push($uvs, $mesh->uvs[$i * 2], $mesh->uvs[$i * 2 + 1]);
            }
            if ($tangents !== null && $mesh->tangents !== null) {
                array_push($tangents, $mesh->tangents[$i * 4], $mesh->tangents[$i * 4 + 1], $mesh->tangents[$i * 4 + 2], $mesh->tangents[$i * 4 + 3]);
            }
        }
        $indices = [];
        foreach ($mesh->indices as $i) {
            $indices[] = $newIndex[$i];
        }
        return new MeshData($vertices, $normals, $uvs, $indices, $tangents);
    }

    /**
     * Edge statistics after treating positions within $tolerance as one
     * vertex: open (used by one triangle), non-manifold (more than two) and
     * flipped (two triangles traversing the edge in the same direction, i.e.
     * inconsistent winding). A closed, consistently wound manifold reports all
     * three as 0.
     *
     * @return array{openEdges: int, nonManifoldEdges: int, flippedEdges: int, degenerateTriangles: int}
     */
    public static function topology(MeshData $mesh, float $tolerance = 1e-5): array
    {
        $map = self::weldMap($mesh, $tolerance, false, false, 0.0, 0.0);
        $undirected = [];
        $directed = [];
        $degenerate = 0;
        for ($t = 0, $n = count($mesh->indices) - 2; $t < $n; $t += 3) {
            $ids = [$map[$mesh->indices[$t]], $map[$mesh->indices[$t + 1]], $map[$mesh->indices[$t + 2]]];
            if ($ids[0] === $ids[1] || $ids[1] === $ids[2] || $ids[0] === $ids[2]) {
                $degenerate++;
                continue;
            }
            for ($e = 0; $e < 3; $e++) {
                $a = $ids[$e];
                $b = $ids[($e + 1) % 3];
                $key = $a < $b ? "$a:$b" : "$b:$a";
                $undirected[$key] = ($undirected[$key] ?? 0) + 1;
                $dk = "$a>$b";
                $directed[$dk] = ($directed[$dk] ?? 0) + 1;
            }
        }
        $open = $nonManifold = $flipped = 0;
        foreach ($undirected as $c) {
            if ($c === 1) {
                $open++;
            } elseif ($c > 2) {
                $nonManifold++;
            }
        }
        foreach ($directed as $c) {
            if ($c > 1) {
                $flipped++;
            }
        }
        return [
            'openEdges' => $open,
            'nonManifoldEdges' => $nonManifold,
            'flippedEdges' => $flipped,
            'degenerateTriangles' => $degenerate,
        ];
    }

    /**
     * For every vertex the index of the representative it welds to (itself
     * when it is the first of its group), using a spatial hash with cell size
     * $tolerance.
     *
     * @return array<int, int>
     */
    private static function weldMap(
        MeshData $mesh,
        float $tolerance,
        bool $matchNormals,
        bool $matchUvs,
        float $minNormalDot,
        float $uvTolerance,
    ): array {
        $count = $mesh->vertexCount();
        $tol = max($tolerance, 1e-12);
        $tol2 = $tol * $tol;
        $v = $mesh->vertices;
        $grid = [];
        $map = [];
        for ($i = 0; $i < $count; $i++) {
            $x = $v[$i * 3];
            $y = $v[$i * 3 + 1];
            $z = $v[$i * 3 + 2];
            $cx = (int) floor($x / $tol);
            $cy = (int) floor($y / $tol);
            $cz = (int) floor($z / $tol);
            $found = -1;
            for ($dx = -1; $dx <= 1 && $found < 0; $dx++) {
                for ($dy = -1; $dy <= 1 && $found < 0; $dy++) {
                    for ($dz = -1; $dz <= 1 && $found < 0; $dz++) {
                        $cell = $grid[($cx + $dx) . ',' . ($cy + $dy) . ',' . ($cz + $dz)] ?? null;
                        if ($cell === null) {
                            continue;
                        }
                        foreach ($cell as $j) {
                            $ex = $v[$j * 3] - $x;
                            $ey = $v[$j * 3 + 1] - $y;
                            $ez = $v[$j * 3 + 2] - $z;
                            if ($ex * $ex + $ey * $ey + $ez * $ez > $tol2) {
                                continue;
                            }
                            if ($matchNormals && isset($mesh->normals[$i * 3 + 2]) && isset($mesh->normals[$j * 3 + 2])) {
                                $d = $mesh->normals[$i * 3] * $mesh->normals[$j * 3]
                                    + $mesh->normals[$i * 3 + 1] * $mesh->normals[$j * 3 + 1]
                                    + $mesh->normals[$i * 3 + 2] * $mesh->normals[$j * 3 + 2];
                                if ($d < $minNormalDot) {
                                    continue;
                                }
                            }
                            if ($matchUvs && isset($mesh->uvs[$i * 2 + 1]) && isset($mesh->uvs[$j * 2 + 1])) {
                                if (abs($mesh->uvs[$i * 2] - $mesh->uvs[$j * 2]) > $uvTolerance
                                    || abs($mesh->uvs[$i * 2 + 1] - $mesh->uvs[$j * 2 + 1]) > $uvTolerance) {
                                    continue;
                                }
                            }
                            $found = $j;
                            break;
                        }
                    }
                }
            }
            if ($found >= 0) {
                $map[$i] = $found;
            } else {
                $map[$i] = $i;
                $grid["$cx,$cy,$cz"][] = $i;
            }
        }
        return $map;
    }
}
