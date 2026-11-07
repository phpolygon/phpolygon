<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Hlod;

use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\MeshSimplifier;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;

/**
 * Builds HLOD cells (shader plan 9.1): the static draws of a region - a district, a
 * block - merged into world space per material and simplified by vertex clustering
 * at $cell metres, registered as "hlod/$id/$material" meshes. A game bakes its
 * regions (at load or in a build step) and, per frame, draws a cell's proxies instead
 * of its own draws once {@see HlodCell::useProxy()} says so. What a region contains
 * and where it switches is the game's: the engine only merges.
 */
final class HlodBuilder
{
    /**
     * @param list<array{0: string, 1: string, 2: Mat4}> $draws mesh id, material id, model matrix
     */
    public static function build(string $id, array $draws, float $cell, float $switchDistance): HlodCell
    {
        $byMaterial = [];
        $min = [INF, INF, INF];
        $max = [-INF, -INF, -INF];
        $source = 0;
        foreach ($draws as [$meshId, $materialId, $model]) {
            $mesh = MeshRegistry::get($meshId);
            if ($mesh === null) {
                continue;
            }
            $placed = $mesh->transformed($model);
            $byMaterial[$materialId][] = $placed;
            $source += $placed->triangleCount();
            for ($i = 0, $n = count($placed->vertices); $i + 2 < $n; $i += 3) {
                for ($a = 0; $a < 3; $a++) {
                    $min[$a] = min($min[$a], $placed->vertices[$i + $a]);
                    $max[$a] = max($max[$a], $placed->vertices[$i + $a]);
                }
            }
        }
        if ($byMaterial === []) {
            return new HlodCell($id, new Vec3(0.0, 0.0, 0.0), 0.0, $switchDistance, [], 0, 0);
        }
        $proxies = [];
        $proxy = 0;
        foreach ($byMaterial as $materialId => $meshes) {
            $merged = MeshSimplifier::cluster(MeshData::merge(...$meshes), $cell);
            if ($merged->triangleCount() === 0) {
                continue;
            }
            $meshId = 'hlod/' . $id . '/' . $materialId;
            MeshRegistry::register($meshId, $merged);
            $proxies[] = [$meshId, (string) $materialId];
            $proxy += $merged->triangleCount();
        }
        $center = new Vec3(($min[0] + $max[0]) / 2, ($min[1] + $max[1]) / 2, ($min[2] + $max[2]) / 2);
        $radius = (new Vec3($max[0] - $min[0], $max[1] - $min[1], $max[2] - $min[2]))->length() / 2;
        return new HlodCell($id, $center, $radius, $switchDistance, $proxies, $source, $proxy);
    }
}
