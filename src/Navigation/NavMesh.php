<?php

declare(strict_types=1);

namespace PHPolygon\Navigation;

use PHPolygon\Math\Vec3;

/**
 * A navigation mesh - a collection of convex polygons representing
 * the walkable surface of a scene.
 *
 * Provides spatial lookup for finding polygons at world positions
 * and neighbor traversal for pathfinding.
 */
class NavMesh
{
    /** @var array<int, NavMeshPolygon> Polygons indexed by ID. */
    private array $polygons = [];

    /** @var array<int, NavMeshEdge[]> Portal edges indexed by polygon ID. */
    private array $edges = [];

    /** @var array<string, int[]> Spatial grid: cell key -> polygon IDs. */
    private array $grid = [];

    private float $gridCellSize;

    public function __construct(float $gridCellSize = 4.0)
    {
        $this->gridCellSize = $gridCellSize;
    }

    public function addPolygon(NavMeshPolygon $polygon): void
    {
        $this->polygons[$polygon->id] = $polygon;
        $this->insertIntoGrid($polygon);
    }

    public function addEdge(NavMeshEdge $edge): void
    {
        $this->edges[$edge->polygonA][] = $edge;
        $this->edges[$edge->polygonB][] = $edge;
    }

    public function getPolygon(int $id): ?NavMeshPolygon
    {
        return $this->polygons[$id] ?? null;
    }

    /**
     * @return NavMeshPolygon[]
     */
    public function getPolygons(): array
    {
        return $this->polygons;
    }

    public function polygonCount(): int
    {
        return count($this->polygons);
    }

    /**
     * Get all portal edges for a polygon.
     *
     * @return NavMeshEdge[]
     */
    public function getEdgesForPolygon(int $polygonId): array
    {
        return $this->edges[$polygonId] ?? [];
    }

    /**
     * Find the portal edge between two specific polygons.
     */
    public function getEdgeBetween(int $polyA, int $polyB): ?NavMeshEdge
    {
        foreach ($this->edges[$polyA] ?? [] as $edge) {
            if ($edge->polygonA === $polyB || $edge->polygonB === $polyB) {
                return $edge;
            }
        }
        return null;
    }

    /**
     * Find the polygon containing a world position (XZ projection).
     */
    public function findPolygonAt(Vec3 $position): ?NavMeshPolygon
    {
        $candidates = $this->queryGrid($position);

        foreach ($candidates as $polyId) {
            $polygon = $this->polygons[$polyId];
            if ($polygon->containsPointXZ($position)) {
                return $polygon;
            }
        }

        return null;
    }

    /**
     * Find the nearest polygon to a world position within maxDistance.
     */
    public function findNearestPolygon(Vec3 $position, float $maxDistance = 10.0): ?NavMeshPolygon
    {
        // First try exact containment
        $exact = $this->findPolygonAt($position);
        if ($exact !== null) {
            return $exact;
        }

        $bestDist = $maxDistance * $maxDistance;
        $best = null;

        foreach ($this->polygons as $polygon) {
            $dist = $position->distanceSquaredTo($polygon->centroid);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $polygon;
            }
        }

        return $best;
    }

    /**
     * Find all polygons whose centroids lie within a radius.
     *
     * @return NavMeshPolygon[]
     */
    public function findPolygonsInRadius(Vec3 $center, float $radius): array
    {
        $radiusSq = $radius * $radius;
        $result = [];

        foreach ($this->polygons as $polygon) {
            if ($center->distanceSquaredTo($polygon->centroid) <= $radiusSq) {
                $result[] = $polygon;
            }
        }

        return $result;
    }

    /**
     * Project a world position onto the nearest point on the NavMesh surface.
     */
    public function projectPoint(Vec3 $position): ?Vec3
    {
        $polygon = $this->findNearestPolygon($position);
        if ($polygon === null) {
            return null;
        }

        // Project onto the polygon plane (average Y of vertices)
        $avgY = 0.0;
        foreach ($polygon->vertices as $v) {
            $avgY += $v->y;
        }
        $avgY /= count($polygon->vertices);

        return new Vec3($position->x, $avgY, $position->z);
    }

    /**
     * @return array{gridCellSize: float, polygons: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        $allEdges = [];
        $seen = [];
        foreach ($this->edges as $polyEdges) {
            foreach ($polyEdges as $edge) {
                $key = min($edge->polygonA, $edge->polygonB) . '_' . max($edge->polygonA, $edge->polygonB);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $allEdges[] = $edge->toArray();
                }
            }
        }

        return [
            'gridCellSize' => $this->gridCellSize,
            'polygons' => array_map(fn(NavMeshPolygon $p) => $p->toArray(), array_values($this->polygons)),
            'edges' => $allEdges,
        ];
    }

    /**
     * @param array{gridCellSize: float, polygons: list<array<string, mixed>>, edges: list<array<string, mixed>>} $data
     */
    public static function fromArray(array $data): self
    {
        $mesh = new self($data['gridCellSize']);

        foreach ($data['polygons'] as $polyData) {
            /** @var array{id: int, vertices: list<array{x: float, y: float, z: float}>, neighborIds: int[], edgeCosts: float[]} $polyData */
            $mesh->addPolygon(NavMeshPolygon::fromArray($polyData));
        }

        foreach ($data['edges'] as $edgeData) {
            /** @var array{left: array{x: float, y: float, z: float}, right: array{x: float, y: float, z: float}, polygonA: int, polygonB: int} $edgeData */
            $mesh->addEdge(NavMeshEdge::fromArray($edgeData));
        }

        return $mesh;
    }

    /**
     * Build the NavMesh from a list of polygons, auto-detecting neighbors and edges.
     *
     * @param NavMeshPolygon[] $polygons Polygons without neighbor info.
     */
    public static function buildFromPolygons(array $polygons, float $gridCellSize = 4.0): self
    {
        $mesh = new self($gridCellSize);

        // Index all polygons
        /** @var NavMeshPolygon[] $indexed */
        $indexed = [];
        foreach ($polygons as $poly) {
            $indexed[$poly->id] = $poly;
            $mesh->addPolygon($poly);
        }

        // Detect adjacency by shared edges
        $ids = array_keys($indexed);
        $count = count($ids);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $indexed[$ids[$i]];
                $b = $indexed[$ids[$j]];
                $sharedEdge = $a->getSharedEdge($b);

                if ($sharedEdge !== null) {
                    $cost = sqrt($a->centroid->distanceSquaredTo($b->centroid));

                    // Rebuild polygons with neighbor info
                    $indexed[$a->id] = new NavMeshPolygon(
                        $a->id,
                        $a->vertices,
                        [...$a->neighborIds, $b->id],
                        [...$a->edgeCosts, $cost],
                    );

                    $indexed[$b->id] = new NavMeshPolygon(
                        $b->id,
                        $b->vertices,
                        [...$b->neighborIds, $a->id],
                        [...$b->edgeCosts, $cost],
                    );

                    $mesh->addEdge(new NavMeshEdge(
                        left: $sharedEdge[0],
                        right: $sharedEdge[1],
                        polygonA: $a->id,
                        polygonB: $b->id,
                    ));
                }
            }
        }

        // Replace polygons with updated neighbor info
        $mesh->polygons = [];
        $mesh->grid = [];
        foreach ($indexed as $poly) {
            $mesh->addPolygon($poly);
        }

        return $mesh;
    }

    private function insertIntoGrid(NavMeshPolygon $polygon): void
    {
        $inv = 1.0 / $this->gridCellSize;

        // Compute AABB of polygon
        $minX = $maxX = $polygon->vertices[0]->x;
        $minZ = $maxZ = $polygon->vertices[0]->z;

        for ($i = 1, $n = count($polygon->vertices); $i < $n; $i++) {
            $v = $polygon->vertices[$i];
            if ($v->x < $minX) $minX = $v->x;
            if ($v->x > $maxX) $maxX = $v->x;
            if ($v->z < $minZ) $minZ = $v->z;
            if ($v->z > $maxZ) $maxZ = $v->z;
        }

        $x0 = (int) floor($minX * $inv);
        $z0 = (int) floor($minZ * $inv);
        $x1 = (int) floor($maxX * $inv);
        $z1 = (int) floor($maxZ * $inv);

        for ($x = $x0; $x <= $x1; $x++) {
            for ($z = $z0; $z <= $z1; $z++) {
                $this->grid["{$x}_{$z}"][] = $polygon->id;
            }
        }
    }

    /**
     * @return int[] Polygon IDs in the cell containing the position.
     */
    private function queryGrid(Vec3 $position): array
    {
        $inv = 1.0 / $this->gridCellSize;
        $x = (int) floor($position->x * $inv);
        $z = (int) floor($position->z * $inv);

        return $this->grid["{$x}_{$z}"] ?? [];
    }
}
