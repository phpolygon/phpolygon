<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\AmbientLight;
use PHPolygon\ECS\World;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\Renderer3DSystem;

class Renderer3DSystemAmbientLightTest extends TestCase
{
    public function testAmbientLightComponentEmitsSetAmbientLight(): void
    {
        $world = new World();
        $world->createEntity()->attach(new AmbientLight(new Color(0.2, 0.4, 0.6), 0.75));

        // render() flushes to the renderer then clears the list, so capture the
        // commands at flush time via a spy renderer.
        $spy = new class extends NullRenderer3D {
            /** @var list<SetAmbientLight> */
            public array $ambient = [];

            public function render(RenderCommandList $commands): void
            {
                $this->ambient = $commands->ofType(SetAmbientLight::class);
            }
        };

        $system = new Renderer3DSystem($spy, new RenderCommandList());
        $system->render($world);

        $this->assertCount(1, $spy->ambient);
        $cmd = $spy->ambient[0];
        $this->assertEqualsWithDelta(0.2, $cmd->color->r, 1e-9);
        $this->assertEqualsWithDelta(0.4, $cmd->color->g, 1e-9);
        $this->assertEqualsWithDelta(0.6, $cmd->color->b, 1e-9);
        $this->assertEqualsWithDelta(0.75, $cmd->intensity, 1e-9);
    }
}
