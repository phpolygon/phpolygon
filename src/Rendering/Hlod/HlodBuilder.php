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

    /**
     * {@see build()} with a disk cache: simplifying a district takes seconds on the CPU,
     * so the cell is written to $cacheDir and read back (proxy meshes registered again)
     * the next time the same draws come in. The key covers the cell id, the cell size,
     * every draw's mesh id, material id and model matrix, and $salt - NOT the mesh
     * contents: pass a version of the source assets as $salt when meshes can change
     * under the same id. An unreadable or foreign file is rebuilt.
     *
     * @param list<array{0: string, 1: string, 2: Mat4}> $draws mesh id, material id, model matrix
     */
    public static function buildCached(string $id, array $draws, float $cell, float $switchDistance, string $cacheDir, string $salt = ''): HlodCell
    {
        $path = self::cachePath($id, $draws, $cell, $cacheDir, $salt);
        $cached = self::readCache($path, $id, $switchDistance);
        if ($cached !== null) {
            return $cached;
        }
        $built = self::build($id, $draws, $cell, $switchDistance);
        self::writeCache($path, $built);
        return $built;
    }

    /**
     * The cache file {@see buildCached()} reads and writes for these draws.
     *
     * @param list<array{0: string, 1: string, 2: Mat4}> $draws
     */
    public static function cachePath(string $id, array $draws, float $cell, string $cacheDir, string $salt = ''): string
    {
        $hash = hash_init('xxh128');
        hash_update($hash, self::CACHE_FORMAT . '|' . $id . '|' . sprintf('%.4f', $cell) . '|' . $salt . '|');
        foreach ($draws as [$meshId, $materialId, $model]) {
            hash_update($hash, $meshId . '|' . $materialId . '|' . pack('g*', ...$model->toArray()));
        }
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $id) ?? 'cell';
        return rtrim($cacheDir, '/\\') . DIRECTORY_SEPARATOR . 'hlod_' . $safe . '_' . hash_final($hash) . '.bin';
    }

    private const string CACHE_FORMAT = 'hlod1';

    private static function writeCache(string $path, HlodCell $cell): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $head = ['format' => self::CACHE_FORMAT, 'center' => [$cell->center->x, $cell->center->y, $cell->center->z],
            'radius' => $cell->radius, 'source' => $cell->sourceTriangles, 'proxy' => $cell->proxyTriangles, 'proxies' => []];
        $body = '';
        foreach ($cell->proxies as [$meshId, $materialId]) {
            $mesh = MeshRegistry::get($meshId);
            if ($mesh === null) {
                return;
            }
            $head['proxies'][] = [$materialId, count($mesh->vertices), count($mesh->normals), count($mesh->uvs), count($mesh->indices)];
            $body .= pack('g*', ...$mesh->vertices) . pack('g*', ...$mesh->normals) . pack('g*', ...$mesh->uvs) . pack('V*', ...$mesh->indices);
        }
        $tmp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($head, JSON_THROW_ON_ERROR) . "\n" . $body) !== false) {
            @rename($tmp, $path);
        }
    }

    private static function readCache(string $path, string $id, float $switchDistance): ?HlodCell
    {
        $raw = is_file($path) ? @file_get_contents($path) : false;
        if (!is_string($raw) || ($nl = strpos($raw, "\n")) === false) {
            return null;
        }
        $head = json_decode(substr($raw, 0, $nl), true);
        if (!is_array($head) || ($head['format'] ?? null) !== self::CACHE_FORMAT) {
            return null;
        }
        $list = $head['proxies'] ?? null;
        $center = $head['center'] ?? null;
        $radius = $head['radius'] ?? null;
        $source = $head['source'] ?? null;
        $proxyTris = $head['proxy'] ?? null;
        if (!is_array($list) || !is_array($center) || count($center) !== 3 || !is_numeric($radius)
            || !is_int($source) || !is_int($proxyTris)) {
            return null;
        }
        $at = $nl + 1;
        $length = strlen($raw);
        $take = static function (int $count, string $format) use ($raw, &$at): array {
            if ($count === 0) {
                return [];
            }
            $values = unpack($format . $count, $raw, $at);
            $at += $count * 4;
            return $values === false ? [] : array_values($values);
        };
        $meshes = [];
        foreach ($list as $entry) {
            if (!is_array($entry) || count($entry) !== 5 || !is_string($entry[0])) {
                return null;
            }
            [$materialId, $nv, $nn, $nu, $ni] = $entry;
            if (!is_int($nv) || !is_int($nn) || !is_int($nu) || !is_int($ni) || $length < $at + ($nv + $nn + $nu + $ni) * 4) {
                return null;
            }
            /** @var list<float> $vertices */
            $vertices = $take($nv, 'g');
            /** @var list<float> $normals */
            $normals = $take($nn, 'g');
            /** @var list<float> $uvs */
            $uvs = $take($nu, 'g');
            /** @var list<int> $indices */
            $indices = $take($ni, 'V');
            $meshes[$materialId] = new MeshData($vertices, $normals, $uvs, $indices);
        }
        $proxies = [];
        foreach ($meshes as $materialId => $mesh) {
            $meshId = 'hlod/' . $id . '/' . $materialId;
            MeshRegistry::register($meshId, $mesh);
            $proxies[] = [$meshId, (string) $materialId];
        }
        $c = array_map(static fn(mixed $v): float => is_numeric($v) ? (float) $v : 0.0, array_values($center));
        return new HlodCell($id, new Vec3($c[0], $c[1], $c[2]), (float) $radius, $switchDistance, $proxies, $source, $proxyTris);
    }
}
