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
 * A decal painted on whatever lies inside a box (shader plan 5.1): an oil stain on
 * the yard, soot round a fire, moss at a wall's foot, cracks in a slab. The box of
 * {@see $halfExtents} around the entity's {@see Transform3D} (turned by its yaw);
 * the decal fades towards its edges, broken up by noise. The eight decals nearest
 * to the camera reach the mesh shader ({@see \PHPolygon\System\DecalSystem}).
 */
#[Serializable]
#[Category('Rendering')]
class DecalVolume extends AbstractComponent
{
    public const int STAIN = 0;
    public const int SOOT = 1;
    public const int MOSS = 2;
    public const int CRACKS = 3;

    #[Property(editorHint: 'vec3')]
    public Vec3 $halfExtents;

    /** One of the kind constants. */
    #[Property]
    public int $kind;

    /** Stain: the colour the surface is multiplied towards; moss: its colour. */
    #[Property(editorHint: 'color')]
    public Color $color;

    #[Property]
    #[Range(min: 0, max: 1)]
    public float $strength;

    /** Only while a PointLight on the same entity shines (soot of a fire that is lit). */
    #[Property]
    public bool $followsLight = false;

    public function __construct(?Vec3 $halfExtents = null, int $kind = self::STAIN, ?Color $color = null, float $strength = 0.8, bool $followsLight = false)
    {
        $this->followsLight = $followsLight;
        $this->halfExtents = $halfExtents ?? new Vec3(1.0, 0.5, 1.0);
        $this->kind = $kind;
        $this->color = $color ?? new Color(0.35, 0.33, 0.3);
        $this->strength = $strength;
    }
}
