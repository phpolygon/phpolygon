<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPolygon\Component\Collectible;
use PHPolygon\Component\PlatformerController;
use PHPolygon\Component\PlatformerGameState;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\System\CollectibleSystem;
use PHPolygon\System\Transform3DSystem;
use PHPUnit\Framework\TestCase;

class CollectibleSystemTest extends TestCase
{
    public function testParentedCoinIsPickedUpUsingWorldPositions(): void
    {
        // Both the player and the coin read getWorldPosition() so the AABB
        // overlap is measured in the same space. Earlier the player was
        // measured via local `position` and the coin via `getWorldPosition()`,
        // so a parented coin (e.g. on a moving platform) was offset by the
        // parent's translation and the pickup silently missed.
        $world = new World();
        $world->addSystem(new Transform3DSystem());
        $world->addSystem(new CollectibleSystem());

        $state = new PlatformerGameState(lives: 3);
        $world->createEntity()->attach($state);

        $player = $world->createEntity();
        $playerTf = new Transform3D(new Vec3(10.0, 0.0, 0.0));
        $player->attach($playerTf)->attach(new PlatformerController());

        // Coin parented under a platform at +10 on X. Local position 0 puts
        // the coin in world at +10 — exactly where the player stands.
        $platform = $world->createEntity();
        $platformTf = new Transform3D(new Vec3(10.0, 0.0, 0.0));
        $platform->attach($platformTf);

        $coin = $world->createEntity();
        $coinTf = new Transform3D(new Vec3(0.0, 0.0, 0.0));
        $col = new Collectible(score: 100, coinValue: 1, radius: 0.5, playerYOffset: 0.0);
        $coin->attach($coinTf)->attach($col);
        $platformTf->addChild($coinTf, $coin->id, $platform->id);

        $world->update(1.0 / 60.0);

        $this->assertTrue($col->collected, 'parented coin at the player position must be picked up');
        $this->assertSame(1, $state->coins);
        $this->assertSame(100, $state->score);
    }
}
