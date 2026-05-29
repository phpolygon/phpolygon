<?php

declare(strict_types=1);

namespace PHPolygon\Prototype;

use PHPolygon\Geometry\MeshCacheIO;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Scene\Scene;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use ReflectionObject;
use ReflectionProperty;
use RuntimeException;

/**
 * Writes the static artefact bundle the file-based WebGL/JSX prototyping
 * playground reads. Nothing here touches a GPU or boots the engine - it is
 * pure data export, runnable headless / in CI.
 *
 * The browser never talks to PHP at runtime: `prototype:export` produces this
 * bundle once (and again whenever procedural geometry changes), Vite serves it
 * statically, and the playground reads it. Round-trip back to PHP is a
 * separate CLI step (`scene:transpile`).
 *
 * Bundle layout (relative to the output dir):
 * ```
 * schema.json                 component vocabulary (ComponentSchemaGenerator)
 * materials.json              MaterialRegistry dump (albedo as {r,g,b,a} etc.)
 * meshes/<slug>.bin           MeshData in the MeshCacheIO binary format
 * scenes/<name>.scene.json    existing scenes, for import into the playground
 * manifest.json               top-level index tying it all together
 * ```
 *
 * The mesh binary is the engine's own MeshCacheIO format (little-endian
 * float32 vertices/normals/uvs + uint32 indices behind a 28-byte header), so
 * the browser decoder is a thin DataView slice and the geometry shown in WebGL
 * is byte-for-byte the geometry the engine generated. (Tangents are omitted -
 * the approximate preview path does not need them.)
 */
final class PrototypeExporter
{
    public const VERSION = 1;

    public function __construct(
        private readonly ComponentSchemaGenerator $schemaGenerator = new ComponentSchemaGenerator(),
        private readonly SceneTranspiler $transpiler = new SceneTranspiler(),
    ) {}

    /**
     * @param list<class-string>      $componentClasses Serializable components for the schema.
     * @param array<string, MeshData> $meshes           Mesh id => geometry.
     * @param array<string, Material> $materials        Material id => material.
     * @param list<Scene>             $scenes           Optional scenes to export for import.
     * @return array<string, mixed>                     The written manifest.
     */
    public function export(
        string $outDir,
        array $componentClasses,
        array $meshes,
        array $materials,
        array $scenes = [],
    ): array {
        $outDir = rtrim($outDir, '/\\');
        $this->ensureDir($outDir);
        $this->ensureDir($outDir . '/meshes');

        // --- Schema ---
        $this->writeJson($outDir . '/schema.json', $this->schemaGenerator->generate($componentClasses));

        // --- Meshes ---
        $meshManifest = [];
        foreach ($meshes as $id => $mesh) {
            $slug = $this->meshSlug($id);
            $relative = "meshes/{$slug}.bin";
            $binary = MeshCacheIO::encode($mesh, $id);
            $this->writeFile($outDir . '/' . $relative, $binary);

            $meshManifest[$id] = [
                'file' => $relative,
                'vertexCount' => $mesh->vertexCount(),
                'triangleCount' => $mesh->triangleCount(),
                'bytes' => strlen($binary),
            ];
        }
        ksort($meshManifest);

        // --- Materials ---
        $materialData = [];
        foreach ($materials as $id => $material) {
            $materialData[$id] = self::materialToArray($material);
        }
        ksort($materialData);
        $this->writeJson($outDir . '/materials.json', [
            '_version' => self::VERSION,
            'materials' => $materialData,
        ]);

        // --- Scenes ---
        $sceneManifest = [];
        if ($scenes !== []) {
            $this->ensureDir($outDir . '/scenes');
            foreach ($scenes as $scene) {
                $name = $this->sanitize($scene->getName());
                $relative = "scenes/{$name}.scene.json";
                $this->writeJson($outDir . '/' . $relative, $this->transpiler->toArray($scene));
                $sceneManifest[$scene->getName()] = $relative;
            }
            ksort($sceneManifest);
        }

        // --- Manifest ---
        $manifest = [
            '_version' => self::VERSION,
            'schema' => 'schema.json',
            'materials' => 'materials.json',
            'meshFormat' => 'meshcache/v1', // MeshCacheIO header + LE float32/uint32 payload
            'meshes' => $meshManifest,
            'materialIds' => array_keys($materialData),
            'scenes' => $sceneManifest,
        ];
        $this->writeJson($outDir . '/manifest.json', $manifest);

        return $manifest;
    }

    /**
     * Convert a Material to a JSON-friendly array. Reflects public readonly
     * properties so new Material fields are exported automatically; Color
     * values become {r,g,b,a}.
     *
     * @return array<string, mixed>
     */
    public static function materialToArray(Material $material): array
    {
        $out = [];
        foreach ((new ReflectionObject($material))->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($material);
            $out[$prop->getName()] = $value instanceof Color ? $value->toArray() : $value;
        }
        return $out;
    }

    /**
     * Filesystem-safe, collision-free filename stem for a mesh id. The sha1
     * suffix guarantees uniqueness even when two ids sanitise alike (e.g.
     * "wall/a" and "wall:a"); the manifest maps the real id back to the file,
     * so the stem only has to be unique, not reversible.
     */
    private function meshSlug(string $id): string
    {
        return $this->sanitize($id) . '-' . substr(sha1($id), 0, 8);
    }

    private function sanitize(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '_', $value) ?? '_';
        $clean = trim($clean, '_');
        if ($clean === '') {
            $clean = 'unnamed';
        }
        return substr($clean, 0, 64);
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException("Failed to encode JSON for {$path}: " . json_last_error_msg());
        }
        $this->writeFile($path, $json);
    }

    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Failed to write {$path}");
        }
    }

    private function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create directory {$dir}");
        }
    }
}
