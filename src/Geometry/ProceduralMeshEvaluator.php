<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

use PHPolygon\Component\ProceduralMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use RuntimeException;

/**
 * Evaluates a {@see ProceduralMesh} node graph into a {@see MeshData}.
 *
 * Nodes are evaluated lazily from the output node, memoized, and cycle-checked.
 * The starter node set:
 *
 *   Generators (no inputs):
 *     box        params: width, height, depth
 *     cylinder   params: radius, height, segments
 *     sphere     params: radius, stacks, slices
 *     plane      params: width, depth, subdivisions
 *     torus      params: radius, tube, radialSegments, tubularSegments
 *     octahedron params: radius
 *     wedge      params: peakZ
 *   Operators (mesh inputs):
 *     transform input 'mesh'; params: tx,ty,tz  rx,ry,rz (degrees)  sx,sy,sz
 *     mirror    input 'mesh'; params: axis (0=X,1=Y,2=Z) — mirror + merge
 *     combine   merges all of its inputs into one mesh
 *
 * New node types are added by extending {@see build()} — the graph format and
 * everything downstream stay unchanged.
 */
final class ProceduralMeshEvaluator
{
    /**
     * Evaluate the graph and return the output node's mesh.
     */
    public function evaluate(ProceduralMesh $graph): MeshData
    {
        $byId = [];
        foreach ($graph->nodes as $node) {
            $id = $node['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $byId[$id] = $node;
            }
        }

        if ($graph->output === '' || !isset($byId[$graph->output])) {
            throw new RuntimeException("ProceduralMesh has no valid output node '{$graph->output}'");
        }

        $cache = [];
        return $this->evalNode($graph->output, $byId, $cache, []);
    }

    /**
     * Evaluate the graph and register the result in the MeshRegistry under the
     * graph's meshId (bumping its version so renderers re-upload). Returns the
     * mesh id.
     */
    public function publish(ProceduralMesh $graph): string
    {
        if ($graph->meshId === '') {
            throw new RuntimeException('ProceduralMesh has no meshId to publish under');
        }
        MeshRegistry::register($graph->meshId, $this->evaluate($graph));
        return $graph->meshId;
    }

    /**
     * @param array<string, array<string, mixed>> $byId
     * @param array<string, MeshData> $cache
     * @param array<string, true> $visiting
     */
    private function evalNode(string $id, array $byId, array &$cache, array $visiting): MeshData
    {
        if (isset($cache[$id])) {
            return $cache[$id];
        }
        if (isset($visiting[$id])) {
            throw new RuntimeException("Cycle in ProceduralMesh graph at node '{$id}'");
        }
        if (!isset($byId[$id])) {
            throw new RuntimeException("ProceduralMesh references unknown node '{$id}'");
        }

        $visiting[$id] = true;
        $node = $byId[$id];

        $inputs = [];
        $rawInputs = is_array($node['inputs'] ?? null) ? $node['inputs'] : [];
        foreach ($rawInputs as $slot => $sourceId) {
            if (is_string($sourceId)) {
                $inputs[(string) $slot] = $this->evalNode($sourceId, $byId, $cache, $visiting);
            }
        }

        $params = [];
        $rawParams = is_array($node['params'] ?? null) ? $node['params'] : [];
        foreach ($rawParams as $key => $value) {
            $params[(string) $key] = $value;
        }
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';

        $mesh = $this->build($type, $id, $params, $inputs);
        $cache[$id] = $mesh;
        return $mesh;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, MeshData> $inputs
     */
    private function build(string $type, string $id, array $params, array $inputs): MeshData
    {
        return match ($type) {
            'box' => BoxMesh::generate(
                $this->f($params, 'width', 1.0),
                $this->f($params, 'height', 1.0),
                $this->f($params, 'depth', 1.0),
            ),
            'cylinder' => CylinderMesh::generate(
                $this->f($params, 'radius', 0.5),
                $this->f($params, 'height', 1.0),
                (int) $this->f($params, 'segments', 16.0),
            ),
            'sphere' => SphereMesh::generate(
                $this->f($params, 'radius', 0.5),
                (int) $this->f($params, 'stacks', 12.0),
                (int) $this->f($params, 'slices', 16.0),
            ),
            'plane' => PlaneMesh::generate(
                $this->f($params, 'width', 1.0),
                $this->f($params, 'depth', 1.0),
                (int) $this->f($params, 'subdivisions', 1.0),
            ),
            'torus' => TorusMesh::generate(
                $this->f($params, 'radius', 1.0),
                $this->f($params, 'tube', 0.4),
                (int) $this->f($params, 'radialSegments', 12.0),
                (int) $this->f($params, 'tubularSegments', 24.0),
            ),
            'octahedron' => OctahedronMesh::generate(
                $this->f($params, 'radius', 1.0),
            ),
            'wedge' => WedgeMesh::generate(
                $this->f($params, 'peakZ', 0.0),
            ),
            'transform' => $this->applyTransform($this->requireInput($inputs, 'mesh', $id), $params),
            'mirror' => $this->applyMirror($this->requireInput($inputs, 'mesh', $id), $params),
            'combine' => MeshData::merge(...array_values($inputs)),
            default => throw new RuntimeException("ProceduralMesh has unknown node type '{$type}' at '{$id}'"),
        };
    }

    /**
     * Mirror the mesh across an axis-aligned plane through the origin and merge
     * the mirrored copy with the original (a symmetric "mirror modifier").
     * `axis`: 0 = X, 1 = Y, 2 = Z.
     *
     * @param array<string, mixed> $params
     */
    private function applyMirror(MeshData $mesh, array $params): MeshData
    {
        $axis = (int) $this->f($params, 'axis', 0.0);
        $axis = max(0, min(2, $axis));

        $vertices = $mesh->vertices;
        $mirroredVertices = [];
        for ($i = 0, $n = count($vertices); $i < $n; $i += 3) {
            $mirroredVertices[] = $axis === 0 ? -$vertices[$i] : $vertices[$i];
            $mirroredVertices[] = $axis === 1 ? -$vertices[$i + 1] : $vertices[$i + 1];
            $mirroredVertices[] = $axis === 2 ? -$vertices[$i + 2] : $vertices[$i + 2];
        }

        $normals = $mesh->normals;
        $mirroredNormals = [];
        for ($i = 0, $n = count($normals); $i < $n; $i += 3) {
            $mirroredNormals[] = $axis === 0 ? -$normals[$i] : $normals[$i];
            $mirroredNormals[] = $axis === 1 ? -$normals[$i + 1] : $normals[$i + 1];
            $mirroredNormals[] = $axis === 2 ? -$normals[$i + 2] : $normals[$i + 2];
        }

        // Reflection flips triangle orientation; swap two indices per triangle
        // so the mirrored copy faces outward again.
        $indices = $mesh->indices;
        $mirroredIndices = [];
        for ($i = 0, $n = count($indices); $i < $n; $i += 3) {
            $mirroredIndices[] = $indices[$i];
            $mirroredIndices[] = $indices[$i + 2];
            $mirroredIndices[] = $indices[$i + 1];
        }

        $mirror = new MeshData($mirroredVertices, $mirroredNormals, $mesh->uvs, $mirroredIndices);

        return MeshData::merge($mesh, $mirror);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function applyTransform(MeshData $mesh, array $params): MeshData
    {
        $matrix = Mat4::trs(
            new Vec3($this->f($params, 'tx', 0.0), $this->f($params, 'ty', 0.0), $this->f($params, 'tz', 0.0)),
            Quaternion::fromEuler(
                deg2rad($this->f($params, 'rx', 0.0)),
                deg2rad($this->f($params, 'ry', 0.0)),
                deg2rad($this->f($params, 'rz', 0.0)),
            ),
            new Vec3($this->f($params, 'sx', 1.0), $this->f($params, 'sy', 1.0), $this->f($params, 'sz', 1.0)),
        );

        $vertices = $mesh->vertices;
        $transformedVertices = [];
        for ($i = 0, $n = count($vertices); $i < $n; $i += 3) {
            $p = $matrix->transformPoint(new Vec3($vertices[$i], $vertices[$i + 1], $vertices[$i + 2]));
            $transformedVertices[] = $p->x;
            $transformedVertices[] = $p->y;
            $transformedVertices[] = $p->z;
        }

        $normals = $mesh->normals;
        $transformedNormals = [];
        for ($i = 0, $n = count($normals); $i < $n; $i += 3) {
            $d = $matrix->transformDirection(new Vec3($normals[$i], $normals[$i + 1], $normals[$i + 2]))->normalize();
            $transformedNormals[] = $d->x;
            $transformedNormals[] = $d->y;
            $transformedNormals[] = $d->z;
        }

        return new MeshData($transformedVertices, $transformedNormals, $mesh->uvs, $mesh->indices);
    }

    /**
     * @param array<string, MeshData> $inputs
     */
    private function requireInput(array $inputs, string $slot, string $nodeId): MeshData
    {
        if (!isset($inputs[$slot])) {
            throw new RuntimeException("ProceduralMesh node '{$nodeId}' is missing required input '{$slot}'");
        }
        return $inputs[$slot];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function f(array $params, string $key, float $default): float
    {
        $value = $params[$key] ?? null;
        return is_numeric($value) ? (float) $value : $default;
    }
}
