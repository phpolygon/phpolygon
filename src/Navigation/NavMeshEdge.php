<?php

declare(strict_types=1);

namespace PHPolygon\Navigation;

use PHPolygon\Math\Vec3;

/**
 * A portal edge shared between two NavMesh polygons.
 *
 * Used by the funnel algorithm to compute the shortest path
 * through a sequence of adjacent polygons.
 */
readonly class NavMeshEdge
{
    public function __construct(
        public Vec3 $left,
        public Vec3 $right,
        public int $polygonA,
        public int $polygonB,
    ) {}

    public function midpoint(): Vec3
    {
        return $this->left->lerp($this->right, 0.5);
    }

    public function width(): float
    {
        return sqrt($this->left->distanceSquaredTo($this->right));
    }

    /**
     * @return array{left: array{x: float, y: float, z: float}, right: array{x: float, y: float, z: float}, polygonA: int, polygonB: int}
     */
    public function toArray(): array
    {
        return [
            'left' => $this->left->toArray(),
            'right' => $this->right->toArray(),
            'polygonA' => $this->polygonA,
            'polygonB' => $this->polygonB,
        ];
    }

    /**
     * @param array{left: array{x: float, y: float, z: float}, right: array{x: float, y: float, z: float}, polygonA: int, polygonB: int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            left: Vec3::fromArray($data['left']),
            right: Vec3::fromArray($data['right']),
            polygonA: $data['polygonA'],
            polygonB: $data['polygonB'],
        );
    }
}
