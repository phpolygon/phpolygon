<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Range;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Rendering\Color;

#[Serializable]
#[Category('Lighting')]
class PointLight extends AbstractComponent
{
    #[Property(editorHint: 'color')]
    public Color $color;

    #[Property(editorHint: 'slider')]
    #[Range(min: 0, max: 10)]
    public float $intensity;

    #[Property]
    public float $radius;

    /**
     * Casts a shadow: the renderer draws a depth cube around the point light. How many
     * lights get one per frame is GraphicsSettings::$pointShadowLights (default 1: the
     * best-ranked light, see $shadowPriority); a light the budget leaves out is lit
     * without a shadow.
     */
    #[Property]
    public bool $castsShadow;

    /**
     * Rank among the shadow-casting lights when the budget is smaller than their number:
     * higher wins, equal priorities go to the nearer light (distance to its influence sphere).
     */
    #[Property]
    public int $shadowPriority;

    /**
     * Face size of this light's depth cube in texels, 0 = the quality tier's size. A hint
     * that only lowers the size (small rooms need no big cube); rounded down to a power of two.
     * Honoured only with several shadowed lights (GraphicsSettings::$pointShadowLights > 1);
     * the single cube keeps the tier's size.
     */
    #[Property]
    public int $shadowResolution;

    public function __construct(
        ?Color $color = null,
        float $intensity = 1.0,
        float $radius = 10.0,
        bool $castsShadow = false,
        int $shadowPriority = 0,
        int $shadowResolution = 0,
    ) {
        $this->color = $color ?? Color::white();
        $this->intensity = $intensity;
        $this->radius = $radius;
        $this->castsShadow = $castsShadow;
        $this->shadowPriority = $shadowPriority;
        $this->shadowResolution = $shadowResolution;
    }
}
