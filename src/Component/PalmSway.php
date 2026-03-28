<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

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
