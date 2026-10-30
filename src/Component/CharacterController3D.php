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
 * Character movement state for physics-driven characters.
 */
enum CharacterState: string
{
    case Walking = 'walking';
    case Falling = 'falling';
    case Seated = 'seated';         // Coupled to a seat (chair, bench, vehicle)
    case Mounted = 'mounted';       // Coupled to a vehicle/mount
    case Animated = 'animated';     // Playing a scripted animation (no physics)
}

#[Serializable]
#[Category('Physics')]
class CharacterController3D extends AbstractComponent
{
    #[Property]
    public float $height;

    #[Property]
    public float $radius;

    #[Property]
    public float $stepHeight;

    #[Property]
    public float $slopeLimit;

    #[Hidden]
    public Vec3 $velocity;

    #[Hidden]
    public bool $isGrounded;

    /**
     * Entity this character stands on: the collider or heightmap that grounded
     * it in the last physics tick (when several did, the one that decided the
     * final height). Written where isGrounded is, so a null here while airborne
     * is the same fact as isGrounded = false. Null while grounded means the
     * bare ground plane of {@see \PHPolygon\System\Physics3DSystem}, which is
     * no entity. Triggers are never the ground.
     */
    #[Hidden]
    public ?int $groundEntityId = null;

    // --- State & coupling ---

    #[Hidden]
    public CharacterState $state = CharacterState::Walking;

    /**
     * Entity ID this character is coupled to (seat, vehicle, mount).
     * When set, the character follows the coupled entity's transform.
     * Physics is disabled while coupled.
     */
    #[Hidden]
    public ?int $coupledToEntity = null;

    /**
     * Local offset from the coupled entity's origin (e.g., seat position).
     */
    #[Hidden]
    public Vec3 $coupledOffset;

    /**
     * Whether the character inherits the coupled entity's rotation.
     */
    #[Hidden]
    public bool $coupledInheritRotation = true;

    // --- Animation ---

    /**
     * Current animation callback. Called each frame while state is Animated.
     * Signature: fn(CharacterController3D, Transform3D, float $dt, float $elapsed): bool
     * Return false to end the animation and return to Walking state.
     *
     * @var (callable(CharacterController3D, Transform3D, float, float): bool)|null
     */
    #[Hidden]
    public $animationCallback = null;

    /**
     * Elapsed time since animation started.
     */
    #[Hidden]
    public float $animationElapsed = 0.0;

    public function __construct(
        float $height = 1.8,
        float $radius = 0.4,
        float $stepHeight = 0.3,
        float $slopeLimit = 45.0,
    ) {
        $this->height = $height;
        $this->radius = $radius;
        $this->stepHeight = $stepHeight;
        $this->slopeLimit = $slopeLimit;
        $this->velocity = Vec3::zero();
        $this->isGrounded = false;
        $this->coupledOffset = Vec3::zero();
    }

    /**
     * Couple this character to another entity (seat, vehicle, mount).
     * Physics is disabled while coupled — the character follows the target entity.
     */
    public function coupleTo(int $entityId, Vec3 $localOffset, CharacterState $state = CharacterState::Seated, bool $inheritRotation = true): void
    {
        $this->coupledToEntity = $entityId;
        $this->coupledOffset = $localOffset;
        $this->coupledInheritRotation = $inheritRotation;
        $this->state = $state;
        $this->velocity = Vec3::zero();
        $this->isGrounded = false;
        $this->groundEntityId = null;
    }

    /**
     * Decouple from the current entity and return to physics control.
     */
    public function decouple(): void
    {
        $this->coupledToEntity = null;
        $this->state = CharacterState::Walking;
    }

    /**
     * Start a scripted animation. Physics is disabled during animation.
     * The callback is called each frame with (controller, transform, dt, elapsed).
     * Return false from the callback to end the animation.
     *
     * @param callable(CharacterController3D, Transform3D, float, float): bool $callback
     */
    public function playAnimation(callable $callback): void
    {
        $this->state = CharacterState::Animated;
        $this->animationCallback = $callback;
        $this->animationElapsed = 0.0;
        $this->velocity = Vec3::zero();
    }

    /**
     * Whether this character is under physics control (not coupled or animated).
     */
    public function isPhysicsActive(): bool
    {
        return $this->state === CharacterState::Walking
            || $this->state === CharacterState::Falling;
    }
}
