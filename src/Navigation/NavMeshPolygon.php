<?php

declare(strict_types=1);

namespace PHPolygon\Navigation;

use PHPolygon\Math\Vec3;

/**
 * A single convex polygon in the navigation mesh.
 *
 * Typically a triangle produced by the NavMesh generator. Stores
 * precomputed centroid, area, neighbor links, and edge costs.
 */
readonly class NavMeshPolygon
{
    public Vec3 $centroid;
    public float $area;

    /**
     * @param int $id Unique polygon index within the NavMesh.
     * @param Vec3[] $vertices Polygon vertices in winding order.
     * @param int[] $neighborIds Adjacent polygon IDs (parallel to edges).
     * @param float[] $edgeCosts Traversal cost to each neighbor (centroid distance).
     */
    public function __construct(
        public int $id,
        public array $vertices,
        public array $neighborIds = [],
        public array $edgeCosts = [],
    ) {
        $this->centroid = self::computeCentroid($vertices);
        $this->area = self::computeArea($vertices);
    }

    /**
     * Test whether a world position falls inside this polygon (XZ projection).
     *
     * Uses the sign-of-cross-product winding test, ignoring Y.
     */
    public function containsPointXZ(Vec3 $point): bool
    {
        $n = count($this->vertices);
        if ($n < 3) {
            return false;
        }

        for ($i = 0; $i < $n; $i++) {
            $a = $this->vertices[$i];
            $b = $this->vertices[($i + 1) % $n];

            // 2D cross product (XZ plane)
            $cross = ($b->x - $a->x) * ($point->z - $a->z)
                   - ($b->z - $a->z) * ($point->x - $a->x);

            if ($cross < 0.0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Find the shared edge between this polygon and another.
     *
     * @return array{Vec3, Vec3}|null The two shared vertices, or null.
     */
    public function getSharedEdge(NavMeshPolygon $other): ?array
    {
        $shared = [];
        $epsilon = 1e-4;

        foreach ($this->vertices as $va) {
            foreach ($other->vertices as $vb) {
                if ($va->equals($vb, $epsilon)) {
                    $shared[] = $va;
                    if (count($shared) === 2) {
                        return $shared;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array{id: int, vertices: list<array{x: float, y: float, z: float}>, neighborIds: int[], edgeCosts: float[]}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'vertices' => array_values(array_map(fn(Vec3 $v) => $v->toArray(), $this->vertices)),
            'neighborIds' => $this->neighborIds,
            'edgeCosts' => $this->edgeCosts,
        ];
    }

    /**
     * @param array{id: int, vertices: list<array{x: float, y: float, z: float}>, neighborIds: int[], edgeCosts: float[]} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            vertices: array_map(fn(array $v) => Vec3::fromArray($v), $data['vertices']),
            neighborIds: $data['neighborIds'],
            edgeCosts: $data['edgeCosts'],
        );
    }

    /**
     * @param Vec3[] $vertices
     */
    private static function computeCentroid(array $vertices): Vec3
    {
        $n = count($vertices);
        if ($n === 0) {
            return Vec3::zero();
        }

        $x = 0.0;
        $y = 0.0;
        $z = 0.0;
        foreach ($vertices as $v) {
            $x += $v->x;
            $y += $v->y;
            $z += $v->z;
        }

        return new Vec3($x / $n, $y / $n, $z / $n);
    }

    /**
     * Compute polygon area via the cross-product shoelace method in 3D.
     *
     * @param Vec3[] $vertices
     */
    private static function computeArea(array $vertices): float
    {
        $n = count($vertices);
        if ($n < 3) {
            return 0.0;
        }

        // Fan triangulation from vertex 0
        $totalArea = 0.0;
        for ($i = 1; $i < $n - 1; $i++) {
            $edge1 = $vertices[$i]->sub($vertices[0]);
            $edge2 = $vertices[$i + 1]->sub($vertices[0]);
            $totalArea += $edge1->cross($edge2)->length() * 0.5;
        }

        return $totalArea;
    }
}
