<?php

declare(strict_types=1);

namespace PHPolygon\Physics;

use PHPolygon\Math\Vec3;

/**
 * Binary Bounding Volume Hierarchy for fast triangle queries.
 * Built top-down with median split. Leaf threshold = 8 triangles.
 */
class BVH
{
    private const LEAF_THRESHOLD = 8;

    private Vec3 $min;
    private Vec3 $max;
    private ?BVH $left = null;
    private ?BVH $right = null;

    /** @var Triangle[]|null Only set for leaf nodes */
    private ?array $triangles = null;

    private function __construct(Vec3 $min, Vec3 $max)
    {
        $this->min = $min;
        $this->max = $max;
    }

    /**
     * Build a BVH from an array of triangles.
     *
     * @param Triangle[] $triangles
     */
    public static function build(array $triangles): self
    {
        if (empty($triangles)) {
            return new self(Vec3::zero(), Vec3::zero());
        }

        // Compute AABB of all triangles
        $bounds = self::computeBounds($triangles);
        $node = new self($bounds['min'], $bounds['max']);

        if (count($triangles) <= self::LEAF_THRESHOLD) {
            $node->triangles = $triangles;
            return $node;
        }

        // Find the longest axis
        $extent = $bounds['max']->sub($bounds['min']);
        if ($extent->x >= $extent->y && $extent->x >= $extent->z) {
            $axis = 0; // X
        } elseif ($extent->y >= $extent->z) {
            $axis = 1; // Y
        } else {
            $axis = 2; // Z
        }

        // Sort by centroid along the chosen axis
        usort($triangles, function (Triangle $a, Triangle $b) use ($axis): int {
            $centA = self::triangleCentroid($a);
            $centB = self::triangleCentroid($b);
            $valA = match ($axis) { 0 => $centA->x, 1 => $centA->y, 2 => $centA->z };
            $valB = match ($axis) { 0 => $centB->x, 1 => $centB->y, 2 => $centB->z };
            return $valA <=> $valB;
        });

        // Median split
        $mid = (int)(count($triangles) / 2);
        $leftTris = array_slice($triangles, 0, $mid);
        $rightTris = array_slice($triangles, $mid);

        // Fallback: if one side is empty, make this a leaf
        if (empty($leftTris) || empty($rightTris)) {
            $node->triangles = $triangles;
            return $node;
        }

        $node->left = self::build($leftTris);
        $node->right = self::build($rightTris);

        return $node;
    }

    /**
     * Query all triangles whose leaf AABB overlaps the given query AABB.
     *
     * @return Triangle[]
     */
    public function query(Vec3 $queryMin, Vec3 $queryMax): array
    {
        // AABB overlap test
        if ($queryMax->x < $this->min->x || $queryMin->x > $this->max->x
            || $queryMax->y < $this->min->y || $queryMin->y > $this->max->y
            || $queryMax->z < $this->min->z || $queryMin->z > $this->max->z) {
            return [];
        }

        // Leaf node — return all triangles
        if ($this->triangles !== null) {
            return $this->triangles;
        }

        // Internal node — recurse
        $result = [];
        if ($this->left !== null) {
            $leftResult = $this->left->query($queryMin, $queryMax);
            if (!empty($leftResult)) {
                array_push($result, ...$leftResult);
            }
        }
        if ($this->right !== null) {
            $rightResult = $this->right->query($queryMin, $queryMax);
            if (!empty($rightResult)) {
                array_push($result, ...$rightResult);
            }
        }

        return $result;
    }

    /**
     * Total number of triangles in this BVH.
     */
    public function triangleCount(): int
    {
        if ($this->triangles !== null) {
            return count($this->triangles);
        }

        $count = 0;
        if ($this->left !== null) {
            $count += $this->left->triangleCount();
        }
        if ($this->right !== null) {
            $count += $this->right->triangleCount();
        }

        return $count;
    }

    private static function triangleCentroid(Triangle $tri): Vec3
    {
        return new Vec3(
            (float)(($tri->v0->x + $tri->v1->x + $tri->v2->x) / 3.0),
            (float)(($tri->v0->y + $tri->v1->y + $tri->v2->y) / 3.0),
            (float)(($tri->v0->z + $tri->v1->z + $tri->v2->z) / 3.0),
        );
    }

    /**
     * @param Triangle[] $triangles
     * @return array{min: Vec3, max: Vec3}
     */
    private static function computeBounds(array $triangles): array
    {
        $minX = $minY = $minZ = PHP_FLOAT_MAX;
        $maxX = $maxY = $maxZ = -PHP_FLOAT_MAX;

        foreach ($triangles as $tri) {
            foreach ([$tri->v0, $tri->v1, $tri->v2] as $v) {
                $minX = min($minX, $v->x);
                $minY = min($minY, $v->y);
                $minZ = min($minZ, $v->z);
                $maxX = max($maxX, $v->x);
                $maxY = max($maxY, $v->y);
                $maxZ = max($maxZ, $v->z);
            }
        }

        return [
            'min' => new Vec3((float)$minX, (float)$minY, (float)$minZ),
            'max' => new Vec3((float)$maxX, (float)$maxY, (float)$maxZ),
        ];
    }
}
