<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

use PHPolygon\Component\ProceduralMesh;

/**
 * Mesh assets on disk: the editable `<id>.mesh.json` source and the compiled
 * `<id>.mesh.bin` a build ships in its place.
 *
 * Source layouts ({@see decodeJson()}):
 *   - `{ "name": ..., "raw": { vertices, normals, uvs, indices } }` - the
 *     editor's baked / vertex-edited shape;
 *   - the same arrays at the top level - {@see \PHPolygon\Build\Mesh\MeshSerializer};
 *   - `{ "name": ..., "nodes": [...], "output": ... }` - a procedural node graph,
 *     evaluated with {@see ProceduralMeshEvaluator}.
 *
 * The compiled form is {@see MeshCacheIO} version 2 (float32 streams, uint16
 * indices where they fit, zlib by default), with crc32 of the JSON source as
 * its version hash. {@see loadFile()} prefers the `.mesh.bin` beside a JSON
 * path when it is current: the source is gone (a shipped build) or its crc32
 * matches. A stale binary is ignored, so editing the JSON in the editor always
 * wins during development. Inside a built game (running from a PHAR) the
 * binary wins without the check: a JSON there can only be a leftover an older
 * build extracted, see {@see $verifySources}.
 */
final class MeshAssetLoader
{
    public const SOURCE_SUFFIX = '.mesh.json';
    public const COMPILED_SUFFIX = '.mesh.bin';

    /**
     * Whether a `.mesh.bin` must match the crc32 of a `.mesh.json` beside it.
     * null = yes, unless the code runs from a PHAR (a shipped build).
     */
    public static ?bool $verifySources = null;

    private function __construct() {}

    /**
     * Lazily register every mesh asset in $dir into the {@see MeshRegistry},
     * keyed by its bare name (`foo.mesh.json` / `foo.mesh.bin` -> `foo`). Only
     * factories are stored; a file is read the first time its mesh is drawn or
     * prefetched. A file that fails to load becomes an empty mesh and is
     * reported to $onError instead of throwing mid-frame.
     *
     * @param ?callable(string, string): void $onError receives the id and the error message
     * @return int number of ids registered (0 when $dir does not exist)
     */
    public static function registerAll(string $dir, ?callable $onError = null): int
    {
        $registered = 0;
        foreach (self::discover($dir) as $id => $path) {
            MeshRegistry::registerLazy($id, static function () use ($path, $id, $onError): MeshData {
                try {
                    return self::loadFile($path);
                } catch (\RuntimeException $e) {
                    if ($onError !== null) {
                        $onError($id, $e->getMessage());
                    }
                    return new MeshData(vertices: [], normals: [], uvs: [], indices: []);
                }
            });
            $registered++;
        }
        return $registered;
    }

    /**
     * Every mesh asset in $dir (not recursive): id => path. A compiled-only
     * asset maps to its `.mesh.bin`; when both exist the JSON path is returned,
     * and {@see loadFile()} decides which one to read.
     *
     * @return array<string, string>
     */
    public static function discover(string $dir): array
    {
        $dir = rtrim($dir, '/\\');
        if (!is_dir($dir)) {
            return [];
        }
        $found = [];
        foreach ([self::COMPILED_SUFFIX, self::SOURCE_SUFFIX] as $suffix) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*' . $suffix) ?: [] as $path) {
                $id = basename($path, $suffix);
                if ($id !== '') {
                    $found[$id] = $path;
                }
            }
        }
        ksort($found);
        return $found;
    }

    /**
     * Load a mesh asset. A `.mesh.json` path is served from its compiled sibling
     * when that is current ({@see compiledFor()}); a `.mesh.bin` path is read as is.
     *
     * @throws \RuntimeException when the file is missing or malformed
     */
    public static function loadFile(string $path): MeshData
    {
        if (str_ends_with($path, self::COMPILED_SUFFIX)) {
            return self::loadCompiled($path);
        }

        $compiled = self::compiledFor($path);
        if ($compiled !== null) {
            return $compiled;
        }

        $json = @file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("Mesh asset not found: {$path}");
        }
        return self::decodeJson($json, $path);
    }

    /**
     * The mesh from the `.mesh.bin` beside $jsonPath, or null when there is none
     * or it was compiled from different source bytes.
     */
    public static function compiledFor(string $jsonPath): ?MeshData
    {
        $binPath = self::compiledPath($jsonPath);
        $bin = is_file($binPath) ? @file_get_contents($binPath) : false;
        if ($bin === false || $bin === '') {
            return null;
        }
        if (self::shouldVerify() && is_file($jsonPath)) {
            $source = @file_get_contents($jsonPath);
            if ($source === false || MeshCacheIO::readVersionHash($bin) !== MeshCacheIO::versionHash($source)) {
                return null;
            }
        }
        return MeshCacheIO::decode($bin);
    }

    /** `<dir>/<id>.mesh.json` -> `<dir>/<id>.mesh.bin`. */
    public static function compiledPath(string $jsonPath): string
    {
        return str_ends_with($jsonPath, self::SOURCE_SUFFIX)
            ? substr($jsonPath, 0, -strlen(self::SOURCE_SUFFIX)) . self::COMPILED_SUFFIX
            : $jsonPath . '.bin';
    }

    /**
     * Decode a mesh JSON in any of the three source layouts.
     *
     * @throws \RuntimeException on invalid JSON
     */
    public static function decodeJson(string $json, string $label = 'mesh'): MeshData
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid mesh JSON: {$label}");
        }

        if (isset($data['nodes']) && is_array($data['nodes'])) {
            $graph = new ProceduralMesh();
            /** @var list<array<string, mixed>> $nodes */
            $nodes = array_values(array_filter($data['nodes'], 'is_array'));
            $graph->nodes = $nodes;
            $graph->output = is_string($data['output'] ?? null) ? $data['output'] : '';
            return (new ProceduralMeshEvaluator())->evaluate($graph);
        }

        $src = (isset($data['raw']) && is_array($data['raw'])) ? $data['raw'] : $data;
        $tangents = $src['tangents'] ?? null;

        return new MeshData(
            vertices: self::floats($src['vertices'] ?? []),
            normals: self::floats($src['normals'] ?? []),
            uvs: self::floats($src['uvs'] ?? []),
            indices: self::ints($src['indices'] ?? []),
            tangents: is_array($tangents) && $tangents !== [] ? self::floats($tangents) : null,
        );
    }

    /**
     * Compile one `.mesh.json` into its `.mesh.bin` (written beside it unless
     * $binPath says otherwise). Returns the compiled bytes' length.
     *
     * @param int $zlibLevel 0 = uncompressed payload, 1-9 = zlib level
     * @throws \RuntimeException when the source cannot be read or decoded
     */
    public static function compileFile(string $jsonPath, ?string $binPath = null, int $zlibLevel = 6): int
    {
        $json = @file_get_contents($jsonPath);
        if ($json === false) {
            throw new \RuntimeException("Mesh asset not found: {$jsonPath}");
        }
        $bin = MeshCacheIO::encodeCompact(self::decodeJson($json, $jsonPath), $json, $zlibLevel);
        $target = $binPath ?? self::compiledPath($jsonPath);
        if (@file_put_contents($target, $bin) === false) {
            throw new \RuntimeException("Cannot write compiled mesh: {$target}");
        }
        return strlen($bin);
    }

    /**
     * Compile every `*.mesh.json` below $dir (recursive). With $removeSources the
     * JSON files are deleted afterwards - meant for a build's staging copy, never
     * for the project tree.
     *
     * @return array{files: int, sourceBytes: int, compiledBytes: int}
     */
    public static function compileDirectory(string $dir, int $zlibLevel = 6, bool $removeSources = false): array
    {
        $stats = ['files' => 0, 'sourceBytes' => 0, 'compiledBytes' => 0];
        if (!is_dir($dir)) {
            return $stats;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        $sources = [];
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), self::SOURCE_SUFFIX)) {
                $sources[] = $file->getPathname();
            }
        }
        sort($sources);
        foreach ($sources as $path) {
            $stats['sourceBytes'] += (int) filesize($path);
            $stats['compiledBytes'] += self::compileFile($path, null, $zlibLevel);
            $stats['files']++;
            if ($removeSources) {
                @unlink($path);
            }
        }
        return $stats;
    }

    private static function shouldVerify(): bool
    {
        return self::$verifySources ?? (!class_exists(\Phar::class, false) || \Phar::running(false) === '');
    }

    /** @throws \RuntimeException */
    private static function loadCompiled(string $path): MeshData
    {
        $bin = @file_get_contents($path);
        if ($bin === false) {
            throw new \RuntimeException("Mesh asset not found: {$path}");
        }
        return MeshCacheIO::decode($bin) ?? throw new \RuntimeException("Invalid compiled mesh: {$path}");
    }

    /** @return float[] */
    private static function floats(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        return array_map(static fn($v): float => is_numeric($v) ? (float) $v : 0.0, array_values($values));
    }

    /** @return int[] */
    private static function ints(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        return array_map(static fn($v): int => is_numeric($v) ? (int) $v : 0, array_values($values));
    }
}
