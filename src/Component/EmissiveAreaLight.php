<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Range;
use PHPolygon\ECS\Attribute\Serializable;

/**
 * Lets a glowing surface light its surroundings: {@see \PHPolygon\System\EmissiveAreaLightSystem}
 * keeps a {@see PointLight} on this entity whose colour and strength follow the emission
 * of the material `$materialId` – a neon sign, a lit window, a screen. When the material
 * stops glowing (its emission is switched off at day or without power), the light goes
 * dark with it; nothing else writes the light.
 *
 * Put it on an entity at the place the light should come from (usually a child just in
 * front of the glowing face).
 */
#[Serializable]
#[Category('Lighting')]
class EmissiveAreaLight extends AbstractComponent
{
    /** Material whose emission drives the light. */
    #[Property]
    public string $materialId = '';

    /** Reach of the light in metres. */
    #[Property]
    public float $radius = 6.0;

    /** Light intensity per unit of emission (the brightest channel). */
    #[Property]
    #[Range(min: 0, max: 10)]
    public float $scale = 1.0;

    public function __construct(string $materialId = '', float $radius = 6.0, float $scale = 1.0)
    {
        $this->materialId = $materialId;
        $this->radius = $radius;
        $this->scale = $scale;
    }
}
