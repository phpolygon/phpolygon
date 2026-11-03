<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

/**
 * A part that turns about a local axis while a signal says so: a roller, a fan, a
 * beacon, a gear. Driven by {@see \PHPolygon\System\SignalMotionSystem}; the rotation
 * the entity had on its first update is kept as the rest orientation and the spin is
 * applied on top of it (rest · axisAngle).
 *
 * Either it has its own drive - {@see $runChannel} / {@see $haltChannel} /
 * {@see $speedChannel} like {@see SignalTrack}, {@see $speed} radians per second,
 * eased over {@see $rampUp} / {@see $rampDown} - or it follows a track:
 * with {@see $followTrack} set its angle is that track's travel divided by
 * {@see $radius}, so a roller turns exactly as far as the belt on it moves, ramps and
 * halts included.
 *
 * {@see $effect}, {@see $reducedRampScale}, {@see $cullRadius} and
 * {@see $maxDistance} work as on {@see SignalTrack}.
 */
#[Serializable]
#[Category('Motion')]
class SignalSpin extends AbstractComponent
{
    #[Property]
    public ?int $source = null;

    #[Property(editorHint: 'vec3')]
    public Vec3 $axis;

    /** Radians per second at full drive (negative turns the other way). */
    #[Property]
    public float $speed = 1.0;

    #[Property]
    public string $runChannel = '';

    #[Property]
    public string $haltChannel = '';

    #[Property]
    public string $speedChannel = '';

    #[Property]
    public float $rampUp = 0.0;

    #[Property]
    public float $rampDown = 0.0;

    /** Entity with a {@see SignalTrack} whose travel this part follows; null = own drive. */
    #[Property]
    public ?int $followTrack = null;

    /** Radius of the roller when following a track, metres. */
    #[Property]
    public float $radius = 0.1;

    #[Property]
    public ?string $effect = null;

    #[Property]
    public float $reducedRampScale = 1.0;

    #[Property]
    public float $cullRadius = 0.0;

    #[Property]
    public float $maxDistance = 0.0;

    #[Hidden]
    public float $drive = 0.0;

    /** Current angle in radians (wrapped to one turn). */
    #[Hidden]
    public float $angle = 0.0;

    #[Hidden]
    public ?Quaternion $rest = null;

    public function __construct(
        ?Vec3 $axis = null,
        float $speed = 1.0,
        string $runChannel = '',
        string $haltChannel = '',
        float $rampUp = 0.0,
        float $rampDown = 0.0,
        ?int $source = null,
        ?int $followTrack = null,
        float $radius = 0.1,
    ) {
        $this->axis = ($axis ?? new Vec3(1.0, 0.0, 0.0))->normalize();
        $this->speed = $speed;
        $this->runChannel = $runChannel;
        $this->haltChannel = $haltChannel;
        $this->rampUp = max(0.0, $rampUp);
        $this->rampDown = max(0.0, $rampDown);
        $this->source = $source;
        $this->followTrack = $followTrack;
        $this->radius = $radius;
    }
}
