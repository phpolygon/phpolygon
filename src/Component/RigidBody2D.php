<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Range;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Vec2;

#[Serializable]
#[Category('Physics')]
class RigidBody2D extends AbstractComponent
{
    #[Property(editorHint: 'vec2')]
    public Vec2 $velocity;

    #[Property(editorHint: 'vec2')]
    public Vec2 $acceleration;

    #[Property]
    #[Range(min: 0.001, max: 10000)]
    public float $mass;

    #[Property]
    #[Range(min: 0, max: 1)]
    public float $drag;

    #[Property]
    #[Range(min: 0, max: 1)]
    public float $angularDrag;

    #[Property]
    public float $angularVelocity;

    #[Property]
    #[Range(min: 0, max: 10)]
    public float $gravityScale;

    #[Property]
    public float $restitution;

    #[Property]
    public bool $isKinematic;

    #[Property]
    public bool $fixedRotation;

    public function __construct(
        ?Vec2 $velocity = null,
        ?Vec2 $acceleration = null,
        float $mass = 1.0,
        float $drag = 0.0,
        float $angularDrag = 0.05,
        float $angularVelocity = 0.0,
        float $gravityScale = 1.0,
        float $restitution = 0.0,
        bool $isKinematic = false,
        bool $fixedRotation = false,
    ) {
        $this->velocity = $velocity ?? Vec2::zero();
        $this->acceleration = $acceleration ?? Vec2::zero();
        $this->mass = $mass;
        $this->drag = $drag;
        $this->angularDrag = $angularDrag;
        $this->angularVelocity = $angularVelocity;
        $this->gravityScale = $gravityScale;
        $this->restitution = $restitution;
        $this->isKinematic = $isKinematic;
        $this->fixedRotation = $fixedRotation;
    }

    public function addForce(Vec2 $force): void
    {
        $this->acceleration = $this->acceleration->add($force->div($this->mass));
    }

    public function addImpulse(Vec2 $impulse): void
    {
        $this->velocity = $this->velocity->add($impulse->div($this->mass));
    }
}
