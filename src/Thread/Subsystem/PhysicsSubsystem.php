<?php

declare(strict_types=1);

namespace PHPolygon\Thread\Subsystem;

use PHPolygon\Component\BoxCollider2D;
use PHPolygon\Component\RigidBody2D;
use PHPolygon\Component\Transform2D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec2;
use PHPolygon\Thread\SubsystemInterface;

/**
 * Bridges the ECS World and the pure-math PhysicsSolver for threaded execution.
 */
class PhysicsSubsystem implements SubsystemInterface
{
    private Vec2 $gravity;

    public function __construct()
    {
        $this->gravity = new Vec2(0.0, 980.0);
    }

    public function setGravity(Vec2 $gravity): void
    {
        $this->gravity = $gravity;
    }

    public function prepareInput(World $world, float $dt): array
    {
        $bodies = [];
        $colliders = [];

        foreach ($world->query(Transform2D::class, RigidBody2D::class) as $entity) {
            $t = $world->getComponent($entity->id, Transform2D::class);
            $rb = $world->getComponent($entity->id, RigidBody2D::class);

            $bodies[$entity->id] = [
                'x' => $t->position->x,
                'y' => $t->position->y,
                'vx' => $rb->velocity->x,
                'vy' => $rb->velocity->y,
                'ax' => $rb->acceleration->x,
                'ay' => $rb->acceleration->y,
                'mass' => $rb->mass,
                'drag' => $rb->drag,
                'gravityScale' => $rb->gravityScale,
                'isKinematic' => $rb->isKinematic,
                'restitution' => $rb->restitution,
                'fixedRotation' => $rb->fixedRotation,
                'angularVelocity' => $rb->angularVelocity,
                'angularDrag' => $rb->angularDrag,
                'rotation' => $t->rotation,
            ];
        }

        foreach ($world->query(Transform2D::class, BoxCollider2D::class) as $entity) {
            $collider = $world->getComponent($entity->id, BoxCollider2D::class);
            $colliders[$entity->id] = [
                'ox' => $collider->offset->x - $collider->size->x * 0.5,
                'oy' => $collider->offset->y - $collider->size->y * 0.5,
                'w' => $collider->size->x,
                'h' => $collider->size->y,
                'isTrigger' => $collider->isTrigger,
            ];
        }

        return [
            'dt' => $dt,
            'gravity' => [$this->gravity->x, $this->gravity->y],
            'bodies' => $bodies,
            'colliders' => $colliders,
        ];
    }

    public function applyDeltas(World $world, array $deltas): void
    {
        /** @var array<int, array{0: float, 1: float}> $positions */
        $positions = $deltas['positions'] ?? [];
        /** @var array<int, array{0: float, 1: float}> $velocities */
        $velocities = $deltas['velocities'] ?? [];
        /** @var array<int, float> $rotations */
        $rotations = $deltas['rotations'] ?? [];
        /** @var array<int, float> $angularVelocities */
        $angularVelocities = $deltas['angularVelocities'] ?? [];

        foreach ($positions as $id => $pos) {
            $transform = $world->tryGetComponent($id, Transform2D::class);
            if ($transform instanceof Transform2D) {
                $transform->position = new Vec2($pos[0], $pos[1]);
            }
        }

        foreach ($velocities as $id => $vel) {
            $rb = $world->tryGetComponent($id, RigidBody2D::class);
            if ($rb instanceof RigidBody2D) {
                $rb->velocity = new Vec2($vel[0], $vel[1]);
                $rb->acceleration = Vec2::zero(); // Clear per-frame acceleration
            }
        }

        foreach ($rotations as $id => $rotation) {
            $transform = $world->tryGetComponent($id, Transform2D::class);
            if ($transform instanceof Transform2D) {
                $transform->rotation = $rotation;
            }
        }

        foreach ($angularVelocities as $id => $angVel) {
            $rb = $world->tryGetComponent($id, RigidBody2D::class);
            if ($rb instanceof RigidBody2D) {
                $rb->angularVelocity = $angVel;
            }
        }
    }

    public static function threadEntry(string $channelPrefix): void
    {
        $in = \parallel\Channel::open("{$channelPrefix}_in");
        $out = \parallel\Channel::open("{$channelPrefix}_out");

        while (true) {
            $input = $in->recv();
            if (!is_array($input)) {
                break;
            }
            /** @var array<string, mixed> $input */
            $out->send(self::compute($input));
        }
    }

    public static function compute(array $input): array
    {
        return PhysicsSolver::simulate($input);
    }
}
