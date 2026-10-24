<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

/**
 * Sway parameters of the legacy multi-entity palm parts.
 *
 * @deprecated No system ever read this component. Plants sway in the vertex
 *             shader instead: give the bark and foliage materials
 *             `foliageWind` (Material::bark() / Material::foliage()) and the
 *             scene's Wind drives them (Renderer3DSystem emits SetWind). Kept
 *             so existing scenes that serialise it still load.
 */
#[Serializable]
#[Category('Animation')]
class PalmSway extends AbstractComponent
{
    #[Property]
    public float $swayStrength;

    #[Property]
    public float $phaseOffset;

    #[Property]
    public bool $isTrunk;

    public function __construct(
        float $swayStrength = 0.5,
        float $phaseOffset = 0.0,
        bool $isTrunk = false,
    ) {
        $this->swayStrength = $swayStrength;
        $this->phaseOffset = $phaseOffset;
        $this->isTrunk = $isTrunk;
    }
}
