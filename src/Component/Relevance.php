<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

/**
 * Opts an entity into {@see \PHPolygon\Activity\ActivitySystem}'s automatic
 * activity tiering (Phase 4 of the visibility plan, 2026-11-11). An entity
 * WITHOUT this component is never touched by ActivitySystem - it stays at
 * the default Visible tier forever, exactly as every entity did before this
 * phase existed. Attach to the ROOT of a Transform3D subtree only -
 * ActivitySystem applies the tier it computes to every `childEntityIds`
 * descendant too (the same whole-subtree rule {@see \PHPolygon\ECS\World::setEntityDormant()}'s
 * docblock already documents); a Relevance component on a non-root entity
 * is read, but its subtree is whatever that entity's own children are.
 */
#[Serializable]
#[Category('Activity')]
class Relevance extends AbstractComponent
{
    #[Property]
    public RelevanceMode $mode;

    /**
     * Distance (world metres) from the camera at which this entity leaves
     * Visible for Near, on top of ActivitySystem's own near/far radii -
     * 0.0 (default) defers entirely to those shared radii. Only meaningful
     * for {@see RelevanceMode::Auto} and {@see RelevanceMode::Near}.
     */
    #[Property]
    public float $radius;

    public function __construct(RelevanceMode $mode = RelevanceMode::Auto, float $radius = 0.0)
    {
        $this->mode = $mode;
        $this->radius = $radius;
    }
}
