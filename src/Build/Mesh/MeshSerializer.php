<?php

declare(strict_types=1);

namespace PHPolygon\Build\Mesh;

use PHPolygon\Geometry\MeshData;

/**
 * MeshData ↔ JSON round-trip for the SVG mesh build pipeline.
 *
 * Format:
 *   {
 *     "version":       1,
 *     "source":        "icons/cup.svg",         // optional
 *     "depth":         0.20,                    // optional
 *     "vertexCount":   42,
 *     "triangleCount": 64,
 *     "vertices":      [x, y, z, ...],
 *     "normals":       [nx, ny, nz, ...],
 *     "uvs":           [u, v, ...],
 *     "indices":       [i, j, k, ...]
 *   }
 *
 * The format is committable, diffable, and readable by the editor (Vue /
 * NativePHP). It's the canonical intermediate between the SVG parser /
 * extruder pair and the PhpMeshGenerator that produces the runtime PHP
 * class.
 *
 * Round-trip is loss-less for the engine's MeshData (no tangents in this
 * format yet - they are computed lazily via `MeshData::withComputedTangents()`).
 */
final class MeshSerializer
{
    public const FORMAT_VERSION = 1;

    /**
     * @param array<string, mixed> $metadata Optional fields like
     *                                       'source', 'depth', 'tool'.
     */
    public function toJson(MeshData $mesh, array $metadata = []): string
    {
        $payload = array_merge(
            [
                'version'       => self::FORMAT_VERSION,
            ],
            $metadata,
            [
                'vertexCount'   => $mesh->vertexCount(),
                'triangleCount' => $mesh->triangleCount(),
                'vertices'      => $mesh->vertices,
                'normals'       => $mesh->normals,
                'uvs'           => $mesh->uvs,
                'indices'       => $mesh->indices,
            ],
        );

        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );
        if ($json === false) {
            throw new \RuntimeException('Failed to encode MeshData as JSON');
        }
        return $json;
    }

    public function fromJson(string $json): MeshData
    {
        /** @var array<string, mixed>|null $data */
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid mesh JSON');
        }

        $rawVersion = $data['version'] ?? 0;
        $version = is_numeric($rawVersion) ? (int)$rawVersion : 0;
        if ($version !== self::FORMAT_VERSION) {
            throw new \RuntimeException(
                "Unsupported mesh JSON version: {$version} (expected " . self::FORMAT_VERSION . ')',
            );
        }

        return new MeshData(
            vertices: $this->floats($data['vertices'] ?? []),
            normals:  $this->floats($data['normals']  ?? []),
            uvs:      $this->floats($data['uvs']      ?? []),
            indices:  $this->ints($data['indices']    ?? []),
        );
    }

    /**
     * @param mixed $values
     * @return float[]
     */
    private function floats(mixed $values): array
    {
        if (!is_array($values)) return [];
        return array_map(
            static fn($v): float => is_numeric($v) ? (float)$v : 0.0,
            $values,
        );
    }

    /**
     * @param mixed $values
     * @return int[]
     */
    private function ints(mixed $values): array
    {
        if (!is_array($values)) return [];
        return array_map(
            static fn($v): int => is_numeric($v) ? (int)$v : 0,
            $values,
        );
    }
}
