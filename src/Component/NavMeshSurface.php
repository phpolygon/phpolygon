<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Navigation\NavMesh;
use PHPolygon\Navigation\NavMeshGeneratorConfig;

/**
 * Marks an entity as a NavMesh source.
 *
 * Attach this to a root entity to define a navigable area.
 * Multiple NavMeshSurface components can exist for separate
 * navigation areas (e.g. different building floors).
 */
#[Serializable]
#[Category('Navigation')]
class NavMeshSurface extends AbstractComponent
{
    #[Property]
    public float $cellSize;

    #[Property]
    public float $agentHeight;

    #[Property]
    public float $agentRadius;

    #[Property]
    public float $agentMaxClimb;

    #[Property]
    public float $agentMaxSlope;

    #[Hidden]
    public ?NavMesh $navMesh = null;

    #[Hidden]
    public bool $needsRebuild = true;

    public function __construct(
        float $cellSize = 0.3,
        float $agentHeight = 1.8,
        float $agentRadius = 0.4,
        float $agentMaxClimb = 0.3,
        float $agentMaxSlope = 45.0,
    ) {
        $this->cellSize = $cellSize;
        $this->agentHeight = $agentHeight;
        $this->agentRadius = $agentRadius;
        $this->agentMaxClimb = $agentMaxClimb;
        $this->agentMaxSlope = $agentMaxSlope;
    }

    /**
     * Get the generator config derived from this component's properties.
     */
    public function getConfig(): NavMeshGeneratorConfig
    {
        return new NavMeshGeneratorConfig(
            cellSize: $this->cellSize,
            agentHeight: $this->agentHeight,
            agentRadius: $this->agentRadius,
            agentMaxClimb: $this->agentMaxClimb,
            agentMaxSlope: $this->agentMaxSlope,
        );
    }

    /**
     * Mark the NavMesh for rebuild on next tick.
     */
    public function invalidate(): void
    {
        $this->needsRebuild = true;
    }
}
