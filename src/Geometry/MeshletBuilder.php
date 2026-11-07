<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * Cuts a triangle mesh into meshlets for the mesh-shader path (shader plan 10.1):
 * runs of triangles with at most $maxVertices distinct vertices and $maxTriangles
 * triangles, each with a bounding sphere the task stage culls against the view
 * frustum. The triangles are walked along a Z curve through their centroids, not
 * in index order: a mesh built ring by ring (spheres, lathes, lofts) would
 * otherwise give meshlets that wrap all the way round and spheres as big as the
 * mesh.
 */
final class MeshletBuilder
{
    /** Vertices per meshlet: one per mesh-stage invocation (64 threads). */
    public const MAX_VERTICES = 64;
    /** Triangles per meshlet: NVIDIA's recommended 124 (fits the 8-bit local indices). */
    public const MAX_TRIANGLES = 124;

    public static function build(MeshData $mesh, int $maxVertices = self::MAX_VERTICES, int $maxTriangles = self::MAX_TRIANGLES): MeshletData
    {
        if ($maxVertices < 3 || $maxVertices > 256 || $maxTriangles < 1) {
            throw new \InvalidArgumentException("MeshletBuilder: need 3..256 vertices and at least one triangle per meshlet, got {$maxVertices} / {$maxTriangles}");
        }
        $vertexCount = intdiv(count($mesh->vertices), 3);
        $vertexFloats = [];
        for ($v = 0; $v < $vertexCount; $v++) {
            $p = $v * 3;
            $t = $v * 2;
            array_push(
                $vertexFloats,
                (float) $mesh->vertices[$p], (float) $mesh->vertices[$p + 1], (float) $mesh->vertices[$p + 2],
                (float) ($mesh->normals[$p] ?? 0.0), (float) ($mesh->normals[$p + 1] ?? 0.0), (float) ($mesh->normals[$p + 2] ?? 0.0),
                (float) ($mesh->uvs[$t] ?? 0.0), (float) ($mesh->uvs[$t + 1] ?? 0.0),
            );
        }

        $indices = $mesh->indices;
        $triangleCount = intdiv(count($indices), 3);
        $order = self::spatialOrder($mesh->vertices, $indices, $triangleCount);
        $meshlets = '';
        $stream = [];
        $count = 0;
        $local = [];      // mesh vertex -> local index of the open meshlet
        $triangles = [];  // packed triangles of the open meshlet

        $flush = function () use (&$meshlets, &$stream, &$count, &$local, &$triangles, $mesh): void {
            if ($triangles === []) {
                return;
            }
            $vertexOffset = count($stream);
            $order = array_keys($local);
            foreach ($order as $v) {
                $stream[] = $v;
            }
            $triangleOffset = count($stream);
            foreach ($triangles as $t) {
                $stream[] = $t;
            }
            [$cx, $cy, $cz, $r] = self::sphere($mesh->vertices, $order);
            $meshlets .= pack('V4', $vertexOffset, count($order), $triangleOffset, count($triangles)) . pack('g4', $cx, $cy, $cz, $r);
            $count++;
            $local = [];
            $triangles = [];
        };

        foreach ($order as $t) {
            $tri =[(int) $indices[$t * 3], (int) $indices[$t * 3 + 1], (int) $indices[$t * 3 + 2]];
            $new = 0;
            foreach (array_unique($tri) as $v) {
                if (!isset($local[$v])) {
                    $new++;
                }
            }
            if (count($local) + $new > $maxVertices || count($triangles) >= $maxTriangles) {
                $flush();
            }
            $ids = [];
            foreach ($tri as $v) {
                if (!isset($local[$v])) {
                    $local[$v] = count($local);
                }
                $ids[] = $local[$v];
            }
            $triangles[] = $ids[0] | ($ids[1] << 8) | ($ids[2] << 16);
        }
        $flush();

        return new MeshletData(
            $count,
            $vertexFloats === [] ? '' : pack('g*', ...$vertexFloats),
            $meshlets,
            $stream === [] ? '' : pack('V*', ...$stream),
        );
    }

    /**
     * Triangle order along a Z curve through their centroids (10 bits per axis of
     * the mesh box): neighbours in the order are neighbours in space, so meshlets
     * come out as compact patches instead of rings around the mesh.
     *
     * @param array<int, float|int> $positions
     * @param array<int, int> $indices
     * @return list<int>
     */
    private static function spatialOrder(array $positions, array $indices, int $triangleCount): array
    {
        if ($triangleCount === 0) {
            return [];
        }
        $centroids = [];
        $min = [INF, INF, INF];
        $max = [-INF, -INF, -INF];
        for ($t = 0; $t < $triangleCount; $t++) {
            $c = [0.0, 0.0, 0.0];
            for ($k = 0; $k < 3; $k++) {
                $v = (int) $indices[$t * 3 + $k];
                for ($a = 0; $a < 3; $a++) {
                    $c[$a] += (float) $positions[$v * 3 + $a] / 3.0;
                }
            }
            for ($a = 0; $a < 3; $a++) {
                $min[$a] = min($min[$a], $c[$a]);
                $max[$a] = max($max[$a], $c[$a]);
            }
            $centroids[$t] = $c;
        }
        $keys = [];
        foreach ($centroids as $t => $c) {
            $key = 0;
            $q = [];
            for ($a = 0; $a < 3; $a++) {
                $span = $max[$a] - $min[$a];
                $q[$a] = $span > 0.0 ? min(1023, (int) (($c[$a] - $min[$a]) / $span * 1023.0)) : 0;
            }
            for ($bit = 9; $bit >= 0; $bit--) {
                $key = ($key << 3) | ((($q[0] >> $bit) & 1) << 2) | ((($q[1] >> $bit) & 1) << 1) | (($q[2] >> $bit) & 1);
            }
            $keys[$t] = $key;
        }
        asort($keys, SORT_NUMERIC);
        return array_keys($keys);
    }

    /**
     * Bounding sphere of the given vertices: centre of their box, radius to the
     * farthest one.
     *
     * @param array<int, float|int> $positions flat x, y, z
     * @param list<int> $vertices
     * @return array{float, float, float, float}
     */
    private static function sphere(array $positions, array $vertices): array
    {
        $min = [INF, INF, INF];
        $max = [-INF, -INF, -INF];
        foreach ($vertices as $v) {
            for ($a = 0; $a < 3; $a++) {
                $c = (float) $positions[$v * 3 + $a];
                $min[$a] = min($min[$a], $c);
                $max[$a] = max($max[$a], $c);
            }
        }
        $center = [($min[0] + $max[0]) * 0.5, ($min[1] + $max[1]) * 0.5, ($min[2] + $max[2]) * 0.5];
        $r2 = 0.0;
        foreach ($vertices as $v) {
            $dx = (float) $positions[$v * 3] - $center[0];
            $dy = (float) $positions[$v * 3 + 1] - $center[1];
            $dz = (float) $positions[$v * 3 + 2] - $center[2];
            $r2 = max($r2, $dx * $dx + $dy * $dy + $dz * $dz);
        }
        return [$center[0], $center[1], $center[2], sqrt($r2)];
    }
}
