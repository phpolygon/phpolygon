<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\PlatformerController;
use PHPolygon\Component\PlatformerGameState;
use PHPolygon\Component\PlatformerLegSegment;
use PHPolygon\Component\PlatformerStatus;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\System\PlatformerAnimationSystem;

class PlatformerAnimationSystemTest extends TestCase
{
    private World $world;
    private PlatformerController $pc;
    private Transform3D $legL;
    private Transform3D $legR;
    private MeshRenderer $legLMesh;

    protected function setUp(): void
    {
        $this->world = new World();
        $this->world->addSystem(new PlatformerAnimationSystem());

        $player = $this->world->createEntity();
        $playerTf = new Transform3D(new Vec3(0.0, 1.85, 0.0));
        $this->pc = new PlatformerController(halfExtents: new Vec3(0.45, 0.85, 0.45), maxSpeed: 0.22);
        $player->attach($playerTf)->attach($this->pc);

        [$this->legL, $this->legLMesh] = $this->leg($player->id, $playerTf, -0.2, 1.0);
        [$this->legR] = $this->leg($player->id, $playerTf, 0.2, -1.0);
    }

    /** @return array{0: Transform3D, 1: MeshRenderer} */
    private function leg(int $playerId, Transform3D $playerTf, float $x, float $sign): array
    {
        $e = $this->world->createEntity();
        $tf = new Transform3D(new Vec3($x, -0.525, 0.0));
        $mesh = new MeshRenderer(meshId: 'leg', materialId: 'm');
        $e->attach($tf)->attach($mesh)->attach(new PlatformerLegSegment(
            restPosition: new Vec3($x, -0.525, 0.0),
            restRotation: Quaternion::identity(),
            pivot: new Vec3($x, -0.25, 0.0),
            swingSign: $sign,
        ));
        $playerTf->addChild($tf, $e->id, $playerId);
        return [$tf, $mesh];
    }

    private static function pitch(Quaternion $q): float
    {
        return 2.0 * atan2($q->x, $q->w); // rotation is pure-X
    }

    public function testRunningSwingsLegsInOppositePhaseAtFullAmplitude(): void
    {
        $this->pc->onGround = true;
        $this->pc->velocity = new Vec3(0.22, 0.0, 0.0); // full speed
        $this->pc->animPhase = M_PI / 2.0;              // sin = 1 -> swing = 0.7

        $this->world->update(1.0 / 60.0);

        $this->assertEqualsWithDelta(0.7, self::pitch($this->legL->rotation), 1e-4);
        $this->assertEqualsWithDelta(-0.7, self::pitch($this->legR->rotation), 1e-4);
    }

    public function testIdleDoesNotSwing(): void
    {
        $this->pc->onGround = true;
        $this->pc->velocity = new Vec3(0.0, 0.0, 0.0);
        $this->pc->animPhase = 1.0;

        $this->world->update(1.0 / 60.0);

        $this->assertEqualsWithDelta(0.0, self::pitch($this->legL->rotation), 1e-6);
        $this->assertEqualsWithDelta(0.0, self::pitch($this->legR->rotation), 1e-6);
    }

    public function testAirborneHoldsAFixedJumpPose(): void
    {
        $this->pc->onGround = false;
        $this->pc->velocity = new Vec3(0.1, 0.4, 0.0);

        $this->world->update(1.0 / 60.0);

        $this->assertEqualsWithDelta(-0.5, self::pitch($this->legL->rotation), 1e-4);
        $this->assertEqualsWithDelta(0.5, self::pitch($this->legR->rotation), 1e-4);
    }

    public function testInvulnerabilityBlinksAndRestores(): void
    {
        $this->pc->onGround = true;

        $this->pc->invuln = 7; // intdiv(7,5)=1 -> odd -> hidden
        $this->world->update(1.0 / 60.0);
        $this->assertFalse($this->legLMesh->visible, 'player blinks off mid-invuln');

        $this->pc->invuln = 0; // invulnerability over
        $this->world->update(1.0 / 60.0);
        $this->assertTrue($this->legLMesh->visible, 'visibility restored when invuln ends');
    }

    public function testGameOverForcesVisibilityRegardlessOfInvuln(): void
    {
        // On Lost / Won the PlatformerControllerSystem freezes; invuln stops
        // decrementing and could otherwise be stuck in an odd blink bucket,
        // leaving the player rig PERMANENTLY INVISIBLE on the GAME OVER
        // screen. The animation system must override the blink in that case.
        $gs = new PlatformerGameState(lives: 3);
        $gs->status = PlatformerStatus::Lost;
        $this->world->createEntity()->attach($gs);

        $this->pc->onGround = true;
        $this->pc->invuln = 7; // would normally be hidden (intdiv(7,5)%2 === 1)

        $this->world->update(1.0 / 60.0);

        $this->assertTrue($this->legLMesh->visible, 'player must stay visible on the GAME OVER screen');
    }
}
