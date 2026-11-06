<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPolygon\Component\Transform3D;
use PHPolygon\Component\WaterDisturber;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\SetShaderParameter;
use PHPolygon\Rendering\Effects\EffectDefinition;
use PHPolygon\Rendering\Effects\EffectFallback;
use PHPolygon\Rendering\Effects\EffectRegistry;
use PHPolygon\Rendering\Effects\EffectTier;
use PHPolygon\Rendering\Effects\FlashClass;
use PHPolygon\Rendering\Effects\ReducedMotion;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\Water\WaterShading;
use PHPolygon\System\WaterDisturbanceSystem;
use PHPUnit\Framework\TestCase;

final class WaterDisturbanceSystemTest extends TestCase
{
    private const float DT = 1.0 / 60.0;

    protected function tearDown(): void
    {
        EffectRegistry::clear();
    }

    /** @return array{World, WaterDisturbanceSystem, RenderCommandList, Transform3D, WaterDisturber} */
    private function scene(string $kind, ?string $wakeEffect = null, ?string $rippleEffect = null): array
    {
        $world = new World();
        $list = new RenderCommandList();
        $system = new WaterDisturbanceSystem($list, wakeLife: 10.0, rippleLife: 2.0, wakeEffect: $wakeEffect, rippleEffect: $rippleEffect);
        $world->addSystem($system);
        $entity = $world->createEntity();
        $transform = new Transform3D();
        $disturber = new WaterDisturber($kind, spacing: 4.0, interval: 0.5);
        $world->attachComponent($entity->id, $transform);
        $world->attachComponent($entity->id, $disturber);

        return [$world, $system, $list, $transform, $disturber];
    }

    private static function move(World $world, Transform3D $t, float $x, float $z): void
    {
        $t->position = new Vec3($x, 0.0, $z);
        $t->worldMatrix = $t->getLocalMatrix();
        $world->update(self::DT);
    }

    public function testAMovingHullLeavesATrailNewestFirst(): void
    {
        [$world, $system, , $t] = $this->scene(WaterDisturber::WAKE);
        // 6 m/s along -z for 3 s.
        for ($i = 0; $i <= 180; $i++) {
            self::move($world, $t, 0.0, -$i * 6.0 * self::DT);
        }

        $points = $system->wakePoints();
        self::assertGreaterThanOrEqual(5, count($points));
        // Head at the hull, a new trail starts there (negative strength).
        self::assertEqualsWithDelta(-18.0, $points[0][1], 1e-6);
        self::assertLessThan(0.0, $points[0][3]);
        self::assertEqualsWithDelta(0.0, $points[0][2], 1e-9);
        // Older points lie behind and are older, about `spacing` apart.
        for ($i = 2; $i < count($points); $i++) {
            self::assertGreaterThan($points[$i - 1][1], $points[$i][1]);
            self::assertGreaterThan($points[$i - 1][2], $points[$i][2]);
            self::assertEqualsWithDelta(4.0, $points[$i][1] - $points[$i - 1][1], 0.2);
        }
        // Full speed: full strength.
        self::assertEqualsWithDelta(1.0, $points[1][3], 0.05);
    }

    public function testTheTrailFadesAfterItsLife(): void
    {
        [$world, $system, , $t] = $this->scene(WaterDisturber::WAKE);
        for ($i = 0; $i <= 60; $i++) {
            self::move($world, $t, 0.0, -$i * 0.1);
        }
        self::assertNotSame([], $system->wakePoints());
        for ($i = 0; $i < 11 * 60; $i++) {
            $world->update(self::DT);
        }

        // Standing still: only the head and its twin at the hull remain, at strength ~0.
        foreach ($system->wakePoints() as $p) {
            self::assertEqualsWithDelta(-6.0, $p[1], 1e-6);
            self::assertLessThan(0.01, abs($p[3]));
        }
    }

    public function testAJumpStartsANewTrail(): void
    {
        [$world, $system, , $t] = $this->scene(WaterDisturber::WAKE);
        for ($i = 0; $i <= 60; $i++) {
            self::move($world, $t, 0.0, -$i * 0.1);
        }
        self::move($world, $t, 500.0, 0.0);

        foreach ($system->wakePoints() as $p) {
            self::assertSame(500.0, $p[0]);
        }
    }

    public function testASwimmerSendsRingsWhileActive(): void
    {
        [$world, $system, , $t, $d] = $this->scene(WaterDisturber::RIPPLE);
        for ($i = 0; $i < 60; $i++) {
            self::move($world, $t, 3.0, 4.0);
        }
        // One ring at once, then one per 0.5 s.
        self::assertCount(2, $system->ripples());
        self::assertSame(3.0, $system->ripples()[0][0]);

        $d->active = false;
        for ($i = 0; $i < 150; $i++) {
            $world->update(self::DT);
        }
        self::assertSame([], $system->ripples(), 'rings older than their life are gone');
    }

    public function testUniformsArePackedOnePointPerColumn(): void
    {
        [$world, , $list, $t] = $this->scene(WaterDisturber::WAKE);
        for ($i = 0; $i <= 120; $i++) {
            self::move($world, $t, $i * 0.1, 0.0);
        }
        $world->render();

        $sent = [];
        foreach ($list->ofType(SetShaderParameter::class) as $cmd) {
            $sent[$cmd->name] = $cmd->value;
        }
        self::assertArrayHasKey(WaterShading::wakeUniform(3), $sent);
        self::assertArrayHasKey(WaterShading::rippleUniform(1), $sent);
        $first = $sent[WaterShading::wakeUniform(0)];
        self::assertCount(16, $first);
        // Column 0 = the head: x, z, age, -strength.
        self::assertEqualsWithDelta(12.0, $first[0], 1e-6);
        self::assertSame(0.0, $first[1]);
        self::assertSame(0.0, $first[2]);
        self::assertLessThan(0.0, $first[3]);
    }

    public function testNothingOnTheWaterIsSentOnce(): void
    {
        [$world, , $list] = $this->scene(WaterDisturber::RIPPLE);
        foreach ($world->query(WaterDisturber::class) as $e) {
            $e->get(WaterDisturber::class)->active = false;
        }
        $world->update(self::DT);
        $world->render();
        $first = count($list->ofType(SetShaderParameter::class));
        $world->update(self::DT);
        $world->render();

        self::assertSame(6, $first);
        self::assertSame($first, count($list->ofType(SetShaderParameter::class)));
    }

    public function testReducedMotionSlowsOrClearsAsRegistered(): void
    {
        EffectRegistry::register(new EffectDefinition('ripples', EffectTier::Low, null, EffectFallback::Off, EffectFallback::Off, ReducedMotion::Off, FlashClass::None));
        EffectRegistry::setReducedMotion(true);
        [$world, $system, , $t] = $this->scene(WaterDisturber::RIPPLE, rippleEffect: 'ripples');
        for ($i = 0; $i < 30; $i++) {
            self::move($world, $t, 0.0, 0.0);
        }

        self::assertSame([], $system->ripples());
    }
}
