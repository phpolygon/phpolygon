<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPolygon\Component\Goal;
use PHPolygon\Component\PlatformerController;
use PHPolygon\Component\PlatformerGameState;
use PHPolygon\Component\PlatformerStatus;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\System\GoalSystem;
use PHPolygon\System\Transform3DSystem;
use PHPUnit\Framework\TestCase;

class GoalSystemTest extends TestCase
{
    public function testParentedGoalIsReachedUsingWorldPositions(): void
    {
        // Both sides read getWorldPosition() so a goal parented under a moving
        // anchor is compared in the same space as the player.
        $world = new World();
        $world->addSystem(new Transform3DSystem());
        $world->addSystem(new GoalSystem());

        $state = new PlatformerGameState(lives: 2);
        $world->createEntity()->attach($state);

        $player = $world->createEntity();
        $playerTf = new Transform3D(new Vec3(20.0, 5.0, 0.0));
        $player->attach($playerTf)->attach(new PlatformerController());

        $anchor = $world->createEntity();
        $anchorTf = new Transform3D(new Vec3(20.0, 5.0, 0.0));
        $anchor->attach($anchorTf);

        $goalEntity = $world->createEntity();
        $goalTf = new Transform3D(new Vec3(0.0, 0.0, 0.0));
        $goal = new Goal(score: 1000, lifeBonus: 250, radius: 1.0);
        $goalEntity->attach($goalTf)->attach($goal);
        $anchorTf->addChild($goalTf, $goalEntity->id, $anchor->id);

        $world->update(1.0 / 60.0);

        $this->assertTrue($goal->reached, 'parented goal at the player position must be reached');
        $this->assertSame(PlatformerStatus::Won, $state->status);
        // lifeBonus * lives + score = 250 * 2 + 1000 = 1500.
        $this->assertSame(1500, $state->score);
    }
}
