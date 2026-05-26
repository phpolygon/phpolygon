<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\SpotLight;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\AddSpotLight;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\Renderer3DSystem;

class Renderer3DSystemSpotLightTest extends TestCase
{
    public function testSpotLightComponentEmitsAddSpotLight(): void
    {
        $world = new World();
        $world->createEntity()
            ->attach(new Transform3D(position: new Vec3(2.0, 5.0, -3.0)))
            ->attach(new SpotLight(
                direction: new Vec3(0.0, -1.0, 0.0),
                color: new Color(0.2, 0.4, 0.6),
                intensity: 2.5,
                range: 12.0,
                angle: 0.6,
                penumbra: 0.25,
            ));

        // render() flushes to the renderer then clears the list, so capture the
        // commands at flush time via a spy renderer.
        $spy = new class extends NullRenderer3D {
            /** @var list<AddSpotLight> */
            public array $spots = [];

            public function render(RenderCommandList $commands): void
            {
                $this->spots = $commands->ofType(AddSpotLight::class);
            }
        };

        $system = new Renderer3DSystem($spy, new RenderCommandList());
        $system->render($world);

        $this->assertCount(1, $spy->spots);
        $cmd = $spy->spots[0];

        // Position comes from the entity's Transform3D world position.
        $this->assertEqualsWithDelta(2.0, $cmd->position->x, 1e-9);
        $this->assertEqualsWithDelta(5.0, $cmd->position->y, 1e-9);
        $this->assertEqualsWithDelta(-3.0, $cmd->position->z, 1e-9);

        // Direction comes from the SpotLight component itself.
        $this->assertEqualsWithDelta(0.0, $cmd->direction->x, 1e-9);
        $this->assertEqualsWithDelta(-1.0, $cmd->direction->y, 1e-9);
        $this->assertEqualsWithDelta(0.0, $cmd->direction->z, 1e-9);

        $this->assertEqualsWithDelta(0.2, $cmd->color->r, 1e-9);
        $this->assertEqualsWithDelta(0.4, $cmd->color->g, 1e-9);
        $this->assertEqualsWithDelta(0.6, $cmd->color->b, 1e-9);

        $this->assertEqualsWithDelta(2.5, $cmd->intensity, 1e-9);
        $this->assertEqualsWithDelta(12.0, $cmd->range, 1e-9);
        $this->assertEqualsWithDelta(0.6, $cmd->angle, 1e-9);
        $this->assertEqualsWithDelta(0.25, $cmd->penumbra, 1e-9);
    }

    public function testNearZeroIntensitySpotLightIsSkipped(): void
    {
        $world = new World();
        $world->createEntity()
            ->attach(new Transform3D(position: new Vec3(0.0, 0.0, 0.0)))
            ->attach(new SpotLight(intensity: 0.0));

        $spy = new class extends NullRenderer3D {
            /** @var list<AddSpotLight> */
            public array $spots = [];

            public function render(RenderCommandList $commands): void
            {
                $this->spots = $commands->ofType(AddSpotLight::class);
            }
        };

        $system = new Renderer3DSystem($spy, new RenderCommandList());
        $system->render($world);

        $this->assertCount(0, $spy->spots);
    }
}
