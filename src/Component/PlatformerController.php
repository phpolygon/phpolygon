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
 * Arcade platformer movement — the "Super-Mario-64-ish" feel: ground/air
 * acceleration, friction, a capped run speed, a variable-height jump and a
 * capped fall speed, resolved against {@see BoxCollider3D} solids by
 * {@see \PHPolygon\System\PlatformerControllerSystem}.
 *
 * The defaults are tuned in *per-tick* units (the engine runs a fixed 60 Hz
 * timestep), so a value of `gravity = 0.045` means "subtract 0.045 from the
 * vertical velocity every tick". This keeps the hand-authored feel of classic
 * frame-based platformer code intact instead of forcing an m/s² re-derivation.
 *
 * This is a deliberate, self-contained alternative to
 * {@see CharacterController3D} + {@see \PHPolygon\System\Physics3DSystem}
 * (capsule, dt-based, no variable jump): the platformer needs the exact
 * frame-based response, but still consumes the engine's standard
 * {@see BoxCollider3D} as its collision source.
 */
#[Serializable]
#[Category('Gameplay')]
class PlatformerController extends AbstractComponent
{
    /** Horizontal half-extents (x,z) and vertical half-height (y) of the body AABB. */
    #[Property(editorHint: 'vec3')]
    public Vec3 $halfExtents;

    /** Ground acceleration applied per tick toward the input direction. */
    #[Property]
    public float $moveAccel;

    /** Air acceleration (usually lower than ground). */
    #[Property]
    public float $airAccel;

    /** Multiplicative ground friction applied when there is no input (0..1). */
    #[Property]
    public float $friction;

    /** Horizontal speed cap. */
    #[Property]
    public float $maxSpeed;

    /** Upward velocity applied on jump. */
    #[Property]
    public float $jumpVelocity;

    /** Gravity subtracted from vertical velocity per tick. */
    #[Property]
    public float $gravity;

    /** Maximum fall speed (terminal velocity). */
    #[Property]
    public float $maxFall;

    /**
     * Fraction the upward velocity is cut to when the jump key is released
     * mid-rise — the classic "tap = short hop, hold = full jump".
     */
    #[Property]
    public float $jumpCutFactor;

    /** Y below which the character is considered fallen and respawns. */
    #[Property]
    public float $killPlaneY;

    /** Invulnerability frames granted after a respawn / hit. */
    #[Property]
    public int $invulnFrames;

    // --- runtime state ---

    #[Hidden]
    public Vec3 $velocity;

    #[Hidden]
    public bool $onGround = false;

    #[Hidden]
    public bool $jumpHeld = false;

    /** Yaw (radians) the body faces; eased toward the movement direction. */
    #[Hidden]
    public float $facing = 0.0;

    /** Accumulated stride phase, advanced while moving on the ground. */
    #[Hidden]
    public float $animPhase = 0.0;

    /** Remaining invulnerability frames (blink + no enemy damage). */
    #[Hidden]
    public int $invuln = 0;

    /** Last grounded position, used as the respawn point after a fall. */
    #[Hidden]
    public Vec3 $lastSafe;

    public function __construct(
        ?Vec3 $halfExtents = null,
        float $moveAccel = 0.05,
        float $airAccel = 0.03,
        float $friction = 0.82,
        float $maxSpeed = 0.22,
        float $jumpVelocity = 0.62,
        float $gravity = 0.045,
        float $maxFall = 0.9,
        float $jumpCutFactor = 0.5,
        float $killPlaneY = -16.0,
        int $invulnFrames = 90,
    ) {
        $this->halfExtents = $halfExtents ?? new Vec3(0.45, 0.85, 0.45);
        $this->moveAccel = $moveAccel;
        $this->airAccel = $airAccel;
        $this->friction = $friction;
        $this->maxSpeed = $maxSpeed;
        $this->jumpVelocity = $jumpVelocity;
        $this->gravity = $gravity;
        // System applies `max($vy - gravity, -$maxFall)`; a negative or zero
        // terminal velocity would silently clamp $vy upward, freezing the fall.
        $this->maxFall = max(0.0, $maxFall);
        $this->jumpCutFactor = $jumpCutFactor;
        $this->killPlaneY = $killPlaneY;
        $this->invulnFrames = $invulnFrames;
        $this->velocity = Vec3::zero();
        $this->lastSafe = Vec3::zero();
    }
}
