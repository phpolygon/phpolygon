<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Thread\Subsystem;

use PHPUnit\Framework\TestCase;
use PHPolygon\Thread\Subsystem\PhysicsSolver;

class PhysicsSolverTest extends TestCase
{
    public function testGravityIntegration(): void
    {
        $result = PhysicsSolver::simulate([
            'dt' => 1.0,
            'gravity' => [0.0, 10.0],
            'bodies' => [
                1 => [
                    'x' => 0.0, 'y' => 0.0,
                    'vx' => 0.0, 'vy' => 0.0,
                    'ax' => 0.0, 'ay' => 0.0,
                    'mass' => 1.0, 'drag' => 0.0,
                    'gravityScale' => 1.0,
                    'isKinematic' => false,
                    'restitution' => 0.0,
                    'fixedRotation' => true,
                    'angularVelocity' => 0.0,
                    'angularDrag' => 0.0,
                    'rotation' => 0.0,
                ],
            ],
            'colliders' => [],
        ]);

        // After 1s with gravity 10: vy=10, y=10
        $this->assertEqualsWithDelta(10.0, $result['velocities'][1][1], 0.001);
        $this->assertEqualsWithDelta(10.0, $result['positions'][1][1], 0.001);
        $this->assertEqualsWithDelta(0.0, $result['positions'][1][0], 0.001);
    }

    public function testKinematicBodyDoesNotMove(): void
    {
        $result = PhysicsSolver::simulate([
            'dt' => 1.0,
            'gravity' => [0.0, 10.0],
            'bodies' => [
                1 => [
                    'x' => 5.0, 'y' => 5.0,
                    'vx' => 0.0, 'vy' => 0.0,
                    'ax' => 0.0, 'ay' => 0.0,
                    'mass' => 1.0, 'drag' => 0.0,
                    'gravityScale' => 1.0,
                    'isKinematic' => true,
                    'restitution' => 0.0,
                    'fixedRotation' => true,
                    'angularVelocity' => 0.0,
                    'angularDrag' => 0.0,
                    'rotation' => 0.0,
                ],
            ],
            'colliders' => [],
        ]);

        $this->assertEqualsWithDelta(5.0, $result['positions'][1][0], 0.001);
        $this->assertEqualsWithDelta(5.0, $result['positions'][1][1], 0.001);
    }

    public function testDragReducesVelocity(): void
    {
        $result = PhysicsSolver::simulate([
            'dt' => 1.0,
            'gravity' => [0.0, 0.0],
            'bodies' => [
                1 => [
                    'x' => 0.0, 'y' => 0.0,
                    'vx' => 100.0, 'vy' => 0.0,
                    'ax' => 0.0, 'ay' => 0.0,
                    'mass' => 1.0, 'drag' => 0.5,
                    'gravityScale' => 1.0,
                    'isKinematic' => false,
                    'restitution' => 0.0,
                    'fixedRotation' => true,
                    'angularVelocity' => 0.0,
                    'angularDrag' => 0.0,
                    'rotation' => 0.0,
                ],
            ],
            'colliders' => [],
        ]);

        // drag=0.5, dt=1: velocity *= (1 - 0.5*1) = 0.5
        $this->assertEqualsWithDelta(50.0, $result['velocities'][1][0], 0.001);
    }

    public function testAABBCollisionDetection(): void
    {
        $result = PhysicsSolver::simulate([
            'dt' => 0.0, // no integration, just collision
            'gravity' => [0.0, 0.0],
            'bodies' => [
                1 => [
                    'x' => 0.0, 'y' => 0.0,
                    'vx' => 0.0, 'vy' => 0.0,
                    'ax' => 0.0, 'ay' => 0.0,
                    'mass' => 1.0, 'drag' => 0.0,
                    'gravityScale' => 0.0,
                    'isKinematic' => false,
                    'restitution' => 0.0,
                    'fixedRotation' => true,
                    'angularVelocity' => 0.0,
                    'angularDrag' => 0.0,
                    'rotation' => 0.0,
                ],
                2 => [
                    'x' => 5.0, 'y' => 0.0,
                    'vx' => 0.0, 'vy' => 0.0,
                    'ax' => 0.0, 'ay' => 0.0,
                    'mass' => 1.0, 'drag' => 0.0,
                    'gravityScale' => 0.0,
                    'isKinematic' => false,
                    'restitution' => 0.0,
                    'fixedRotation' => true,
                    'angularVelocity' => 0.0,
                    'angularDrag' => 0.0,
                    'rotation' => 0.0,
                ],
            ],
            'colliders' => [
                1 => ['ox' => 0.0, 'oy' => 0.0, 'w' => 10.0, 'h' => 10.0, 'isTrigger' => false],
                2 => ['ox' => 0.0, 'oy' => 0.0, 'w' => 10.0, 'h' => 10.0, 'isTrigger' => false],
            ],
        ]);

        $this->assertCount(1, $result['collisions']);
        $this->assertSame(1, $result['collisions'][0]['a']);
        $this->assertSame(2, $result['collisions'][0]['b']);
    }

    public function testNoCollisionWhenSeparated(): void
    {
        $result = PhysicsSolver::simulate([
            'dt' => 0.0,
            'gravity' => [0.0, 0.0],
            'bodies' => [
                1 => [
                    'x' => 0.0, 'y' => 0.0,
                    'vx' => 0.0, 'vy' => 0.0,
                    'ax' => 0.0, 'ay' => 0.0,
                    'mass' => 1.0, 'drag' => 0.0,
                    'gravityScale' => 0.0,
                    'isKinematic' => false,
                    'restitution' => 0.0,
                    'fixedRotation' => true,
                    'angularVelocity' => 0.0,
                    'angularDrag' => 0.0,
                    'rotation' => 0.0,
                ],
                2 => [
                    'x' => 100.0, 'y' => 0.0,
                    'vx' => 0.0, 'vy' => 0.0,
                    'ax' => 0.0, 'ay' => 0.0,
                    'mass' => 1.0, 'drag' => 0.0,
                    'gravityScale' => 0.0,
                    'isKinematic' => false,
                    'restitution' => 0.0,
                    'fixedRotation' => true,
                    'angularVelocity' => 0.0,
                    'angularDrag' => 0.0,
                    'rotation' => 0.0,
                ],
            ],
            'colliders' => [
                1 => ['ox' => 0.0, 'oy' => 0.0, 'w' => 10.0, 'h' => 10.0, 'isTrigger' => false],
                2 => ['ox' => 0.0, 'oy' => 0.0, 'w' => 10.0, 'h' => 10.0, 'isTrigger' => false],
            ],
        ]);

        $this->assertCount(0, $result['collisions']);
    }

    public function testAngularVelocityUpdatesRotation(): void
    {
        $result = PhysicsSolver::simulate([
            'dt' => 1.0,
            'gravity' => [0.0, 0.0],
            'bodies' => [
                1 => [
                    'x' => 0.0, 'y' => 0.0,
                    'vx' => 0.0, 'vy' => 0.0,
                    'ax' => 0.0, 'ay' => 0.0,
                    'mass' => 1.0, 'drag' => 0.0,
                    'gravityScale' => 0.0,
                    'isKinematic' => false,
                    'restitution' => 0.0,
                    'fixedRotation' => false,
                    'angularVelocity' => 2.0,
                    'angularDrag' => 0.0,
                    'rotation' => 0.0,
                ],
            ],
            'colliders' => [],
        ]);

        $this->assertEqualsWithDelta(2.0, $result['rotations'][1], 0.001);
    }

    public function testEmptySimulationReturnsEmptyResults(): void
    {
        $result = PhysicsSolver::simulate([
            'dt' => 0.016,
            'gravity' => [0.0, 9.81],
            'bodies' => [],
            'colliders' => [],
        ]);

        $this->assertEmpty($result['positions']);
        $this->assertEmpty($result['velocities']);
        $this->assertEmpty($result['collisions']);
    }
}
