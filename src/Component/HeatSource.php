<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Range;
use PHPolygon\ECS\Attribute\Serializable;

/**
 * Something hot whose air shimmers (a fire, an exhaust, a sun-baked roof): the
 * heat haze pass ({@see \PHPolygon\Rendering\PostProcess\HeatHaze}) wobbles the
 * image in a zone of {@see $radius} metres above it. With a {@see PointLight} on
 * the same entity the shimmer follows the light's intensity relative to
 * {@see $fullIntensity}, so a fire that is out does not shimmer.
 */
#[Serializable]
#[Category('Rendering')]
class HeatSource extends AbstractComponent
{
    #[Property]
    public float $radius;

    #[Property]
    #[Range(min: 0, max: 1)]
    public float $strength;

    /** Light intensity at which the shimmer is full (with a PointLight on the entity). */
    #[Property]
    public float $fullIntensity;

    public function __construct(float $radius = 1.5, float $strength = 0.6, float $fullIntensity = 1.0)
    {
        $this->radius = $radius;
        $this->strength = $strength;
        $this->fullIntensity = $fullIntensity;
    }
}
