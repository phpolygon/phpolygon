<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\PlatformerController;
use PHPolygon\Component\PlatformerGameState;
use PHPolygon\Component\PlatformerStatus;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Runtime\InputInterface;
use PHPolygon\System\PlatformerControllerSystem;

/**
 * Regression coverage for the respawn anti-spiral: a fall must respawn the
 * player a safe margin inside the supporting platform, never on the lethal lip.
 */
class PlatformerControllerSystemTest extends TestCase
{
    private function world(): array
    {
        $world = new World();
        $input = $this->createMock(InputInterface::class); // all keys up by default
        $world->addSystem(new PlatformerControllerSystem($input));

        // collider_platform_0 from the imported scene: top surface at y=1.0,
        // spanning x[-5,5], z[-11,3].
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.3, -4.0)))
            ->attach(new BoxCollider3D(size: new Vec3(10.0, 1.4, 14.0)));

        $player = $world->createEntity();
        $pc = new PlatformerController(halfExtents: new Vec3(0.45, 0.85, 0.45), maxSpeed: 0.22);
        $player->attach(new Transform3D(new Vec3(0.0, 1.85, -10.9))); // at the -Z lip
        $player->attach($pc);

        return [$world, $pc];
    }

    public function testRespawnPointIsPulledInFromThePlatformEdge(): void
    {
        [$world, $pc] = $this->world();

        $world->update(1.0 / 60.0);

        $this->assertTrue($pc->onGround, 'player should rest on the platform');
        // Edge is z=-11; with half-extent 0.45 + margin 0.75 the safe spot is
        // clamped to z=-9.8, well clear of the lip (was the spawn at z=-10.9).
        $this->assertEqualsWithDelta(-9.8, $pc->lastSafe->z, 0.05);
        $this->assertGreaterThan(-10.9, $pc->lastSafe->z, 'respawn must move inward, not stay at the brink');
    }

    public function testApplyDamageSetsInvulnEvenOnTheLethalHit(): void
    {
        // Critical: prevents same-frame re-entry from StompSystem driving lives
        // below zero. Before this guard, applyDamage returned without setting
        // invuln on the lives==0 branch, so a second enemy contact in the same
        // frame called applyDamage again with invuln still 0 → lives → -1, -2 …
        $pc = new PlatformerController(invulnFrames: 90);
        $tf = new Transform3D(new Vec3(0.0, 0.0, 0.0));
        $state = new PlatformerGameState(lives: 1, deathPenalty: 50);

        PlatformerControllerSystem::applyDamage($pc, $tf, $state);

        $this->assertSame(0, $state->lives);
        $this->assertSame(PlatformerStatus::Lost, $state->status);
        $this->assertSame(90, $pc->invuln, 'invuln must be set even on the lethal branch');
    }

    public function testRespawnPointKeepsInteriorPositionsUnchanged(): void
    {
        $world = new World();
        $input = $this->createMock(InputInterface::class);
        $world->addSystem(new PlatformerControllerSystem($input));

        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.3, -4.0)))
            ->attach(new BoxCollider3D(size: new Vec3(10.0, 1.4, 14.0)));

        $player = $world->createEntity();
        $pc = new PlatformerController(halfExtents: new Vec3(0.45, 0.85, 0.45), maxSpeed: 0.22);
        $player->attach(new Transform3D(new Vec3(0.0, 1.85, -4.0))); // platform centre
        $player->attach($pc);

        $world->update(1.0 / 60.0);

        $this->assertTrue($pc->onGround);
        // Comfortably inside the platform → not clamped.
        $this->assertEqualsWithDelta(0.0, $pc->lastSafe->x, 1e-3);
        $this->assertEqualsWithDelta(-4.0, $pc->lastSafe->z, 1e-3);
    }
}
