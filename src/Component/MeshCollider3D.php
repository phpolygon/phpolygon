<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Physics\BVH;

/**
 * Triangle-mesh collider with BVH acceleration.
 * Uses the mesh from MeshRegistry identified by meshId.
 * The BVH is built lazily on first physics tick.
 */
#[Serializable]
#[Category('Physics')]
class MeshCollider3D extends AbstractComponent
{
    #[Property]
    public string $meshId;

    #[Property]
    public bool $isStatic;

    #[Property]
    public bool $isTrigger;

    #[Hidden]
    public ?BVH $bvh = null;

    /**
     * Cached world matrix array for change detection.
     * If the entity's transform changes, the BVH world-space triangles must be rebuilt.
     *
     * @var float[]|null
     */
    #[Hidden]
    public ?array $lastWorldMatrixArr = null;

    public function __construct(
        string $meshId = '',
        bool $isStatic = true,
        bool $isTrigger = false,
    ) {
        $this->meshId = $meshId;
        $this->isStatic = $isStatic;
        $this->isTrigger = $isTrigger;
    }
}
