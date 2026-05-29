<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

/** Which world axis a {@see Patrol} oscillates along. */
enum PatrolAxis: string
{
    case X = 'x';
    case Y = 'y';
    case Z = 'z';
}

/**
 * Back-and-forth movement along one world axis between {@see $min} and
 * {@see $max}, at {@see $speed} per tick. Driven by
 * {@see \PHPolygon\System\PatrolSystem}, which also yaws the entity to face
 * its travel direction.
 */
#[Serializable]
#[Category('Gameplay')]
class Patrol extends AbstractComponent
{
    #[Property]
    public PatrolAxis $axis;

    #[Property]
    public float $min;

    #[Property]
    public float $max;

    /** Distance travelled per tick. */
    #[Property]
    public float $speed;

    /** Current travel direction: +1 or -1. */
    #[Hidden]
    public int $dir = 1;

    public function __construct(
        PatrolAxis $axis = PatrolAxis::X,
        float $min = -1.0,
        float $max = 1.0,
        float $speed = 0.035,
        int $dir = 1,
    ) {
        $this->axis = $axis;
        // Reversed bounds would put PatrolSystem into a one-tick ping-pong as
        // `$value > $max` and `$value < $min` both trip on the same frame.
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }
        $this->min = $min;
        $this->max = $max;
        $this->speed = $speed;
        // Direction must be ±1; anything else (0 in particular) freezes the
        // entity forever since the system advances `value += dir * speed`.
        $this->dir = $dir < 0 ? -1 : 1;
    }
}
