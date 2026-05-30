<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Vec3;

/** Player movement style for a shooter. */
enum ShooterMovement: string
{
    /** Strafe on the X/Y plane, clamped to bounds — arcade / rail shooter. */
    case Planar = 'planar';
    /** Mouse-look + WASD on the ground plane — first-person shooter. */
    case FirstPerson = 'firstPerson';
}

/**
 * Marks the player ship/avatar and configures how
 * {@see \PHPolygon\System\ShooterControllerSystem} reads input. In
 * {@see ShooterMovement::Planar} the body strafes within
 * [{@see $boundsMin}, {@see $boundsMax}] and always aims down -Z; in
 * {@see ShooterMovement::FirstPerson} the mouse drives {@see $yaw}/{@see $pitch}
 * and the aim follows the look direction. Movement is in units per tick.
 */
#[Serializable]
#[Category('Gameplay')]
class ShooterController extends AbstractComponent
{
    #[Property]
    public ShooterMovement $mode;

    /** Movement speed, units per tick. */
    #[Property]
    public float $moveSpeed;

    /** Planar movement clamp — minimum corner. */
    #[Property(editorHint: 'vec3')]
    public Vec3 $boundsMin;

    /** Planar movement clamp — maximum corner. */
    #[Property(editorHint: 'vec3')]
    public Vec3 $boundsMax;

    /** Mouse look sensitivity (radians per pixel), first-person mode. */
    #[Property]
    public float $sensitivity;

    /** Eye height above the body origin, first-person mode. */
    #[Property]
    public float $eyeHeight;

    // --- runtime state ---

    #[Hidden]
    public float $yaw = 0.0;

    #[Hidden]
    public float $pitch = 0.0;

    public function __construct(
        ShooterMovement $mode = ShooterMovement::Planar,
        float $moveSpeed = 0.3,
        ?Vec3 $boundsMin = null,
        ?Vec3 $boundsMax = null,
        float $sensitivity = 0.002,
        float $eyeHeight = 1.6,
    ) {
        $this->mode = $mode;
        $this->moveSpeed = $moveSpeed;
        $this->boundsMin = $boundsMin ?? new Vec3(-14.0, 2.0, 0.0);
        $this->boundsMax = $boundsMax ?? new Vec3(14.0, 13.0, 0.0);
        $this->sensitivity = $sensitivity;
        $this->eyeHeight = $eyeHeight;
    }
}
