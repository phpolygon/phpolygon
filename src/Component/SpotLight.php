<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Range;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;

/**
 * Cone-shaped light. Like {@see PointLight} it has no position field of its
 * own — the world position comes from the entity's {@see Transform3D}. It adds
 * a beam direction plus a cone half-angle and a penumbra (soft edge) factor.
 *
 * The Renderer3DSystem turns each SpotLight into an
 * {@see \PHPolygon\Rendering\Command\AddSpotLight} command (mirroring how
 * PointLight becomes AddPointLight). Distance/range attenuation matches the
 * point-light falloff; on top of that the backend shaders multiply by a cone
 * factor smoothstepped between cos(angle) and cos(angle * (1 - penumbra)).
 */
#[Serializable]
#[Category('Lighting')]
class SpotLight extends AbstractComponent
{
    #[Property(editorHint: 'vec3')]
    public Vec3 $direction;

    #[Property(editorHint: 'color')]
    public Color $color;

    #[Property(editorHint: 'slider')]
    #[Range(min: 0, max: 10)]
    public float $intensity;

    /** Distance falloff range (analogous to {@see PointLight::$radius}). */
    #[Property]
    public float $range;

    /** Cone half-angle in radians (apex to edge). */
    #[Property(editorHint: 'slider')]
    #[Range(min: 0, max: 1.5707963267948966)]
    public float $angle;

    /** Soft-edge fraction (0 = hard cone, 1 = fully feathered to the apex). */
    #[Property(editorHint: 'slider')]
    #[Range(min: 0, max: 1)]
    public float $penumbra;

    public function __construct(
        ?Vec3 $direction = null,
        ?Color $color = null,
        float $intensity = 1.0,
        float $range = 10.0,
        float $angle = 0.5,
        float $penumbra = 0.1,
    ) {
        $this->direction = $direction ?? new Vec3(0.0, -1.0, 0.0);
        $this->color = $color ?? Color::white();
        $this->intensity = $intensity;
        $this->range = $range;
        $this->angle = $angle;
        $this->penumbra = $penumbra;
    }
}
