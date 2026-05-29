<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

/**
 * Marks a child mesh of a {@see PlatformerController} character as part of a
 * swinging leg, so {@see \PHPolygon\System\PlatformerAnimationSystem} can give
 * it the classic run cycle without a dedicated bone/skeleton rig.
 *
 * The original JSX put the leg + foot meshes in a `THREE.Group` and rotated the
 * group about the hip. The importer flattens that hierarchy, so instead each
 * leg/foot mesh carries this component and is rotated **about a shared hip
 * pivot** every frame — multiple meshes sharing one {@see $pivot} and
 * {@see $swingSign} stay rigid relative to each other, reproducing the group.
 *
 * All transforms are in the parent (character) local space, the same space the
 * mesh's own {@see Transform3D} lives in.
 */
#[Serializable]
#[Category('Gameplay')]
class PlatformerLegSegment extends AbstractComponent
{
    /** Rest local position (the pose the importer captured). */
    #[Property(editorHint: 'vec3')]
    public Vec3 $restPosition;

    /** Rest local rotation; the swing is composed on top of it. */
    #[Property]
    public Quaternion $restRotation;

    /** Hip pivot (local space) the leg rotates about. Shared by a leg's meshes. */
    #[Property(editorHint: 'vec3')]
    public Vec3 $pivot;

    /**
     * Swing direction: +1 and -1 for the two legs so they alternate. The
     * airborne "tuck" pose uses the opposite sign (matching the original).
     */
    #[Property]
    public float $swingSign;

    public function __construct(
        ?Vec3 $restPosition = null,
        ?Quaternion $restRotation = null,
        ?Vec3 $pivot = null,
        float $swingSign = 1.0,
    ) {
        $this->restPosition = $restPosition ?? Vec3::zero();
        $this->restRotation = $restRotation ?? Quaternion::identity();
        $this->pivot = $pivot ?? Vec3::zero();
        $this->swingSign = $swingSign;
    }
}
