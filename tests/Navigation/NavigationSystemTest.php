<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Navigation;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\NavMeshAgent;
use PHPolygon\Component\NavMeshSurface;
use PHPolygon\Component\Transform3D;
use PHPolygon\Component\BoxCollider3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Navigation\AStarPathfinder;
use PHPolygon\Navigation\NavMesh;
use PHPolygon\Navigation\NavMeshEdge;
use PHPolygon\Navigation\NavMeshPolygon;
use PHPolygon\System\NavigationSystem;

class NavigationSystemTest extends TestCase
{
    public function testSystemInitializesWithDefaultPathfinder(): void
    {
        $system = new NavigationSystem();
        $this->assertNull($system->getNavMesh());
        $this->assertNull($system->getQuery());
    }

    public function testAgentMovesTowardDestination(): void
    {
        // Build a simple pre-made NavMesh (bypass generation)
        $navMesh = new NavMesh();
        $navMesh->addPolygon(new NavMeshPolygon(0, [
            new Vec3(-10, 0, -10),
            new Vec3(10, 0, -10),
            new Vec3(10, 0, 10),
        ], [1], [5.0]));
        $navMesh->addPolygon(new NavMeshPolygon(1, [
            new Vec3(-10, 0, -10),
            new Vec3(10, 0, 10),
            new Vec3(-10, 0, 10),
        ], [0], [5.0]));
        $navMesh->addEdge(new NavMeshEdge(
            new Vec3(-10, 0, -10), new Vec3(10, 0, 10), 0, 1,
        ));

        $system = new NavigationSystem();
        $system->setNavMesh($navMesh);

        $world = new World();

        $agent = $world->createEntity();
        $agent->attach(new Transform3D(position: new Vec3(0, 0, 0)));
        $agentComp = new NavMeshAgent(speed: 5.0, acceleration: 100.0);
        $agent->attach($agentComp);
        $agentComp->setDestination(new Vec3(5, 0, 5));

        $world->addSystem($system);

        // Simulate several ticks (2 seconds at 60fps)
        for ($i = 0; $i < 120; $i++) {
            $world->update(0.016);
        }

        $transform = $agent->get(Transform3D::class);
        $distToTarget = sqrt($transform->position->distanceSquaredTo(new Vec3(5, 0, 5)));

        // Agent should have reached near the target (within stopping distance)
        $this->assertLessThan(1.5, $distToTarget);
    }

    public function testAgentStopsWhenStopped(): void
    {
        $system = new NavigationSystem();
        $world = new World();

        $entity = $world->createEntity();
        $entity->attach(new Transform3D(position: new Vec3(0, 0, 0)));
        $agentComp = new NavMeshAgent();
        $entity->attach($agentComp);

        $agentComp->setDestination(new Vec3(10, 0, 10));
        $agentComp->stop();

        $world->addSystem($system);
        $world->update(0.016);

        // Agent shouldn't have moved
        $transform = $entity->get(Transform3D::class);
        $this->assertEqualsWithDelta(0.0, $transform->position->x, 1e-6);
        $this->assertEqualsWithDelta(0.0, $transform->position->z, 1e-6);
    }

    public function testSetNavMeshMakesQueryAvailable(): void
    {
        $system = new NavigationSystem();
        $this->assertNull($system->getQuery());

        $navMesh = new NavMesh();
        $navMesh->addPolygon(new NavMeshPolygon(0, [
            new Vec3(0, 0, 0),
            new Vec3(10, 0, 0),
            new Vec3(0, 0, 10),
        ]));

        $system->setNavMesh($navMesh);

        $this->assertNotNull($system->getQuery());
        $this->assertSame($navMesh, $system->getNavMesh());
    }
}
