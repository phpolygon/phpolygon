<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\LightCone;
use PHPolygon\Component\SpotLight;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawLightCone;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\LightConeSystem;

final class LightConeSystemTest extends TestCase
{
    private function camera(): SetCamera
    {
        return new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 2.0, 10.0), new Vec3(0.0, 2.0, 0.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(1.0, 1.0, 0.1, 200.0),
        );
    }

    /** @return array{0: World, 1: SpotLight, 2: LightCone} */
    private function lamp(Vec3 $at, ?LightCone $cone = null): array
    {
        $world = new World();
        $spot = new SpotLight(new Vec3(0.0, -2.0, 0.0), new Color(1.0, 0.5, 0.25), 4.0, 8.0, 0.5, 0.2);
        $cone ??= new LightCone(density: 0.1, length: 0.75, edgeSoftness: 0.4, axisFade: 0.3);
        $world->createEntity()->attach(new Transform3D(position: $at))->attach($spot)->attach($cone);
        return [$world, $spot, $cone];
    }

    public function testTheBeamFollowsItsSpotLight(): void
    {
        [$world, $spot] = $this->lamp(new Vec3(1.0, 4.0, 0.0));
        $cones = LightConeSystem::cones($world, $this->camera());
        self::assertCount(1, $cones);
        $c = $cones[0];
        self::assertTrue($c->apex->equals(new Vec3(1.0, 4.0, 0.0)));
        self::assertTrue($c->direction->equals(new Vec3(0.0, -1.0, 0.0)), 'unit direction');
        self::assertEqualsWithDelta(0.5, $c->angle, 1e-12);
        self::assertEqualsWithDelta(0.5 * (1.0 - 0.4), $c->innerAngle, 1e-12, 'the softer of penumbra and edge softness');
        self::assertEqualsWithDelta(6.0, $c->length, 1e-12, 'fraction of the range');
        self::assertEqualsWithDelta(8.0, $c->range, 1e-12);
        self::assertSame($spot->color, $c->color);
        self::assertEqualsWithDelta(4.0 * 0.1, $c->intensity, 1e-12, 'intensity x density');
        self::assertEqualsWithDelta(0.3, $c->axisFade, 1e-12);

        // Whoever dims the light dims the beam; colour follows too.
        $spot->intensity = 1.0;
        $spot->color = new Color(0.0, 1.0, 0.0);
        $c = LightConeSystem::cones($world, $this->camera())[0];
        self::assertEqualsWithDelta(0.1, $c->intensity, 1e-12);
        self::assertSame($spot->color, $c->color);
        // A wider penumbra than the cone's own softness wins.
        $spot->penumbra = 0.9;
        self::assertEqualsWithDelta(0.05, LightConeSystem::cones($world, $this->camera())[0]->innerAngle, 1e-12);
    }

    public function testNoBeamWhileTheLightIsOffOrTheConeDisabled(): void
    {
        [$world, $spot, $cone] = $this->lamp(new Vec3(0.0, 4.0, 0.0));
        $spot->intensity = 0.0;
        self::assertSame([], LightConeSystem::cones($world, $this->camera()));
        $spot->intensity = 4.0;
        $cone->enabled = false;
        self::assertSame([], LightConeSystem::cones($world, $this->camera()));
        $cone->enabled = true;
        $cone->density = 0.0;
        self::assertSame([], LightConeSystem::cones($world, $this->camera()));

        // A cone without a spot light draws nothing.
        $world = new World();
        $world->createEntity()->attach(new Transform3D())->attach(new LightCone());
        self::assertSame([], LightConeSystem::cones($world, $this->camera()));
    }

    public function testBeamsOutOfViewOrFarAwayAreSkipped(): void
    {
        // Behind the camera.
        [$world] = $this->lamp(new Vec3(0.0, 4.0, 30.0));
        self::assertSame([], LightConeSystem::cones($world, $this->camera()));

        // Fading out over the last quarter of the distance limit.
        [$world, , $cone] = $this->lamp(new Vec3(0.0, 4.0, -60.0));
        $cone->maxDistance = 1000.0;
        $full = LightConeSystem::cones($world, $this->camera())[0]->intensity;
        $cone->maxDistance = 70.0;
        $faded = LightConeSystem::cones($world, $this->camera())[0]->intensity;
        self::assertGreaterThan(0.0, $faded);
        self::assertLessThan($full, $faded);
        $cone->maxDistance = 40.0;
        self::assertSame([], LightConeSystem::cones($world, $this->camera()));

        self::assertSame(1.0, LightConeSystem::distanceFade(10.0, 100.0));
        self::assertSame(0.5, LightConeSystem::distanceFade(87.5, 100.0));
        self::assertSame(0.0, LightConeSystem::distanceFade(100.0, 100.0));
    }

    public function testTheNearestBeamsAreKept(): void
    {
        $world = new World();
        for ($i = 0; $i < LightConeSystem::MAX_CONES + 4; $i++) {
            $world->createEntity()
                ->attach(new Transform3D(position: new Vec3(0.0, 4.0, -5.0 * $i)))
                ->attach(new SpotLight(intensity: 2.0, range: 4.0))
                ->attach(new LightCone());
        }
        $cones = LightConeSystem::cones($world, $this->camera());
        self::assertCount(LightConeSystem::MAX_CONES, $cones);
        self::assertEqualsWithDelta(0.0, $cones[0]->apex->z, 1e-9, 'nearest first');
        self::assertEqualsWithDelta(-5.0 * (LightConeSystem::MAX_CONES - 1), $cones[LightConeSystem::MAX_CONES - 1]->apex->z, 1e-9);
    }

    public function testRenderAppendsToTheListOfTheFrame(): void
    {
        [$world] = $this->lamp(new Vec3(0.0, 4.0, 0.0));
        $list = new RenderCommandList();
        $system = new LightConeSystem($list);
        $system->render($world);
        self::assertSame([], $list->getCommands(), 'nothing without a camera');

        $list->add($this->camera());
        $system->render($world);
        self::assertCount(1, $list->ofType(DrawLightCone::class));
    }
}
