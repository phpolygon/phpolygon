<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Vec3;

/**
 * Dynamic obstacle that carves into the NavMesh at runtime.
 *
 * Entities with this component modify the walkable area of the
 * NavMesh. When carve=true, the NavigationSystem will remove
 * polygons overlapping the obstacle.
 */
#[Serializable]
#[Category('Navigation')]
class NavMeshObstacle extends AbstractComponent
{
    #[Property]
    public float $radius;

    #[Property]
    public float $height;

    #[Property]
    public bool $carve;

    #[Property]
    public float $carvingMoveThreshold;

    #[Hidden]
    public ?Vec3 $lastCarvedPosition = null;

    public function __construct(
        float $radius = 1.0,
        float $height = 2.0,
        bool $carve = true,
        float $carvingMoveThreshold = 0.1,
    ) {
        $this->radius = $radius;
        $this->height = $height;
        $this->carve = $carve;
        $this->carvingMoveThreshold = $carvingMoveThreshold;
    }
}
