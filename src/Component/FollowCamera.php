<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Vec3;

/**
 * Third-person follow camera. {@see \PHPolygon\System\FollowCameraSystem}
 * eases the camera entity's transform toward a framing derived from a target
 * entity's world position, then {@see Camera3DComponent}/Camera3DSystem render
 * the view from that transform.
 *
 * The framing is `target * scale + offset` for both the eye position and the
 * look-at point, which covers the common "trail behind and above, look
 * slightly ahead" rig (including partial horizontal damping via a <1 scale).
 */
#[Serializable]
#[Category('Rendering')]
class FollowCamera extends AbstractComponent
{
    /** Name of the entity to follow (resolved each frame by the system). */
    #[Property]
    public string $targetName;

    /** Smoothing factor 0..1 applied to the eye position each frame (1 = snap). */
    #[Property]
    public float $lerpFactor;

    /** Eye position = target * positionScale + positionOffset. */
    #[Property(editorHint: 'vec3')]
    public Vec3 $positionScale;

    #[Property(editorHint: 'vec3')]
    public Vec3 $positionOffset;

    /** Look-at point = target * lookScale + lookOffset. */
    #[Property(editorHint: 'vec3')]
    public Vec3 $lookScale;

    #[Property(editorHint: 'vec3')]
    public Vec3 $lookOffset;

    /** Whether the eye position has been seeded (first frame snaps, no lerp). */
    #[Hidden]
    public bool $initialised = false;

    public function __construct(
        string $targetName = '',
        float $lerpFactor = 0.08,
        ?Vec3 $positionScale = null,
        ?Vec3 $positionOffset = null,
        ?Vec3 $lookScale = null,
        ?Vec3 $lookOffset = null,
    ) {
        $this->targetName = $targetName;
        $this->lerpFactor = $lerpFactor;
        $this->positionScale = $positionScale ?? new Vec3(1.0, 1.0, 1.0);
        $this->positionOffset = $positionOffset ?? new Vec3(0.0, 5.5, 9.5);
        $this->lookScale = $lookScale ?? new Vec3(1.0, 1.0, 1.0);
        $this->lookOffset = $lookOffset ?? new Vec3(0.0, 1.0, 0.0);
    }
}
