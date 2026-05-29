<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Patrol;
use PHPolygon\Component\PatrolAxis;
use PHPolygon\Component\PlatformerController;
use PHPolygon\Component\PlatformerGameState;
use PHPolygon\Component\SpinBob;
use PHPolygon\Component\Stompable;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\System\StompSystem;

class StompSystemTest extends TestCase
{
    /**
     * @return array{0: World, 1: PlatformerController, 2: Transform3D, 3: int, 4: int, 5: PlatformerGameState}
     */
    private function scene(int $squashFrames = 2): array
    {
        $world = new World();
        $world->addSystem(new StompSystem());

        $gs = new PlatformerGameState(lives: 3, deathPenalty: 50);
        $world->createEntity()->attach($gs);

        $player = $world->createEntity();
        $pc = new PlatformerController(halfExtents: new Vec3(0.45, 0.85, 0.45));
        $ptf = new Transform3D(new Vec3(0.0, 2.5, -4.0));
        $player->attach($ptf)->attach($pc);

        $enemy = $world->createEntity();
        $enemyTf = new Transform3D(new Vec3(0.0, 1.0, -4.0));
        $enemy->attach($enemyTf)
            ->attach(new Stompable(contactRadius: 1.0, bodyHeight: 1.2, stompHeight: 0.6, bounceVelocity: 0.38, score: 200, squashFrames: $squashFrames))
            ->attach(new Patrol(axis: PatrolAxis::X, min: -3.0, max: 3.0, speed: 0.035))
            ->attach(new SpinBob(bobAmplitude: 0.06, bobFrequency: 0.0349, bobAbsolute: true));

        $childMesh = $world->createEntity();
        $childTf = new Transform3D(new Vec3(0.0, 0.6, 0.0));
        $childMesh->attach($childTf)->attach(new MeshRenderer(meshId: 'body', materialId: 'm'));
        $enemyTf->addChild($childTf, $childMesh->id, $enemy->id);

        return [$world, $pc, $ptf, $enemy->id, $childMesh->id, $gs];
    }

    public function testStompSquashesBouncesScoresAndFreezes(): void
    {
        [$world, $pc, , $enemyId, , $gs] = $this->scene();
        $pc->velocity = new Vec3(0.0, -0.3, 0.0); // descending onto the enemy's head

        $world->update(1.0 / 60.0);

        $st = $world->getComponent($enemyId, Stompable::class);
        $this->assertFalse($st->alive, 'enemy defeated');
        $this->assertEqualsWithDelta(0.38, $pc->velocity->y, 1e-9, 'player bounces');
        $this->assertSame(200, $gs->score, 'score awarded');
        $this->assertEqualsWithDelta(0.3, $world->getComponent($enemyId, Transform3D::class)->scale->y, 1e-9, 'squashed flat');
        $this->assertFalse($world->hasComponent($enemyId, Patrol::class), 'corpse stops patrolling');
        $this->assertFalse($world->hasComponent($enemyId, SpinBob::class), 'corpse stops bobbing');
    }

    public function testStompedEnemyDespawnsWithItsChildrenAfterTheSquashTimer(): void
    {
        [$world, $pc, , $enemyId, $childId] = $this->scene(squashFrames: 2);
        $pc->velocity = new Vec3(0.0, -0.3, 0.0);

        $world->update(1.0 / 60.0); // stomp (timer = 2)
        $this->assertTrue($world->isAlive($enemyId), 'lingers while squashed');

        for ($i = 0; $i < 4; $i++) {
            $world->update(1.0 / 60.0); // 2 -> 1 -> 0 -> despawn
        }

        $this->assertFalse($world->isAlive($enemyId), 'enemy despawned after squash');
        $this->assertFalse($world->isAlive($childId), 'child meshes despawn too (cascade)');
    }

    public function testSideContactDamagesInsteadOfStomping(): void
    {
        [$world, $pc, $ptf, $enemyId, , $gs] = $this->scene();
        // Same height as the enemy, not descending: a side hit, not a stomp.
        $ptf->position = new Vec3(0.0, 1.4, -4.0);
        $pc->velocity = new Vec3(0.1, 0.0, 0.0);

        $world->update(1.0 / 60.0);

        $this->assertTrue($world->getComponent($enemyId, Stompable::class)->alive, 'enemy survives a side hit');
        $this->assertSame(2, $gs->lives, 'player loses a life');
    }
}
