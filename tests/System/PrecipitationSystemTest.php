<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\Component\Weather;
use PHPolygon\Component\Wind;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\System\PrecipitationSystem;

class PrecipitationSystemTest extends TestCase
{
    private function spawnPlayer(World $world, Vec3 $pos): int
    {
        $e = $world->createEntity();
        $world->attachComponent($e->id, new Transform3D(position: $pos));
        $world->attachComponent($e->id, new CharacterController3D());
        return $e->id;
    }

    private function spawnParticle(World $world, Vec3 $pos): int
    {
        $e = $world->createEntity();
        $world->attachComponent($e->id, new Transform3D(position: $pos));
        $world->attachComponent($e->id, new MeshRenderer(meshId: 'rain_drop', materialId: 'precipitation'));
        return $e->id;
    }

    public function testRainRepositionsParticlesNearPlayer(): void
    {
        $world = new World();
        $world->addSystem(new PrecipitationSystem());

        $weather = new Weather();
        $weather->rainIntensity = 0.8;
        $we = $world->createEntity();
        $world->attachComponent($we->id, $weather);

        $playerPos = new Vec3(100.0, 5.0, 200.0);
        $this->spawnPlayer($world, $playerPos);

        // Particles start hidden far below
        $p1 = $this->spawnParticle($world, new Vec3(0, -100, 0));
        $p2 = $this->spawnParticle($world, new Vec3(0, -100, 0));

        $world->update(0.1);

        $t1 = $world->getComponent($p1, Transform3D::class)->position;
        $t2 = $world->getComponent($p2, Transform3D::class)->position;

        // Both lifted out of the hidden -100 well, clustered around the player
        $this->assertGreaterThan(-50.0, $t1->position->y ?? $t1->y, 'Particle lifted from hidden well');
        $this->assertLessThan(40.0, abs($t1->x - $playerPos->x), 'Particle near player X');
        $this->assertLessThan(40.0, abs($t1->z - $playerPos->z), 'Particle near player Z');

        // Two particles with different indices get different positions
        $this->assertNotEquals(
            [$t1->x, $t1->z],
            [$t2->x, $t2->z],
            'Distinct particles should not overlap exactly',
        );
    }

    public function testWindDriftAffectsRainHorizontalPosition(): void
    {
        $build = function (float $windIntensity): float {
            $world = new World();
            $world->addSystem(new PrecipitationSystem());

            $weather = new Weather();
            $weather->rainIntensity = 0.8;
            $we = $world->createEntity();
            $world->attachComponent($we->id, $weather);

            $this->spawnPlayer($world, new Vec3(0.0, 0.0, 0.0));

            $wind = new Wind();
            $wind->intensity = $windIntensity;
            $wind->direction = new Vec3(1.0, 0.0, 0.0);
            $wn = $world->createEntity();
            $world->attachComponent($wn->id, $wind);

            $p = $this->spawnParticle($world, new Vec3(0, -100, 0));

            // Accumulate enough time for the wind drift term to matter
            for ($i = 0; $i < 20; $i++) {
                $world->update(0.5);
            }

            return $world->getComponent($p, Transform3D::class)->position->x;
        };

        $noWindX = $build(0.0);
        $strongWindX = $build(2.0);

        $this->assertNotEqualsWithDelta($noWindX, $strongWindX, 1e-3, 'Strong wind drifts rain in X');
    }

    public function testSnowAppliesFlatFlakeScale(): void
    {
        $world = new World();
        $world->addSystem(new PrecipitationSystem());

        $weather = new Weather();
        $weather->snowIntensity = 0.9;
        $we = $world->createEntity();
        $world->attachComponent($we->id, $weather);

        $this->spawnPlayer($world, new Vec3(0.0, 0.0, 0.0));
        $p = $this->spawnParticle($world, new Vec3(0, -100, 0));

        $world->update(0.1);

        $scale = $world->getComponent($p, Transform3D::class)->scale;
        // Snow flakes are flat: very thin in Y, wider in XZ
        $this->assertLessThan(0.01, $scale->y, 'Snow flake is thin in Y');
        $this->assertGreaterThan($scale->y, $scale->x, 'Snow flake is wider in X than Y');
    }

    public function testInactiveWeatherLeavesParticlesUntouchedInitially(): void
    {
        $world = new World();
        $world->addSystem(new PrecipitationSystem());

        // Clear weather — nothing active
        $we = $world->createEntity();
        $world->attachComponent($we->id, new Weather());

        $this->spawnPlayer($world, new Vec3(0.0, 0.0, 0.0));
        $startPos = new Vec3(7.0, -100.0, 9.0);
        $p = $this->spawnParticle($world, $startPos);

        $world->update(0.1);

        // wasActive was never true → the system returns before touching particles
        $pos = $world->getComponent($p, Transform3D::class)->position;
        $this->assertEqualsWithDelta(7.0, $pos->x, 1e-9);
        $this->assertEqualsWithDelta(-100.0, $pos->y, 1e-9);
        $this->assertEqualsWithDelta(9.0, $pos->z, 1e-9);
    }

    public function testParticlesReHiddenOnActiveToInactiveTransition(): void
    {
        $world = new World();
        $world->addSystem(new PrecipitationSystem());

        $weather = new Weather();
        $weather->rainIntensity = 0.8; // active
        $we = $world->createEntity();
        $world->attachComponent($we->id, $weather);

        $this->spawnPlayer($world, new Vec3(0.0, 0.0, 0.0));
        $p = $this->spawnParticle($world, new Vec3(0, -100, 0));

        // Active frame lifts the particle up
        $world->update(0.1);
        $this->assertGreaterThan(-50.0, $world->getComponent($p, Transform3D::class)->position->y, 'Active rain lifts particle');

        // Now weather clears → next update must re-hide the particle exactly once
        $weather->rainIntensity = 0.0;
        $world->update(0.1);

        $pos = $world->getComponent($p, Transform3D::class)->position;
        $this->assertEqualsWithDelta(-100.0, $pos->y, 1e-9, 'Particle re-hidden at y=-100 on transition');
    }

    public function testNoPlayerIsNoOp(): void
    {
        $world = new World();
        $world->addSystem(new PrecipitationSystem());

        $weather = new Weather();
        $weather->rainIntensity = 0.8;
        $we = $world->createEntity();
        $world->attachComponent($we->id, $weather);

        // No player at all
        $p = $this->spawnParticle($world, new Vec3(1, -100, 2));

        $world->update(0.1); // returns early — must not throw or move particle

        $pos = $world->getComponent($p, Transform3D::class)->position;
        $this->assertEqualsWithDelta(-100.0, $pos->y, 1e-9, 'Particle untouched without a player');
    }
}
